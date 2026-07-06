<?php

return array (
  'core.app_name' => 'SampleApp',
  'core.namespace_prefix' => 'SampleApp',
  'core.available' => true,
  'core.debug' => false,
  'core.use_database' => false,
  'core.use_logging' => true,
  'core.use_security' => false,
  'core.use_translation' => false,
  'core.default_context' => 'web',
  // Plugins are declared in Config/plugins.php now, not here.
  // Master telemetry gate (covers the full
  // telemetry.* family). Requires the open-telemetry/* packages to be
  // installed to have any effect -- see the plan's Dependencies section.
  'telemetry.enabled' => true,
  'telemetry.exporter' => 'otlp',
  'telemetry.export.mode' => 'simple',
  'telemetry.sampling.strategy' => 'always_on',
  'telemetry.otlp.endpoint' => 'http://127.0.0.1:4318',
  'telemetry.service.name' => 'quiote-sample-app',
);
