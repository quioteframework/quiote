<?php

// Both runtime packages are registered: only one of them will claim the
// process, so the same app image serves under either server.
return [
    ['class' => \Quiote\Runtime\RoadRunner\WorkerRoadRunnerPlugin::class, 'enabled' => true],
    ['class' => \Quiote\Runtime\Swoole\WorkerSwoolePlugin::class, 'enabled' => true],
];
