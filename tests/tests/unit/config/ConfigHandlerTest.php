<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\ConfigHandler;
use Quiote\Config\ConfigValueHolder;
use Quiote\Exception\ConfigurationException;

class MyTestConfigHandler extends ConfigHandler
{
	public function execute($config, $context = null)
	{
		return '';
	}

	/**
	 * @param array<int|string, mixed> $oldValues
	 * @return array<int|string, mixed>
	 */
	public function callGetItemParameters(ConfigValueHolder $itemNode, array $oldValues = [], bool $literalize = true): array
	{
		return $this->getItemParameters($itemNode, $oldValues, $literalize);
	}

	/**
	 * @return array<int, ConfigValueHolder>
	 */
	public function callOrderConfigurations(ConfigValueHolder $configurations, ?string $environment = null, ?string $context = null, bool $autoloadParser = true): array
	{
		return $this->orderConfigurations($configurations, $environment, $context, $autoloadParser);
	}
}

class ConfigHandlerTest extends PhpUnitTestCase
{
	protected ?MyTestConfigHandler $ch = null;
	#[\Override]
    public function setUp(): void
	{
		$this->ch = new MyTestConfigHandler();
		$this->ch->initialize('MyValidationFile.mvf');
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->ch = null;
	}

	public function testGetValidationFile(): void
	{
		$ch = $this->ch;
		if ($ch === null) {
			$this->fail('setUp() did not initialize the config handler under test.');
		}
		$this->assertSame('MyValidationFile.mvf', $ch->getValidationFile());
	}

	public function testGetItemParametersUsesNameAttributeAndSyntheticKeys(): void
	{
		$item = new ConfigValueHolder();
		$item->setName('item');

		$named = new ConfigValueHolder();
		$named->setName('parameter');
		$named->setAttribute('name', 'foo');
		$named->setValue('bar');
		$item->addChildren('parameter', $named);

		$unnamed = new ConfigValueHolder();
		$unnamed->setName('parameter');
		$unnamed->setValue('baz');
		$item->addChildren('parameter', $unnamed);

		$result = $this->ch?->callGetItemParameters($item);

		self::assertIsArray($result);
		$this->assertSame('bar', $result['foo']);
		$this->assertSame('baz', $result[0]);
	}

	public function testGetItemParametersThrowsWhenNameAttributeIsNotStringOrInt(): void
	{
		$item = new ConfigValueHolder();
		$item->setName('item');

		$named = new ConfigValueHolder();
		$named->setName('parameter');
		$named->setAttribute('name', ['not', 'a', 'scalar', 'key']);
		$named->setValue('bar');
		$item->addChildren('parameter', $named);

		$this->expectException(ConfigurationException::class);
		$this->ch?->callGetItemParameters($item);
	}

	public function testOrderConfigurationsFiltersByEnvironmentAndContext(): void
	{
		$configurations = new ConfigValueHolder();
		$configurations->setName('configurations');

		$default = new ConfigValueHolder();
		$default->setName('config');
		$configurations->addChildren('config', $default);

		$devOnly = new ConfigValueHolder();
		$devOnly->setName('config');
		$devOnly->setAttribute('environment', 'dev');
		$configurations->appendChildren($devOnly);

		$prodOnly = new ConfigValueHolder();
		$prodOnly->setName('config');
		$prodOnly->setAttribute('environment', 'prod');
		$configurations->appendChildren($prodOnly);

		$result = $this->ch?->callOrderConfigurations($configurations, 'dev', null);

		$this->assertSame([$default, $devOnly], $result);
	}

	public function testOrderConfigurationsThrowsWhenEnvironmentAttributeIsNotAString(): void
	{
		$configurations = new ConfigValueHolder();
		$configurations->setName('configurations');

		$bad = new ConfigValueHolder();
		$bad->setName('config');
		$bad->setAttribute('environment', 12345);
		$configurations->addChildren('config', $bad);

		$this->expectException(ConfigurationException::class);
		$this->ch?->callOrderConfigurations($configurations, 'dev', null);
	}

}
