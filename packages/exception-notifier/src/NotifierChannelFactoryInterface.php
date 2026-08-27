<?php

namespace Quiote\ExceptionNotifier;

use Quiote\Http\Client\HttpClientFactory;

/**
 * Builds a {@see NotifierChannelInterface} from one entry of the
 * `exception_notifier.channels` config array. Implemented by every built-in
 * channel; a third-party channel registered via
 * {@see ExceptionNotifierChannelRegistry::register()} must implement it too,
 * since the registry has no other way to turn config into an instance.
 */
interface NotifierChannelFactoryInterface
{
    /**
     * @param array<string, mixed> $channelConfig one entry of `exception_notifier.channels`
     */
    public static function fromChannelConfig(array $channelConfig, HttpClientFactory $httpClientFactory): NotifierChannelInterface;
}
