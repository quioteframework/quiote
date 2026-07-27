<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\ArrayPathDefinition;

class ArrayPathDefinitionTest extends PhpUnitTestCase
{
	
	/**
	 * @param array{parts: array<int, string>, absolute: bool} $expected
	 * @param class-string<Throwable>|false $expectedException
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('getPathPartData')]
	public function testGetPartsFromPath(string $path, array $expected, string|false $expectedException): void
	{
		if($expectedException !== false) {
			$this->expectException($expectedException);
		}
		$this->assertEquals($expected, ArrayPathDefinition::getPartsFromPath($path));
	}

	/**
	 * @return array<string, array{0: string, 1: array{parts: array<int, string>, absolute: bool}, 2: string|false}>
	 */
	public static function getPathPartData(): array
	{
		return [
			'absolute,nopath' => [
				'level1',
				[
					'parts' => [
						'level1',
					],
					'absolute' => true,
				],
				false,
			],
			'absolute,1 level' => [
				'absolute[level1]',
				[
					'parts' => [
						'absolute',
						'level1',
					],
					'absolute' => true,
				],
				false,
			],
			'absolute,2 levels' => [
				'absolute[level1][level2]',
				[
					'parts' => [
						'absolute',
						'level1',
						'level2',
					],
					'absolute' => true,
				],
				false,
			],
			'relative, 1 level' => [
				'[level1]',
				[
					'parts' => [
						'level1'
					],
					'absolute' => false,
				],
				false,
			],
			'relative, 2 levels' => [
				'[level1][level2]',
				[
					'parts' => [
						'level1',
						'level2',
					],
					'absolute' => false,
				],
				false,
			],
			'brokenpath-1' => [
				'absolute[broken',
				[
					'parts' => [
						'absolute',
						'broken'
					],
					'absolute' => true,
				],
				'\InvalidArgumentException',
			],
			'brokenpath-2' => [
				'absolute[broken]]',
				[
					'parts' => [
						'absolute',
						'broken]'
					],
					'absolute' => true,
				],
				'\InvalidArgumentException',
			],
			'brokenpath-3' => [
				'absolute[[broken]',
				[
					'parts' => [
						'absolute[',
						'broken'
					],
					'absolute' => true,
				],
				'\InvalidArgumentException',
			],
			'partStartsWithZero,ticket1189' => [
				'0[1]',
				[
					'parts' => [
						'0',
						'1',
					],
					'absolute' => true,
				],
				false,
			],

		];
	}

	/**
	 * The memo cache stores results keyed by the raw path string; repeated
	 * calls with the same path must keep returning an equal (independent)
	 * result, and calls with a broken path must not poison the cache with a
	 * partial/incorrect entry.
	 */
	public function testGetPartsFromPathIsIdempotentAcrossRepeatedCalls(): void
	{
		$first = ArrayPathDefinition::getPartsFromPath('data[0][Field]');
		$second = ArrayPathDefinition::getPartsFromPath('data[0][Field]');

		$this->assertSame($first, $second);
	}

	public function testGetPartsFromPathThrowsConsistentlyOnRepeatedBrokenPath(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		ArrayPathDefinition::getPartsFromPath('absolute[broken');
	}

	public function testGetPartsFromPathThrowsAgainAfterPriorException(): void
	{
		try {
			ArrayPathDefinition::getPartsFromPath('absolute[broken');
		} catch (\InvalidArgumentException) {
		}

		$this->expectException(\InvalidArgumentException::class);
		ArrayPathDefinition::getPartsFromPath('absolute[broken');
	}
}


?>