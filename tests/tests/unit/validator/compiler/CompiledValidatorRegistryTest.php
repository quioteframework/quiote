<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry;
use Quiote\Validator\Validator;

class CompiledValidatorRegistryTest extends UnitTestCase
{
	private string $moduleDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->moduleDir = tempnam(sys_get_temp_dir(), 'cvr_');
		unlink($this->moduleDir);
		mkdir($this->moduleDir);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->moduleDir);
		parent::tearDown();
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	private function writeValidatorFile(string $module, string $action, string $suffix, string $body): void
	{
		$path = $this->moduleDir . '/' . $module . '/Validate/' . $action . $suffix;
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), 0777, true);
		}
		file_put_contents($path, $body);
	}

	public function testReturnsFalseWhenNoCandidateFileExists(): void
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$registry = new CompiledValidatorRegistry();

		$applied = $registry->apply($this->moduleDir, 'NoSuchModule', 'NoSuchAction', $vm, $this->getContext());
		$this->assertFalse($applied);
		$this->assertCount(0, $vm->getChilds());
	}

	public function testAppliesGeneratedFileAndRegistersValidator(): void
	{
		$this->writeValidatorFile('Demo', 'Create', '.generated.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->string('username', true)->minLength(3);
};
PHP);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$registry = new CompiledValidatorRegistry();
		$applied = $registry->apply($this->moduleDir, 'Demo', 'Create', $vm, $this->getContext(), 'write');

		$this->assertTrue($applied);
		$this->assertCount(1, $vm->getChilds());
	}

	public function testGeneratedFileTakesPrecedenceOverHandWrittenFile(): void
	{
		$this->writeValidatorFile('Demo', 'Create', '.generated.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->raw(\Quiote\Validator\SetValidator::class, [], ['value' => 'from-generated']);
};
PHP);
		$this->writeValidatorFile('Demo', 'Create', '.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->raw(\Quiote\Validator\SetValidator::class, [], ['value' => 'from-hand-written']);
};
PHP);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		(new CompiledValidatorRegistry())->apply($this->moduleDir, 'Demo', 'Create', $vm, $this->getContext());

		$childs = $vm->getChilds();
		$this->assertCount(1, $childs);
		$child = reset($childs);
		$this->assertInstanceOf(Validator::class, $child);
		$this->assertSame('from-generated', $child->getParameter('value'));
	}

	public function testFallsBackToHandWrittenFileWhenNoGeneratedFileExists(): void
	{
		$this->writeValidatorFile('Demo', 'Create', '.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->raw(\Quiote\Validator\SetValidator::class, [], ['value' => 'hand-written-only']);
};
PHP);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		(new CompiledValidatorRegistry())->apply($this->moduleDir, 'Demo', 'Create', $vm, $this->getContext());

		$childs = $vm->getChilds();
		$this->assertCount(1, $childs);
		$child = reset($childs);
		$this->assertInstanceOf(Validator::class, $child);
		$this->assertSame('hand-written-only', $child->getParameter('value'));
	}

	public function testDottedActionNameMapsToNestedDirectoryLikeXmlPathDoes(): void
	{
		$this->writeValidatorFile('Demo', 'Nested/Action', '.generated.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->isNotEmpty('x', false);
};
PHP);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$applied = (new CompiledValidatorRegistry())->apply($this->moduleDir, 'Demo', 'Nested.Action', $vm, $this->getContext());
		$this->assertTrue($applied);
	}

	public function testResolvedPathIsMemoizedButSelfHealsIfFileIsRemoved(): void
	{
		$this->writeValidatorFile('Demo', 'Create', '.generated.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->raw(\Quiote\Validator\SetValidator::class, [], ['value' => 'still-here']);
};
PHP);
		$registry = new CompiledValidatorRegistry();

		$vm1 = $this->getContext()->createInstanceFor('validation_manager');
		$this->assertTrue($registry->apply($this->moduleDir, 'Demo', 'Create', $vm1, $this->getContext()));

		// A second call for the same (moduleDir, module, action) hits the
		// per-process memoization cache; it must still find the file (the
		// cache re-verifies with is_file() rather than trusting blindly).
		$vm2 = $this->getContext()->createInstanceFor('validation_manager');
		$this->assertTrue($registry->apply($this->moduleDir, 'Demo', 'Create', $vm2, $this->getContext()));

		unlink($this->moduleDir . '/Demo/Validate/Create.generated.php');

		$vm3 = $this->getContext()->createInstanceFor('validation_manager');
		$this->assertFalse(
			$registry->apply($this->moduleDir, 'Demo', 'Create', $vm3, $this->getContext()),
			'A resolved path must self-heal (re-check) rather than being trusted forever once the file disappears.'
		);
	}

	public function testNegativeResolutionIsMemoizedForTheProcessLifetime(): void
	{
		// Deliberate tradeoff (documented on CompiledValidatorRegistry,
		// mirroring ConfigCache::isModified()'s own precedent): a "no file"
		// result is trusted for the rest of the worker's lifetime once
		// observed, so the common case (no compiled validator file) never
		// pays a repeated stat() cost. Creating the file afterwards must
		// NOT be picked up without a fresh registry... this test locks in
		// that the cache is keyed per (moduleDir, module, action), so a
		// *different* action is unaffected by another action's cached miss.
		$registry = new CompiledValidatorRegistry();
		$vmMiss = $this->getContext()->createInstanceFor('validation_manager');
		$this->assertFalse($registry->apply($this->moduleDir, 'Demo', 'NeverExisted', $vmMiss, $this->getContext()));

		$this->writeValidatorFile('Demo', 'DifferentAction', '.generated.php', <<<'PHP'
<?php
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
    $v->isNotEmpty('y', false);
};
PHP);
		$vmHit = $this->getContext()->createInstanceFor('validation_manager');
		$this->assertTrue($registry->apply($this->moduleDir, 'Demo', 'DifferentAction', $vmHit, $this->getContext()));
	}

	public function testThrowsWhenFileDoesNotReturnCallable(): void
	{
		$this->writeValidatorFile('Demo', 'Broken', '.generated.php', "<?php\nreturn 'not-a-callable';\n");

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$this->expectException(RuntimeException::class);
		(new CompiledValidatorRegistry())->apply($this->moduleDir, 'Demo', 'Broken', $vm, $this->getContext());
	}
}
?>
