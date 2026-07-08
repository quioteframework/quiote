<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Schema\SchemaValidator;
use Quiote\Config\Schema\Severity;

/**
 * Proves SchemaValidator's generic structural checks (allowed keys, enums-of-
 * kind, nesting) in isolation from any real config handler.
 */
class SchemaValidatorTest extends PhpUnitTestCase
{
	public function testStructWithAllRequiredKeysPresentIsClean(): void
	{
		$schema = Rule::struct(['class' => Rule::phpClass()], required: ['class']);

		$this->assertSame([], SchemaValidator::validate($schema, ['class' => 'Foo\\Bar']));
	}

	public function testStructMissingRequiredKeyReportsDiagnostic(): void
	{
		$schema = Rule::struct(['class' => Rule::phpClass()], required: ['class']);

		$diagnostics = SchemaValidator::validate($schema, []);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.missing_required_key', $diagnostics[0]->code);
		$this->assertSame('class', $diagnostics[0]->keyPath);
		$this->assertSame(Severity::Error, $diagnostics[0]->severity);
	}

	public function testStructRejectsUnknownKeyWhenClosed(): void
	{
		$schema = Rule::struct(['class' => Rule::phpClass()], closed: true);

		$diagnostics = SchemaValidator::validate($schema, ['class' => 'Foo', 'oops' => 1]);

		$this->assertCount(1, $diagnostics);
		$this->assertSame('schema.unknown_key', $diagnostics[0]->code);
		$this->assertSame('oops', $diagnostics[0]->keyPath);
	}

	public function testStructAllowsUnknownKeyWhenOpen(): void
	{
		$schema = Rule::struct(['class' => Rule::phpClass()], closed: false);

		$this->assertSame([], SchemaValidator::validate($schema, ['class' => 'Foo', 'extra' => 1]));
	}

	public function testStructRejectsNonArrayValue(): void
	{
		$schema = Rule::struct(['class' => Rule::phpClass()]);

		$diagnostics = SchemaValidator::validate($schema, 'not-an-array');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
	}

	public function testStringRejectsNonStringValue(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::string(), 42, 'name');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('name', $diagnostics[0]->keyPath);
	}

	public function testBoolRejectsNonBoolValue(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::bool(), 'true');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
	}

	public function testIntRejectsNonIntValue(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::int(), '1');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
	}

	public function testPhpClassAcceptsNamespacedClassName(): void
	{
		$this->assertSame([], SchemaValidator::validate(Rule::phpClass(), 'Quiote\\Config\\Config'));
	}

	public function testPhpClassAcceptsBareIdentifier(): void
	{
		// Short driver aliases (e.g. "eloquent") are syntactically valid too --
		// schema validation only checks shape, not that the class exists.
		$this->assertSame([], SchemaValidator::validate(Rule::phpClass(), 'eloquent'));
	}

	public function testPhpClassRejectsInvalidString(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::phpClass(), '1Invalid');

		$this->assertSame('schema.invalid_php_class', $diagnostics[0]->code);
	}

	public function testEnumAcceptsAnAllowedValue(): void
	{
		$this->assertSame([], SchemaValidator::validate(Rule::enumOf(['pre', 'post']), 'pre'));
	}

	public function testEnumRejectsADisallowedValue(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::enumOf(['pre', 'post']), 'during', 'phase');

		$this->assertSame('schema.invalid_enum_value', $diagnostics[0]->code);
		$this->assertSame('phase', $diagnostics[0]->keyPath);
	}

	public function testEnumRejectsNonStringValue(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::enumOf(['pre', 'post']), 42);

		$this->assertSame('schema.invalid_enum_value', $diagnostics[0]->code);
	}

	public function testPhpClassRejectsEmptyString(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::phpClass(), '');

		$this->assertSame('schema.invalid_php_class', $diagnostics[0]->code);
	}

	public function testNullableAcceptsNull(): void
	{
		$this->assertSame([], SchemaValidator::validate(Rule::string(nullable: true), null));
	}

	public function testNonNullableRejectsNull(): void
	{
		$diagnostics = SchemaValidator::validate(Rule::string(), null, 'name');

		$this->assertSame('schema.null_not_allowed', $diagnostics[0]->code);
		$this->assertSame('name', $diagnostics[0]->keyPath);
	}

	public function testMixedAcceptsAnything(): void
	{
		$this->assertSame([], SchemaValidator::validate(Rule::mixed(), ['anything' => true]));
		$this->assertSame([], SchemaValidator::validate(Rule::mixed(), null));
	}

	public function testDictValidatesEveryValueAgainstSharedSchema(): void
	{
		$schema = Rule::dictOf(Rule::struct(['class' => Rule::phpClass()], required: ['class']));

		$diagnostics = SchemaValidator::validate($schema, [
			'a' => ['class' => 'Foo'],
			'b' => [],
		], 'databases');

		$this->assertCount(1, $diagnostics);
		$this->assertSame('databases.b.class', $diagnostics[0]->keyPath);
	}

	public function testDictRejectsNonStringKey(): void
	{
		$schema = Rule::dictOf(Rule::mixed());

		$diagnostics = SchemaValidator::validate($schema, [0 => 'x'], 'databases');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('databases', $diagnostics[0]->keyPath);
	}

	public function testListValidatesEveryItem(): void
	{
		$schema = Rule::listOf(Rule::struct(['class' => Rule::phpClass()], required: ['class']));

		$diagnostics = SchemaValidator::validate($schema, [
			['class' => 'Foo'],
			[],
		], 'plugins');

		$this->assertCount(1, $diagnostics);
		$this->assertSame('plugins[1].class', $diagnostics[0]->keyPath);
	}

	public function testListRejectsAssociativeArray(): void
	{
		$schema = Rule::listOf(Rule::mixed());

		$diagnostics = SchemaValidator::validate($schema, ['a' => 1, 'b' => 2], 'plugins');

		$this->assertSame('schema.wrong_type', $diagnostics[0]->code);
		$this->assertSame('plugins', $diagnostics[0]->keyPath);
	}
}
?>
