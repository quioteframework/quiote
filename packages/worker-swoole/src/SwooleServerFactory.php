<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

interface SwooleServerFactory
{
    /**
     * @param array<string, mixed> $settings Passed straight to Swoole's set().
     */
    public function create(string $host, int $port, array $settings): SwooleServerInterface;
}
