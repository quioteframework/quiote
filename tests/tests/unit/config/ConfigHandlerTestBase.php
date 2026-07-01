<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\XmlConfigParser;

abstract class ConfigHandlerTestBase extends PhpUnitTestCase
{
	protected function getIncludeFile($code)
	{
		$file = tempnam(Config::get('core.cache_dir'), 'cht');
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
		return XmlConfigParser::run(
			$configFile,
			$environment ?: Config::get('core.environment'),
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
