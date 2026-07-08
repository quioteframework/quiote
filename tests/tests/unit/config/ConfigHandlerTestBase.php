<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\XmlConfigParser;

abstract class ConfigHandlerTestBase extends PhpUnitTestCase
{
	protected function getIncludeFile(string $code): string
	{
		$file = tempnam(Config::getString('core.cache_dir'), 'cht');
		if ($file === false) {
			throw new \RuntimeException('Failed to create a temporary include file for the config handler test.');
		}
		file_put_contents($file, $code);
		return $file;
	}

	/**
	 * @param array<string, mixed> $env
	 */
	protected function includeCode(string $code, array $env = []): mixed
	{
		extract($env);
		$file = $this->getIncludeFile($code);
		$ret = include($file);
		unlink($file);
		return $ret;
	}

	protected function parseConfiguration(string $configFile, ?string $xslFile = null, ?string $environment = null): \Quiote\Config\Util\DOM\XmlConfigDomDocument {
		return XmlConfigParser::run(
			$configFile,
			$environment ?: Config::getNullableString('core.environment'),
			'',
			[
				XmlConfigParser::STAGE_SINGLE => $xslFile ? [$xslFile] : [],
				XmlConfigParser::STAGE_COMPILATION => [],
			],
			[
				XmlConfigParser::STAGE_SINGLE => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [],
				],
				XmlConfigParser::STAGE_COMPILATION => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => []
				],
			]
		);
		
	}
}
