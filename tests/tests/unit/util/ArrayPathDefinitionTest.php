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
}


?>