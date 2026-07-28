<?php

// Probe app for the worker-runtime integration suite. Deliberately minimal and
// separate from samples/app: the endpoints here exist to exercise behaviour that
// only differs once the app leaves the SAPI (sessions, stray output, streaming,
// proxy headers), which is not something a sample app should carry.
return [
    'core.app_name' => 'WorkerProbe',
    'core.namespace_prefix' => 'WorkerProbe',
    'core.available' => true,
    'core.debug' => false,
    'core.use_database' => false,
    'core.use_logging' => true,
    'core.use_security' => false,
    'core.use_translation' => false,
    'core.default_context' => 'web',
    'core.csrf.enabled' => false,
    // Log to stderr, never stdout: under RoadRunner stdout *is* the protocol
    // relay, and writing to it corrupts the stream.
    'core.logging.sinks' => [],
];
