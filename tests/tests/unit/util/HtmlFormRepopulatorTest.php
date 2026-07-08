<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\HtmlFormRepopulator;
use Quiote\Validator\Validator;
use Quiote\Validator\ValidationError;
use Quiote\Validator\ValidationIncident;
use Quiote\Validator\ValidationReport;

class HtmlFormRepopulatorTest extends PhpUnitTestCase
{
	public function testEmptyHtmlIsReturnedUnchanged(): void
	{
		$this->assertSame('', HtmlFormRepopulator::repopulate('', ['foo' => 'bar']));
	}

	public function testRepopulatesTextInputWithMatchingParameter(): void
	{
		$html = '<html><body><form><input type="text" name="foo" value="" /></form></body></html>';

		$out = HtmlFormRepopulator::repopulate($html, ['foo' => 'bar']);

		$this->assertStringContainsString('name="foo"', $out);
		$this->assertStringContainsString('value="bar"', $out);
	}

	public function testRepopulatesCheckboxWithMatchingValue(): void
	{
		$html = '<html><body><form><input type="checkbox" name="foo" value="yes" /></form></body></html>';

		$out = HtmlFormRepopulator::repopulate($html, ['foo' => 'yes']);

		$this->assertStringContainsString('checked', $out);
	}

	public function testLeavesCheckboxUncheckedWhenValueDoesNotMatch(): void
	{
		$html = '<html><body><form><input type="checkbox" name="foo" value="yes" /></form></body></html>';

		$out = HtmlFormRepopulator::repopulate($html, ['foo' => 'no']);

		$this->assertStringNotContainsString('checked', $out);
	}

	public function testSelectsMatchingOption(): void
	{
		$html = '<html><body><form><select name="foo"><option value="a">A</option><option value="b">B</option></select></form></body></html>';

		$out = HtmlFormRepopulator::repopulate($html, ['foo' => 'b']);

		$this->assertMatchesRegularExpression('/<option[^>]*value="b"[^>]*selected/', $out);
	}

	public function testIgnoresInputsWithoutAMatchingParameter(): void
	{
		$html = '<html><body><form><input type="text" name="foo" value="" /></form></body></html>';

		$out = HtmlFormRepopulator::repopulate($html, ['unrelated' => 'value']);

		$this->assertStringNotContainsString('value="value"', $out);
	}

	public function testInsertsErrorMessagesAsListWhenFormIsPresent(): void
	{
		$html = '<html><body><form><input type="text" name="foo" /></form></body></html>';
		$report = $this->createValidationReport('the field is required');

		$out = HtmlFormRepopulator::repopulate($html, [], $report);

		$this->assertStringContainsString('<ul>', $out);
		$this->assertStringContainsString('the field is required', $out);
	}

	public function testDoesNotCrashWhenErrorsArePresentButNoFormExists(): void
	{
		$html = '<html><body><div>no form here</div></body></html>';
		$report = $this->createValidationReport('the field is required');

		$out = HtmlFormRepopulator::repopulate($html, [], $report);

		$this->assertStringContainsString('no form here', $out);
		$this->assertStringNotContainsString('<ul>', $out);
	}

	public function testDoesNotThrowWhenParsingGarbageInput(): void
	{
		// DOMDocument::loadHTML() synthesizes an <html><body> wrapper around
		// virtually any input, so this mainly guards against a fatal error
		// when the parsed document is otherwise unusable.
		$out = HtmlFormRepopulator::repopulate('not even html', ['foo' => 'bar']);

		$this->assertStringStartsWith('<!DOCTYPE html>', $out);
	}

	private function createValidationReport(string $message): ValidationReport
	{
		$incident = new ValidationIncident(null, Validator::ERROR);
		$error = new ValidationError($message, 'test_error', []);
		$incident->addError($error);

		$report = new ValidationReport();
		$report->addIncident($incident);

		return $report;
	}
}
