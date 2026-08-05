<?php

declare(strict_types=1);

use Quiote\Rector\Rector\ContextUserToConstructorInjectionRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    // Matches what an application's rector.php should do; see the other rules' configs.
    $rectorConfig->importNames();
    $rectorConfig->rule(ContextUserToConstructorInjectionRector::class);
};
