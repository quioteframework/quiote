<?php

declare(strict_types=1);

// Probe endpoints answer in JSON so the integration tests can assert on
// structure rather than scraping HTML.
echo json_encode(['hits' => $template['hits'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
