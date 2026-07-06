<?php

// telemetry-otel is opt-in -- without this, telemetry.enabled in settings.php
// does nothing.
return [
    ['class' => \Quiote\Telemetry\TelemetryPlugin::class, 'enabled' => true],
];
