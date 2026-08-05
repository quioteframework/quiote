<?php
use Quiote\Config\ConfigHandler;
use Quiote\Config\IDeclarationConfigHandler;

/**
 * Same as ImportTestHandler, but its effect is a global a test can reset between
 * calls -- which is how ConfigCache::load()'s $once behaviour is observed.
 */
class ImportTestOnceHandler extends ConfigHandler implements IDeclarationConfigHandler
{
	public function execute($config, $context = null)
	{
		return ['global' => 'ConfigCacheImportTestOnce_included'];
	}

	public function apply(mixed $declaration, string $sourceRef): void
	{
		if (!is_array($declaration) || !isset($declaration['global']) || !is_string($declaration['global'])) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The declaration from "%s" must name a global to set.',
				$sourceRef
			));
		}

		$GLOBALS[$declaration['global']] = true;
	}
}
