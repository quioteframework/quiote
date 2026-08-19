<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Exception\StorageException;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\FileCassetteStore;

final class FileCassetteStoreTest extends TestCase
{
    private string $dir;
    private ?string $originalAppDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiote-cassettes-' . bin2hex(random_bytes(6));
        $this->originalAppDir = Config::getNullableString('core.app_dir');
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
        // Restored rather than removed: other tests in this process rely on core.app_dir
        // already being set by tests/bootstrap.php.
        if ($this->originalAppDir !== null) {
            Config::set('core.app_dir', $this->originalAppDir, true, false);
        } else {
            Config::remove('core.app_dir');
        }
        parent::tearDown();
    }

    private function makeCassette(string $rawId): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => $rawId],
            request: ['method' => 'GET'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
            exception: null,
            log: null,
        );
    }

    public function testPutThenGetRoundTrips(): void
    {
        $store = new FileCassetteStore($this->dir);
        $id = CassetteId::fromRaw('CRX2050');
        $cassette = $this->makeCassette('CRX2050');

        $store->put($id, $cassette);
        $loaded = $store->get($id);

        $this->assertNotNull($loaded);
        $this->assertSame(['id' => 'CRX2050'], $loaded->meta);
    }

    public function testHasReflectsWhetherACassetteWasStored(): void
    {
        $store = new FileCassetteStore($this->dir);
        $id = CassetteId::fromRaw('CRX2050');

        $this->assertFalse($store->has($id));
        $store->put($id, $this->makeCassette('CRX2050'));
        $this->assertTrue($store->has($id));
    }

    public function testGetOnAnUnknownIdReturnsNull(): void
    {
        $store = new FileCassetteStore($this->dir);

        $this->assertNull($store->get(CassetteId::fromRaw('never-stored')));
    }

    public function testDirectoryIsCreatedWith0700Permissions(): void
    {
        new FileCassetteStore($this->dir);

        $this->assertDirectoryExists($this->dir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testWrittenFileHas0600Permissions(): void
    {
        $store = new FileCassetteStore($this->dir);
        $id = CassetteId::fromRaw('CRX2050');
        $store->put($id, $this->makeCassette('CRX2050'));

        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.qcast');
        $this->assertNotFalse($files);
        $this->assertCount(1, $files);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function testDeleteRemovesAStoredCassette(): void
    {
        $store = new FileCassetteStore($this->dir);
        $id = CassetteId::fromRaw('CRX2050');
        $store->put($id, $this->makeCassette('CRX2050'));

        $store->delete($id);

        $this->assertFalse($store->has($id));
        $this->assertNull($store->get($id));
    }

    public function testDeleteOfAnUnknownIdIsNotAnError(): void
    {
        $store = new FileCassetteStore($this->dir);

        $store->delete(CassetteId::fromRaw('never-stored'));

        $this->addToAssertionCount(1);
    }

    public function testSlugsListsEveryStoredCassette(): void
    {
        $store = new FileCassetteStore($this->dir);
        $store->put(CassetteId::fromRaw('AAA'), $this->makeCassette('AAA'));
        $store->put(CassetteId::fromRaw('BBB'), $this->makeCassette('BBB'));

        $this->assertSame(['AAA', 'BBB'], $store->slugs());
    }

    public function testEmptyDirectoryPathIsRefused(): void
    {
        $this->expectException(StorageException::class);
        new FileCassetteStore('');
    }

    public function testADirectoryInsideTheApplicationsPublicDocumentRootIsRefused(): void
    {
        Config::set('core.app_dir', $this->dir, true, false);
        $publicSubdir = $this->dir . '/pub/cassettes';

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/public document root/');
        new FileCassetteStore($publicSubdir);
    }

    public function testADirectoryOutsideThePublicDocumentRootIsAccepted(): void
    {
        Config::set('core.app_dir', $this->dir, true, false);
        $sibling = $this->dir . '-var-cassettes';

        $store = new FileCassetteStore($sibling);
        $store->put(CassetteId::fromRaw('CRX2050'), $this->makeCassette('CRX2050'));

        $this->assertTrue($store->has(CassetteId::fromRaw('CRX2050')));

        // cleanup: this directory is outside $this->dir so tearDown() won't touch it.
        @unlink($sibling . DIRECTORY_SEPARATOR . CassetteId::fromRaw('CRX2050')->slug . '.qcast');
        @rmdir($sibling);
    }

    public function testTheCassetteFileIsOwnerOnlyFromTheMomentItExists(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX mode bits are not the real ACL on this platform.');
        }
        $store = new FileCassetteStore($this->dir);
        $id = CassetteId::fromRaw('PERMS');

        $store->put($id, $this->makeCassette('X'));

        $file = $this->dir . DIRECTORY_SEPARATOR . 'PERMS.qcast';
        $this->assertFileExists($file);
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function testAPreExistingWorldReadableDirectoryIsNarrowedToOwnerOnly(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX mode bits are not the real ACL on this platform.');
        }
        // The 0700 mkdir applies only to a directory this class creates; one that already existed --
        // `mkdir -p` under the usual umask leaves 0755 -- was accepted as-is, so 0600 cassettes sat
        // in a directory anyone on the host could list. Narrowed rather than refused, because this
        // store is a container singleton and throwing takes every request down.
        $loose = $this->dir . '-loose';
        mkdir($loose, 0777, true);
        chmod($loose, 0755);

        try {
            $store = new FileCassetteStore($loose);
            clearstatcache(true, $loose);

            $this->assertSame(0700, fileperms($loose) & 0777);
            $store->put(CassetteId::fromRaw('OK'), $this->makeCassette('X'));
            $this->assertNotNull($store->get(CassetteId::fromRaw('OK')));
        } finally {
            foreach (glob($loose . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($loose);
        }
    }

    public function testAPreExistingOwnerOnlyDirectoryIsAccepted(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX mode bits are not the real ACL on this platform.');
        }
        $tight = $this->dir . '-tight';
        mkdir($tight, 0700, true);

        try {
            $store = new FileCassetteStore($tight);
            $store->put(CassetteId::fromRaw('OK1'), $this->makeCassette('X'));
            $this->assertNotNull($store->get(CassetteId::fromRaw('OK1')));
        } finally {
            foreach (glob($tight . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tight);
        }
    }

    public function testARelativePathIsAnchoredToTheAppDirNotTheWorkingDirectory(): void
    {
        // Where a cassette lands must not depend on the SAPI's CWD -- the project root under
        // RoadRunner, frequently the document root under php-fpm.
        $appDir = $this->dir . '-app';
        mkdir($appDir, 0700, true);
        $original = Config::getNullableString('core.app_dir');
        Config::set('core.app_dir', $appDir, true, false);

        try {
            $store = new FileCassetteStore('var/cassettes');
            $store->put(CassetteId::fromRaw('ANCHORED'), $this->makeCassette('X'));

            $this->assertFileExists($appDir . '/var/cassettes/ANCHORED.qcast');
        } finally {
            @unlink($appDir . '/var/cassettes/ANCHORED.qcast');
            @rmdir($appDir . '/var/cassettes');
            @rmdir($appDir . '/var');
            @rmdir($appDir);
            if ($original !== null) {
                Config::set('core.app_dir', $original, true, false);
            } else {
                Config::remove('core.app_dir');
            }
        }
    }

    public function testARelativePathWithNoAppDirIsRefusedRatherThanResolvedAgainstTheCwd(): void
    {
        $original = Config::getNullableString('core.app_dir');
        Config::remove('core.app_dir');

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessageMatches('/depends on the process working directory/');
            new FileCassetteStore('var/cassettes');
        } finally {
            if ($original !== null) {
                Config::set('core.app_dir', $original, true, false);
            }
        }
    }

    public function testAnAbsolutePathIsUsedAsGiven(): void
    {
        $store = new FileCassetteStore($this->dir);
        $store->put(CassetteId::fromRaw('ABS'), $this->makeCassette('X'));

        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'ABS.qcast');
    }
}
