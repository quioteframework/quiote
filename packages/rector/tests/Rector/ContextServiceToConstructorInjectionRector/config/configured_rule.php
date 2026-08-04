<?php

declare(strict_types=1);

use Quiote\Rector\Rector\ContextServiceToConstructorInjectionRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    // Matches what an application's rector.php should do. Without it the rule writes injected
    // types fully qualified, which is correct but unreadable across hundreds of call sites.
    $rectorConfig->importNames();
    $rectorConfig->rule(ContextServiceToConstructorInjectionRector::class);
};
