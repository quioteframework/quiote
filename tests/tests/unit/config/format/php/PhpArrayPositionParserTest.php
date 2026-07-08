<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\Php\PhpArrayPositionParser;

class PhpArrayPositionParserTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'papp_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	private function write(string $code): string
	{
		$path = $this->dir . '/config.php';
		file_put_contents($path, $code);
		return $path;
	}

	public function testFlatAssociativeMap(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    'foo' => 'bar',
    'baz' => 42,
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions['foo']['line']);
		$this->assertSame($path, $positions['foo']['file']);
		$this->assertSame(4, $positions['baz']['line']);
	}

	public function testNestedMap(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    'database' => [
        'class' => 'eloquent',
        'parameters' => [
            'dsn' => 'sqlite::memory:',
        ],
    ],
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(4, $positions['database.class']['line']);
		$this->assertSame(6, $positions['database.parameters.dsn']['line']);
		$this->assertArrayNotHasKey('database', $positions);
		$this->assertArrayNotHasKey('database.parameters', $positions);
	}

	public function testPlainList(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    'App\Plugin\One',
    'App\Plugin\Two',
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions['[0]']['line']);
		$this->assertSame(4, $positions['[1]']['line']);
	}

	public function testMixedListOfMaps(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    ['class' => 'App\Plugin\One', 'enabled' => true],
    ['class' => 'App\Plugin\Two'],
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions['[0].class']['line']);
		$this->assertSame(3, $positions['[0].enabled']['line']);
		$this->assertSame(4, $positions['[1].class']['line']);
		$this->assertArrayNotHasKey('[1].enabled', $positions);
	}

	public function testIntegerKeys(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    0 => 'zero',
    5 => 'five',
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions['[0]']['line']);
		$this->assertSame(4, $positions['[5]']['line']);
	}

	public function testDoubleQuotedKeyWithEscape(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    "foo\tbar" => 'value',
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions["foo\tbar"]['line']);
	}

	public function testLegacyArraySyntax(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return array(
    'foo' => array(
        'bar' => 'baz',
    ),
);
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(4, $positions['foo.bar']['line']);
	}

	public function testTrailingCommaAndComments(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    // a leading comment
    'foo' => 'bar', // trailing comment
    'baz' => 'qux',
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(4, $positions['foo']['line']);
		$this->assertSame(5, $positions['baz']['line']);
	}

	public function testNonArrayReturnYieldsNoPositions(): void
	{
		$path = $this->write("<?php\nreturn 'not-an-array';\n");

		$this->assertSame([], PhpArrayPositionParser::parse($path));
	}

	public function testFunctionCallValueIsRecordedAsLeafWithoutDescending(): void
	{
		$path = $this->write(<<<'PHP'
<?php
return [
    'class' => \Quiote\Foo\Bar::class,
    'next' => 'value',
];
PHP);

		$positions = PhpArrayPositionParser::parse($path);

		$this->assertSame(3, $positions['class']['line']);
		$this->assertSame(4, $positions['next']['line']);
	}

	public function testMissingFileYieldsNoPositions(): void
	{
		$this->assertSame([], PhpArrayPositionParser::parse($this->dir . '/does-not-exist.php'));
	}
}
?>
