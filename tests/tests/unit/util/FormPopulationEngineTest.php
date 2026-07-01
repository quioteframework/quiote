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
			$request->setParameter($key, $value);
		}

		FormPopulationConfig::seed($request, $engine->getDefaults());
		if($config) {
			FormPopulationConfig::merge($request, $config);
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