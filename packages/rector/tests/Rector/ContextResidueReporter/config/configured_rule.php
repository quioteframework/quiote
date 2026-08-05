<?php

declare(strict_types=1);

use Quiote\Rector\Rector\ContextResidueReporter;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(ContextResidueReporter::class);
};
