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
  // Master telemetry gate (see docs/OPENTELEMETRY_PLAN.md and
  // docs/CONFIGURATION_SETTINGS.md's Telemetry section for the full
  // telemetry.* family). Requires the open-telemetry/* packages to be
  // installed to have any effect -- see the plan's Dependencies section.
  'telemetry.enabled' => false,
);
