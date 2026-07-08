<?php

use Quiote\Routing\Compiler\ModuleActionDiscovery;
use Quiote\Testing\PhpUnitTestCase;

final class ModuleActionDiscoveryTest extends PhpUnitTestCase
{
	private const FIXTURE_MODULES = __DIR__ . '/../../../../fixtures/TriadDiagnostics/Modules';

	public function testDiscoversEveryActionFileRegardlessOfRouteAttributes(): void
	{
		$entries = (new ModuleActionDiscovery())->discover([self::FIXTURE_MODULES], 'Sandbox');

		$byAction = [];
		foreach ($entries as $entry) {
			$byAction[$entry->action] = $entry;
		}

		$this->assertArrayHasKey('Good', $byAction);
		$this->assertArrayHasKey('NoView', $byAction);
		$this->assertArrayHasKey('NoTemplate', $byAction);
		$this->assertArrayHasKey('Broken', $byAction);
		$this->assertArrayHasKey('NoAutoView', $byAction);

		$this->assertSame('Widget', $byAction['Good']->module);
		$this->assertSame('Sandbox\Modules\Widget\Actions\GoodAction', $byAction['Good']->fqcn);
		$this->assertSame(self::FIXTURE_MODULES . '/Widget/Actions/GoodAction.php', $byAction['Good']->file);
	}

	public function testLegacyClassNameConvertsDottedActionsToUnderscores(): void
	{
		$entries = (new ModuleActionDiscovery())->discover([self::FIXTURE_MODULES], 'Sandbox');
		$good = null;
		foreach ($entries as $entry) {
			if ($entry->action === 'Good') {
				$good = $entry;
			}
		}

		$this->assertNotNull($good);
		$this->assertSame('Widget_GoodAction', $good->legacyClassName());
	}

	public function testDiscoverReturnsEmptyForADirectoryWithNoModules(): void
	{
		$entries = (new ModuleActionDiscovery())->discover([__DIR__], 'Sandbox');
		$this->assertSame([], $entries);
	}
}
