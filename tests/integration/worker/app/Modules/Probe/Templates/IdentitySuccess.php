<?php

declare(strict_types=1);

echo json_encode([
	'authenticated' => (bool) ($template['authenticated'] ?? false),
	'roles' => array_values((array) ($template['roles'] ?? [])),
	'credentials' => array_values((array) ($template['credentials'] ?? [])),
	'display_name' => $template['display_name'] ?? null,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
