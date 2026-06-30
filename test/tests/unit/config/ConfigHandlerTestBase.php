<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviXmlConfigParser;

abstract class ConfigHandlerTestBase extends AgaviPhpUnitTestCase
{
	protected function getIncludeFile($code)
	{
		$file = tempnam(AgaviConfig::get('core.cache_dir'), 'cht');
		file_put_contents($file, $code);
		return $file;
	}

	protected function includeCode($code, $env = [])
	{
		extract($env);
		$file = $this->getIncludeFile($code);
		$ret = include($file);
		unlink($file);
		return $ret;
	}
	
	protected function parseConfiguration($configFile, $xslFile = null, $environment = null) {
		return AgaviXmlConfigParser::run(
			$configFile,
			$environment ?: AgaviConfig::get('core.environment'),
			'',
			[
				AgaviXmlConfigParser::STAGE_SINGLE => $xslFile ? [$xslFile] : [],
				AgaviXmlConfigParser::STAGE_COMPILATION => [],
			],
			[
				AgaviXmlConfigParser::STAGE_SINGLE => [
					AgaviXmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					AgaviXmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [],
				],
				AgaviXmlConfigParser::STAGE_COMPILATION => [
					AgaviXmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					AgaviXmlConfigParser::STEP_TRANSFORMATIONS_AFTER => []
				],
			]
		);
		
	}
}
