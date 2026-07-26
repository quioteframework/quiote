<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Exception\StorageException;
use Quiote\Session\FileSessionPersistence;
use Quiote\Session\SessionManager;
use Quiote\Testing\UnitTestCase;

class FileSessionPersistenceTest extends UnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-fsp-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $entries = scandir($this->dir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($this->dir . DIRECTORY_SEPARATOR . $entry);
                    }
                }
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    // -- happy paths ---------------------------------------------------------

    public function testSaveLoadRoundtrip(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $persistence->save('sid-1', ['user_id' => 42, 'name' => 'Ĺörem 実例']);

        $this->assertSame(['user_id' => 42, 'name' => 'Ĺörem 実例'], $persistence->load('sid-1'));
    }

    public function testLoadUnknownSidReturnsNull(): void
    {
        $persistence = new FileSessionPersistence($this->dir);

        $this->assertNull($persistence->load('never-saved'));
    }

    public function testDeleteRemovesSessionAndUnknownDeleteIsANoOp(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $persistence->save('sid-1', ['a' => 1]);

        $persistence->delete('sid-1');
        $this->assertNull($persistence->load('sid-1'));

        $persistence->delete('sid-1'); // second delete must not error
        $this->assertNull($persistence->load('sid-1'));
    }

    public function testConstructorCreatesNestedDirectory(): void
    {
        $nested = $this->dir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
        $persistence = new FileSessionPersistence($nested);
        $persistence->save('sid-1', ['ok' => true]);

        $this->assertTrue(is_dir($nested));
        $this->assertSame(['ok' => true], $persistence->load('sid-1'));

        // cleanup of the extra nesting (tearDown only handles $this->dir itself)
        $entries = glob($nested . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($entries as $file) {
            @unlink($file);
        }
        @rmdir($nested);
        @rmdir(dirname($nested));
    }

    public function testWorksAsSessionManagerBackend(): void
    {
        $manager = new SessionManager(new FileSessionPersistence($this->dir));
        $session = $manager->startFromRequest(new ServerRequest('GET', '/'));
        $session->set('user_id', 7);
        $manager->persistAndBakeCookies($session, new \Nyholm\Psr7\Response());

        $restored = $manager->startFromRequest(
            (new ServerRequest('GET', '/'))->withCookieParams(['QSID' => $session->getId()])
        );
        $this->assertSame(7, $restored->get('user_id'));
    }

    // -- expiry & GC ---------------------------------------------------------

    public function testExpiredSessionLoadsAsNullAndFileIsRemoved(): void
    {
        $persistence = new FileSessionPersistence($this->dir, ['idle_ttl' => 60]);
        $persistence->save('sid-1', ['a' => 1]);
        $this->backdateAllFiles(120);

        $this->assertNull($persistence->load('sid-1'));
        $this->assertSame([], $this->sessionFiles(), 'expired file should be unlinked on load');
    }

    public function testIdleTtlZeroDisablesExpiry(): void
    {
        $persistence = new FileSessionPersistence($this->dir, ['idle_ttl' => 0]);
        $persistence->save('sid-1', ['a' => 1]);
        $this->backdateAllFiles(999999);

        $this->assertSame(['a' => 1], $persistence->load('sid-1'));
        $this->assertSame(0, $persistence->gc());
    }

    public function testGcRemovesOnlyExpiredFilesAndOrphanedTempFiles(): void
    {
        $persistence = new FileSessionPersistence($this->dir, ['idle_ttl' => 60, 'gc_probability' => 0]);
        $persistence->save('old', ['a' => 1]);
        $this->backdateAllFiles(120);
        $persistence->save('fresh', ['b' => 2]);
        $orphan = $this->dir . DIRECTORY_SEPARATOR . '.tmp-orphaned';
        file_put_contents($orphan, 'x');
        touch($orphan, time() - 120);
        $unrelated = $this->dir . DIRECTORY_SEPARATOR . 'unrelated.txt';
        file_put_contents($unrelated, 'keep me');
        touch($unrelated, time() - 120);

        $removed = $persistence->gc();

        $this->assertSame(2, $removed, 'one expired session + one orphaned temp file');
        $this->assertNull($persistence->load('old'));
        $this->assertSame(['b' => 2], $persistence->load('fresh'));
        $this->assertFileExists($unrelated, 'gc must not touch foreign files');
        @unlink($unrelated);
    }

    public function testGcProbabilityZeroNeverSweepsOnSave(): void
    {
        $persistence = new FileSessionPersistence(
            $this->dir,
            ['idle_ttl' => 60, 'gc_probability' => 0, 'gc_divisor' => 1]
        );
        $persistence->save('old', ['a' => 1]);
        $this->backdateAllFiles(120);

        for ($i = 0; $i < 25; $i++) {
            $persistence->save('churn-' . $i, ['i' => $i]);
        }

        $this->assertCount(26, $this->sessionFiles(), 'expired file must survive saves with gc_probability=0');
    }

    public function testGcProbabilityOneOverOneSweepsOnEverySave(): void
    {
        $persistence = new FileSessionPersistence(
            $this->dir,
            ['idle_ttl' => 60, 'gc_probability' => 1, 'gc_divisor' => 1]
        );
        $persistence->save('old', ['a' => 1]);
        $this->backdateAllFiles(120);

        $persistence->save('fresh', ['b' => 2]);

        $this->assertNull($persistence->load('old'));
        $this->assertSame(['b' => 2], $persistence->load('fresh'));
    }

    // -- failure paths -------------------------------------------------------

    public function testEmptyDirectoryThrows(): void
    {
        $this->expectException(StorageException::class);
        new FileSessionPersistence('');
    }

    public function testDirectoryCollidingWithExistingFileThrows(): void
    {
        mkdir($this->dir, 0700, true);
        $blocker = $this->dir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'not a directory');

        $this->expectException(StorageException::class);
        new FileSessionPersistence($blocker);
    }

    public function testCorruptedSessionFileLoadsAsNull(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $persistence->save('sid-1', ['a' => 1]);
        foreach ($this->sessionFiles() as $file) {
            file_put_contents($file, "\xde\xad\xbe\xef not any known serialization");
        }

        $this->assertNull($persistence->load('sid-1'));
    }

    public function testTruncatedJsonLoadsAsNull(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $persistence->save('sid-1', ['a' => 1]);
        foreach ($this->sessionFiles() as $file) {
            file_put_contents($file, '{"a": 1');
        }

        $this->assertNull($persistence->load('sid-1'));
    }

    public function testHostileSidCannotEscapeTheDirectory(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $hostile = '../../quiote-fsp-escape';
        $persistence->save($hostile, ['a' => 1]);

        $this->assertSame(['a' => 1], $persistence->load($hostile));
        $this->assertFileDoesNotExist(dirname($this->dir, 2) . DIRECTORY_SEPARATOR . 'quiote-fsp-escape');
        $files = $this->sessionFiles();
        $this->assertCount(1, $files);
        $this->assertSame($this->dir, dirname($files[0]), 'session file must live inside the configured directory');
    }

    public function testDistinctSidsGetDistinctFiles(): void
    {
        $persistence = new FileSessionPersistence($this->dir);
        $persistence->save('sid-1', ['who' => 'one']);
        $persistence->save('sid-2', ['who' => 'two']);

        $this->assertSame(['who' => 'one'], $persistence->load('sid-1'));
        $this->assertSame(['who' => 'two'], $persistence->load('sid-2'));
        $this->assertCount(2, $this->sessionFiles());
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function sessionFiles(): array
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.sess');
        return $files === false ? [] : $files;
    }

    private function backdateAllFiles(int $seconds): void
    {
        $entries = scandir($this->dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                touch($path, time() - $seconds);
            }
        }
    }
}
