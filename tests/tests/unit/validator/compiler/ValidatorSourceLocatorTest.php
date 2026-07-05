<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Validator\Compiler\ValidatorSourceLocator;

class ValidatorSourceLocatorTest extends PhpUnitTestCase
{
	public function testDiscoverFindsPerActionValidatorFiles()
	{
		$locator = new ValidatorSourceLocator();
		$sources = $locator->discover([Config::getString('core.module_dir') . '/*/Validate/*.xml']);

		$this->assertNotEmpty($sources);
		$paths = array_map(fn($s) => basename($s->path), $sources);
		$this->assertContains('MethodHttp.xml', $paths);
		$this->assertContains('SimpleAction.xml', $paths);
		$this->assertContains('Welcome.xml', $paths);
	}

	public function testDiscoverIsSortedAndDeduplicated()
	{
		$locator = new ValidatorSourceLocator();
		$pattern = Config::getString('core.module_dir') . '/*/Validate/*.xml';
		$sources = $locator->discover([$pattern, $pattern]);

		$paths = array_map(fn($s) => $s->path, $sources);
		$this->assertSame($paths, array_unique($paths));

		$sorted = $paths;
		sort($sorted);
		$this->assertSame($sorted, $paths);
	}

	public function testDiscoverReturnsEmptyForNonMatchingPattern()
	{
		$locator = new ValidatorSourceLocator();
		$sources = $locator->discover([Config::getString('core.module_dir') . '/NoSuchModule*/Validate/*.xml']);
		$this->assertSame([], $sources);
	}

	public function testDefaultRootsMatchesConfigHandlersXmlPattern()
	{
		$roots = ValidatorSourceLocator::defaultRoots();
		$this->assertCount(1, $roots);
		$this->assertSame(Config::getString('core.module_dir') . '/*/Validate/*.xml', $roots[0]);
	}
}
?>
