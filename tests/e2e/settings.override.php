<?php

// telemetry.* overrides baked into the e2e-only image (tests/e2e/Dockerfile) --
// see docs/OPENTELEMETRY_E2E_VERIFICATION.md. 'batch' export mode + real
// FrankenPHP worker mode is deliberately different from the manual
// verification exercise (which used 'simple') -- this exercises
// BatchSpanProcessor and the per-request forceFlush() wired into Kernel's
// worker reset closure for real, under a real worker process.
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
  'telemetry.enabled' => true,
  'telemetry.exporter' => 'otlp',
  'telemetry.export.mode' => 'batch',
  'telemetry.sampling.strategy' => 'always_on',
  'telemetry.otlp.endpoint' => 'http://otel-collector:4318',
  'telemetry.service.name' => 'quiote-e2e-frankenphp',
);
