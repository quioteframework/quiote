<?php
use Quiote\Config\ConfigHandler;
use Quiote\Config\IDeclarationConfigHandler;

/**
 * A legacy-style handler that compiles a declaration and applies it, so
 * ConfigCache::load() has an observable effect without the artifact itself
 * containing any statements.
 */
class ImportTestHandler extends ConfigHandler implements IDeclarationConfigHandler
{
	public function execute($config, $context = null)
	{
		return "<?php\nreturn " . var_export(['constant' => 'ConfigCacheImportTest_included'], true) . ";\n";
	}

	public function apply(mixed $declaration, string $sourceRef): void
	{
		if (!is_array($declaration) || !isset($declaration['constant']) || !is_string($declaration['constant'])) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The declaration from "%s" must name a constant to define.',
				$sourceRef
			));
		}

		if (!defined($declaration['constant'])) {
			define($declaration['constant'], true);
		}
	}
}
