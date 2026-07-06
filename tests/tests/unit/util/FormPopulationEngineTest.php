<?php

use Quiote\Context;
use Quiote\Request\WebRequest;
use Quiote\Response\WebResponse;
use Quiote\Testing\UnitTestCase;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\FormPopulationEngine;
use Quiote\Validator\ValidationArgument;
use Quiote\Validator\ValidationError;
use Quiote\Validator\ValidationIncident;
use Quiote\Validator\ValidationReport;
use Quiote\Validator\Validator;
use Nyholm\Psr7\ServerRequest;

require_once __DIR__ . '/../../../lib/validator/DummyValidator.class.php';

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class FormPopulationEngineTest extends UnitTestCase
{
	private ?Context $context = null;

	public function setUp(): void
	{
		parent::setUp();
		$this->context = $this->getContext();
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->context = null;
	}

	public function testTextValuePopulation(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar']);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//input[@value="bar"]')->length);
	}

	public function testCheckboxValuePopulation(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="checkbox" name="foo" value="1"></form></body></html>';
		$content = $this->executeFormPopulationEngine($html, ['foo' => '1']);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//input[@checked]')->length);
	}

	public function testSelectValuePopulation(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><select name="foo"><option value="bar">bar</option></select></form></body></html>';
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar']);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//option[@value="bar" and @selected]')->length);
	}

	public function testFieldErrorMessage(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$config = [
			'field_error_messages' => [
				'self::*' => [
					'location'  => 'after',
					'container' => '<ul>${errorMessages}</ul>',
					'markup'    => '<li>${errorMessage}</li>',
				],
			],
			'validation_report' => $this->createValidationReport(['foo'], 'My error message'),
		];
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//input/following-sibling::ul')->length);
	}

	public function testErrorMessage(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$config = [
			'error_messages' => [
				'self::*' => [
					'location'  => 'before',
					'container' => '<ul>${errorMessages}</ul>',
					'markup'    => '<li>${errorMessage}</li>',
				],
			],
			'validation_report' => $this->createValidationReport(['foo'], 'My error message'),
		];
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config);
		$xpath = $this->loadXpath($content);
		$this->assertEquals('ul', $xpath->query('//form/*[1]')->item(0)->nodeName);
	}

	public function testFormsXpathSetting(): void
	{
		$html = '<!DOCTYPE html><html><body><input type="text" name="foo"></body></html>';
		$config = [
			'forms_xpath' => '//${htmlnsPrefix}body',
		];
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//input[@value="bar"]')->length);
	}

	public function testErrorCallbacksClosureHtml(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$config = [
			'error_messages' => [
				'self::*' => [
					'location'  => 'before',
					'container' => function($element, array $errorStrings, array $errors) {
						$html = '<ul>';
						foreach($errors as $error) {
							$html .= '<li>' . htmlspecialchars((string) $error->getMessage()) . '</li>';
						}
						$html .= '</ul>';
						return $html;
					},
				],
			],
			'validation_report' => $this->createValidationReport(['foo'], 'My error message'),
		];
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//ul/li')->length);
	}

	public function testErrorCallbacksCallableDomElement(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$config = [
			'error_messages' => [
				'self::*' => [
					'location'  => 'before',
					'container' => self::class . '::_errorCallback',
				],
			],
			'validation_report' => $this->createValidationReport(['foo'], 'My error message'),
		];
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//div')->length);
	}

	public function testWriteMethodAliasAllowsPost(): void
	{
		$html = '<!DOCTYPE html><html><body><form action="/"><input type="text" name="foo"></form></body></html>';
		$config = [
			'methods' => ['write'],
		];
		$psrRequest = new ServerRequest('POST', 'https://example.test/');
		$content = $this->executeFormPopulationEngine($html, ['foo' => 'bar'], $config, $psrRequest);
		$xpath = $this->loadXpath($content);
		$this->assertEquals(1, $xpath->query('//input[@value="bar"]')->length);
	}

	public function testIsPostFilterAlwaysReturnsTrue(): void
	{
		$engine = new FormPopulationEngine();
		$this->assertTrue($engine->isPostFilter());
	}

	public function testToUtf8ConvertsFromIso88591ByDefault(): void
	{
		$engine = new FormPopulationEngine();
		$latin1 = mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8');

		$result = $this->invokeProtected($engine, 'toUtf8', [$latin1]);

		$this->assertSame('café', $result);
	}

	public function testToUtf8RecursesIntoArrays(): void
	{
		$engine = new FormPopulationEngine();
		$latin1 = mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8');

		$result = $this->invokeProtected($engine, 'toUtf8', [['a' => $latin1, 'b' => $latin1]]);

		$this->assertSame(['a' => 'café', 'b' => 'café'], $result);
	}

	public function testToUtf8ConvertsFromAnArbitraryEncoding(): void
	{
		$engine = new FormPopulationEngine();
		$iso88592 = iconv('UTF-8', 'ISO-8859-2', 'čaj');

		$result = $this->invokeProtected($engine, 'toUtf8', [$iso88592, 'ISO-8859-2']);

		$this->assertSame('čaj', $result);
	}

	public function testFromUtf8ConvertsToIso88591ByDefault(): void
	{
		$engine = new FormPopulationEngine();

		$result = $this->invokeProtected($engine, 'fromUtf8', ['cafe']);
		if (!is_string($result)) {
			throw new \RuntimeException('Expected fromUtf8() to return a string.');
		}

		$this->assertSame('cafe', mb_convert_encoding($result, 'UTF-8', 'ISO-8859-1'));
	}

	public function testFromUtf8RecursesIntoArrays(): void
	{
		$engine = new FormPopulationEngine();

		$result = $this->invokeProtected($engine, 'fromUtf8', [['a' => 'cafe', 'b' => 'cafe']]);

		$this->assertSame(['a' => 'cafe', 'b' => 'cafe'], $result);
	}

	public function testFromUtf8ConvertsToAnArbitraryEncoding(): void
	{
		$engine = new FormPopulationEngine();

		$result = $this->invokeProtected($engine, 'fromUtf8', ['caj', 'ISO-8859-2']);
		if (!is_string($result)) {
			throw new \RuntimeException('Expected fromUtf8() to return a string.');
		}

		$this->assertSame('caj', iconv('ISO-8859-2', 'UTF-8', $result));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataNormalizeLibxmlLevel')]
	public function testNormalizeLibxmlLevel(mixed $value, bool $isIgnoreSetting, int|false $expected): void
	{
		$engine = new FormPopulationEngine();

		$result = $this->invokeProtected($engine, 'normalizeLibxmlLevel', [$value, $isIgnoreSetting]);

		$this->assertSame($expected, $result);
	}

	/** @return array<string, array{mixed, bool, int|false}> */
	public static function dataNormalizeLibxmlLevel(): array
	{
		return [
			'ignore=true maps to FATAL' => [true, true, LIBXML_ERR_FATAL],
			'ignore=false maps to NONE' => [false, true, LIBXML_ERR_NONE],
			'report=true maps to WARNING' => [true, false, LIBXML_ERR_WARNING],
			'report=false maps to false' => [false, false, false],
			'explicit int passes through' => [LIBXML_ERR_ERROR, true, LIBXML_ERR_ERROR],
			'string constant name is resolved' => ['LIBXML_ERR_WARNING', true, LIBXML_ERR_WARNING],
			'unrecognized value defaults (ignore=true)' => ['bogus', true, LIBXML_ERR_ERROR],
			'unrecognized value defaults (ignore=false)' => ['bogus', false, LIBXML_ERR_WARNING],
		];
	}

	/** @param array<int, mixed> $args */
	private function invokeProtected(object $object, string $method, array $args): mixed
	{
		$ref = new \ReflectionMethod($object, $method);
		return $ref->invoke($object, ...$args);
	}

	public static function _errorCallback($element, array $errorStrings, array $errors): \DOMElement
	{
		return new \DOMElement('div', implode(',', $errorStrings));
	}

	private function executeFormPopulationEngine(string $content, array $parameters, array $config = [], ?ServerRequest $psrRequest = null): string
	{
		$engine = new FormPopulationEngine();
		$engine->initialize($this->context);

		$psr = $psrRequest ?? new ServerRequest('POST', 'https://example.test/');
		$request = new WebRequest(
			$psr->getMethod(),
			$psr->getUri(),
			$psr->getHeaders(),
			$psr->getBody(),
			$psr->getProtocolVersion(),
			$psr->getServerParams()
		);
		$request->initialize($this->context);

		foreach($parameters as $key => $value) {
			$request = $request->setParameter($key, $value);
		}

		$seeded = FormPopulationConfig::seed($request, $engine->getDefaults());
		if ($seeded instanceof WebRequest) { $request = $seeded; }
		if($config) {
			$merged = FormPopulationConfig::merge($request, $config);
			if ($merged instanceof WebRequest) { $request = $merged; }
		}

		$response = new WebResponse();
		$response->initialize($this->context);
		$response->setOutputType($this->context->getController()->getOutputType());
		$response->setContent($content);

		$engine->populate($response, $request);
		$engine->reset();

		return (string) $response->getContent();
	}

	private function createValidationReport(array $fields, string $message): ValidationReport
	{
		$incident = new ValidationIncident(null, Validator::ERROR);
		$error = new ValidationError($message, 'test_error', $fields);
		$incident->addError($error);

		$report = new ValidationReport();
		$report->addIncident($incident);
		foreach($fields as $field) {
			$argument = $field instanceof ValidationArgument ? $field : new ValidationArgument($field);
			$report->addArgumentResult($argument, Validator::ERROR);
		}

		return $report;
	}

	private function loadXpath(string $content): \DOMXPath
	{
		$dom = new \DOMDocument();
		$dom->strictErrorChecking = false;
		$dom->recover = true;
		$dom->loadHTML($content);
		return new \DOMXPath($dom);
	}
}

?>