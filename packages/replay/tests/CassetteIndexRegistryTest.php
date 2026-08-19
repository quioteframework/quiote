<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexChain;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Index\UnavailableIndex;

/**
 * The registry built every factory eagerly, so one misconfigured index aborted the chain before any
 * index existed -- and the shipped Azure configuration hit exactly that, since the Log Analytics
 * index borrows a storage auth mode that cannot authenticate an AAD-only API. That also defeated
 * {@see CassetteIndexChain}, which is deliberately built to tolerate a broken index.
 */
final class CassetteIndexRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        CassetteIndexRegistry::reset();
        parent::tearDown();
    }

    private static function cassette(): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'found'],
            request: ['method' => 'GET', 'uri' => '/x'],
            resolved: [],
            session: null,
            user: null,
            effects: [],
            response: ['status' => 200],
            exception: null,
            log: null,
        );
    }

    private static function resolvingIndex(): CassetteIndexInterface
    {
        $cassette = self::cassette();

        return new class($cassette) implements CassetteIndexInterface {
            public function __construct(private readonly Cassette $cassette)
            {
            }

            #[\Override]
            public function resolve(CassetteId $id, IndexHints $hints): ?Cassette
            {
                // Declines for any other id, so this fake exercises the chain's fall-through as
                // well as its resolution.
                return $id->slug === 'found' ? $this->cassette : null;
            }
        };
    }

    public function testAFactoryThatThrowsBecomesADecliningIndexRatherThanAbortingTheBuild(): void
    {
        CassetteIndexRegistry::register(static function (Container $container): CassetteIndexInterface {
            throw new \RuntimeException('Unknown or unsupported Azure token-provider auth strategy "shared_key"');
        });
        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => self::resolvingIndex());

        $indexes = CassetteIndexRegistry::build(new Container());

        $this->assertCount(2, $indexes);
        $this->assertInstanceOf(UnavailableIndex::class, $indexes[0]);
        // And the chain still resolves through the healthy one.
        $resolved = CassetteIndexChain::resolve($indexes, CassetteId::fromRaw('found'), new IndexHints());
        $this->assertSame('found', $resolved->meta['id']);
    }

    public function testAnUnavailableIndexReportsWhyRatherThanDecliningSilently(): void
    {
        // A null would mean "nothing to find here", which is a different answer from "this index is
        // misconfigured" -- and the second is what a developer needs told.
        CassetteIndexRegistry::register(static function (Container $container): CassetteIndexInterface {
            throw new \RuntimeException('no workspace credential');
        });

        $indexes = CassetteIndexRegistry::build(new Container());

        try {
            CassetteIndexChain::resolve($indexes, CassetteId::fromRaw('x'), new IndexHints());
            $this->fail('Expected the chain to fail.');
        } catch (CassetteIndexException $e) {
            $this->assertStringContainsString('could not be built', $e->getMessage());
            $this->assertStringContainsString('no workspace credential', $e->getMessage());
        }
    }

    public function testEveryFactoryIsStillBuiltWhenNoneThrows(): void
    {
        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => self::resolvingIndex());
        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => self::resolvingIndex());

        $indexes = CassetteIndexRegistry::build(new Container());

        $this->assertCount(2, $indexes);
        foreach ($indexes as $index) {
            $this->assertNotInstanceOf(UnavailableIndex::class, $index);
        }
    }
}
