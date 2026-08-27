<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\ExceptionNotifier\Channel\TeamsNotifierChannel;
use Quiote\ExceptionNotifier\Channel\WebhookNotifierChannel;
use Quiote\ExceptionNotifier\ExceptionNotificationContext;
use Quiote\ExceptionNotifier\ExceptionNotifierChannelRegistry;
use Quiote\ExceptionNotifier\NotifierChannelFactoryInterface;
use Quiote\ExceptionNotifier\NotifierChannelInterface;
use Quiote\Http\Client\HttpClientFactory;

final class ExceptionNotifierChannelRegistryTest extends TestCase
{
    #[Before]
    #[After]
    public function resetRegistry(): void
    {
        ExceptionNotifierChannelRegistry::reset();
    }

    public function testBuiltInAliasesAreRegistered(): void
    {
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('teams'));
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('webhook'));
        $this->assertSame(TeamsNotifierChannel::class, ExceptionNotifierChannelRegistry::aliases()['teams']);
        $this->assertSame(WebhookNotifierChannel::class, ExceptionNotifierChannelRegistry::aliases()['webhook']);
    }

    public function testRegisterAddsANewAliasThatInstantiateCanResolve(): void
    {
        ExceptionNotifierChannelRegistry::register('fake', FakeNotifierChannel::class);
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('fake'));

        $channel = ExceptionNotifierChannelRegistry::instantiate('fake', [], new HttpClientFactory());
        $this->assertInstanceOf(FakeNotifierChannel::class, $channel);
    }

    public function testInstantiateThrowsForAnUnknownAlias(): void
    {
        $this->expectException(RuntimeException::class);
        ExceptionNotifierChannelRegistry::instantiate('does-not-exist', [], new HttpClientFactory());
    }

    public function testInstantiateThrowsWhenTheRegisteredClassDoesNotImplementBothInterfaces(): void
    {
        ExceptionNotifierChannelRegistry::register('broken', stdClass::class);

        $this->expectException(RuntimeException::class);
        ExceptionNotifierChannelRegistry::instantiate('broken', [], new HttpClientFactory());
    }

    public function testResetRestoresOnlyTheBuiltInAliases(): void
    {
        ExceptionNotifierChannelRegistry::register('fake', FakeNotifierChannel::class);
        ExceptionNotifierChannelRegistry::reset();

        $this->assertFalse(ExceptionNotifierChannelRegistry::has('fake'));
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('teams'));
        $this->assertTrue(ExceptionNotifierChannelRegistry::has('webhook'));
    }
}

final class FakeNotifierChannel implements NotifierChannelInterface, NotifierChannelFactoryInterface
{
    public static function fromChannelConfig(array $channelConfig, HttpClientFactory $httpClientFactory): NotifierChannelInterface
    {
        return new self();
    }

    public function notify(Throwable $exception, ExceptionNotificationContext $context): void
    {
    }
}
