<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\ArrayMergeStrategy;

class ArrayMergeStrategyTest extends PhpUnitTestCase
{
	public function testScalarOverrideReplacesBaseValue(): void
	{
		$merger = new ArrayMergeStrategy();
		$result = $merger->merge(['a' => 1], ['a' => 2]);
		$this->assertSame(['a' => 2], $result);
	}

	public function testNestedAssociativeArraysMergeKeyByKey(): void
	{
		$merger = new ArrayMergeStrategy();
		$base = ['db' => ['host' => 'localhost', 'port' => 5432]];
		$override = ['db' => ['port' => 6543]];

		$result = $merger->merge($base, $override);

		$this->assertSame(['db' => ['host' => 'localhost', 'port' => 6543]], $result);
	}

	public function testListValuesAreReplacedWholesaleNotMergedByIndex(): void
	{
		$merger = new ArrayMergeStrategy();
		$base = ['tags' => ['a', 'b', 'c']];
		$override = ['tags' => ['x']];

		$result = $merger->merge($base, $override);

		$this->assertSame(['tags' => ['x']], $result);
	}

	public function testDoesNotMutateInputArrays(): void
	{
		$merger = new ArrayMergeStrategy();
		$base = ['a' => ['b' => 1]];
		$override = ['a' => ['b' => 2]];

		$merger->merge($base, $override);

		$this->assertSame(['a' => ['b' => 1]], $base);
		$this->assertSame(['a' => ['b' => 2]], $override);
	}

	public function testNewKeysInOverrideAreAdded(): void
	{
		$merger = new ArrayMergeStrategy();
		$result = $merger->merge(['a' => 1], ['b' => 2]);
		$this->assertSame(['a' => 1, 'b' => 2], $result);
	}

	public function testDeeplyNestedMergeAtMultipleLevels(): void
	{
		$merger = new ArrayMergeStrategy();
		$base = ['a' => ['b' => ['c' => 1, 'd' => 2]]];
		$override = ['a' => ['b' => ['c' => 99]]];

		$result = $merger->merge($base, $override);

		$this->assertSame(['a' => ['b' => ['c' => 99, 'd' => 2]]], $result);
	}
}
?>
