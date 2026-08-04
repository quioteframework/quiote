<?php

declare(strict_types=1);

use Quiote\Rector\Rector\ContextRequestToRequestStateRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->importNames();
    $rectorConfig->rule(ContextRequestToRequestStateRector::class);
};
