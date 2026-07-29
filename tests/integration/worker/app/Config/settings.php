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
    // The PSR-7-native session stack. This probe is the only place it runs
    // against real servers, which is what has to be true before the
    // ext/session implementation can be retired.
    'core.use_modern_session' => true,
    'core.use_translation' => false,
    'core.default_context' => 'web',
    'core.csrf.enabled' => false,
    // Log to stderr, never stdout: under RoadRunner stdout *is* the protocol
    // relay, and writing to it corrupts the stream.
    'core.logging.sinks' => [],
];
