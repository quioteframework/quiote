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
}
