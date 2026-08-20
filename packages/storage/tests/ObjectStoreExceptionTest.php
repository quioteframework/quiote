<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Storage\ObjectStoreException;

/**
 * The one thing `ObjectStoreException` promises: that code written against
 * `ObjectStoreClientInterface` can catch a single type instead of enumerating providers, while a
 * provider's own subclass still narrows to that provider. Both halves are inheritance, which is
 * exactly the kind of contract a later "tidy-up" breaks silently -- resealing the class or
 * reparenting it away from `RuntimeException` would leave every existing `catch` compiling and
 * catching nothing.
 */
final class ObjectStoreExceptionTest extends TestCase
{
    public function testItIsARuntimeException(): void
    {
        // Apps predating the shared supertype catch \RuntimeException around storage calls.
        $this->assertInstanceOf(RuntimeException::class, new ObjectStoreException('failed'));
    }

    public function testAProviderSubclassIsCaughtAsTheSharedType(): void
    {
        $providerFailure = new class ('blob not found') extends ObjectStoreException {};

        try {
            throw $providerFailure;
        } catch (ObjectStoreException $e) {
            $this->assertSame('blob not found', $e->getMessage());

            return;
        }
    }

    public function testItCanBeSubclassed(): void
    {
        // Not final, deliberately: cloud-azure, cloud-s3 and cloud-gcs each extend it so
        // `catch (AzureStorageException)` still narrows to Azure.
        $this->assertFalse((new ReflectionClass(ObjectStoreException::class))->isFinal());
    }

    public function testItCarriesACauseThroughLikeAnyException(): void
    {
        $cause = new RuntimeException('connection reset');

        $exception = new ObjectStoreException('failed reading object', 7, $cause);

        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame(7, $exception->getCode());
    }
}
