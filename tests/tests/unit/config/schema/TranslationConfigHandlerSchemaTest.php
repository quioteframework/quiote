<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\TranslationConfigHandler;
use Quiote\Config\Schema\SchemaValidator;

class TranslationConfigHandlerSchemaTest extends PhpUnitTestCase
{
	private function cleanConfig(): array
	{
		return [
			'default_domain' => 'messages',
			'default_locale' => 'en_US',
			'default_timezone' => 'UTC',
			'locales' => [
				'en_US' => ['name' => 'en_US', 'params' => [], 'fallback' => null, 'ldml_file' => null],
			],
			'translators' => [
				'messages' => [
					'msg' => ['class' => 'App\\Translator', 'filters' => [], 'params' => []],
				],
			],
		];
	}

	public function testCleanCanonicalArrayHasNoDiagnostics(): void
	{
		$handler = new TranslationConfigHandler();

		$this->assertSame([], SchemaValidator::validate($handler->schema(), $this->cleanConfig()));
	}

	public function testMissingTopLevelKeyIsReported(): void
	{
		$handler = new TranslationConfigHandler();

		$config = $this->cleanConfig();
		unset($config['default_locale']);

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('default_locale', $diagnostics[0]->keyPath);
	}

	public function testUnrecognizedTranslatorTypeIsReported(): void
	{
		$handler = new TranslationConfigHandler();

		$config = $this->cleanConfig();
		$config['translators']['messages']['msgs'] = ['class' => 'X', 'filters' => [], 'params' => []];

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('translators.messages.msgs', $diagnostics[0]->keyPath);
	}

	public function testMissingLocaleFieldIsReported(): void
	{
		$handler = new TranslationConfigHandler();

		$config = $this->cleanConfig();
		unset($config['locales']['en_US']['fallback']);

		$diagnostics = SchemaValidator::validate($handler->schema(), $config);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('locales.en_US.fallback', $diagnostics[0]->keyPath);
	}
}
?>
