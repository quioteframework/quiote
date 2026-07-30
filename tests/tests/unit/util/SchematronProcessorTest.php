<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Exception\QuioteException;
use Quiote\Util\SchematronProcessor;

class SchematronProcessorTest extends PhpUnitTestCase
{
	public function testPrepareProcessorSetsScalarAndStringableParametersAsStrings(): void
	{
		$processor = new SchematronProcessor(['unused-chain-entry']);
		$processor->setParameter('count', 5);
		$processor->setParameter('label', new class implements \Stringable {
			public function __toString(): string { return 'a-label'; }
		});

		$xsltProcessor = new \XSLTProcessor();

		$this->invokeProtected($processor, 'prepareProcessor', [$xsltProcessor]);

		$this->assertSame('5', $xsltProcessor->getParameter('', 'count'));
		$this->assertSame('a-label', $xsltProcessor->getParameter('', 'label'));
	}

	public function testPrepareProcessorThrowsWhenParameterIsNotStringable(): void
	{
		$processor = new SchematronProcessor(['unused-chain-entry']);
		$processor->setParameter('bad', ['not', 'stringable']);

		$xsltProcessor = new \XSLTProcessor();

		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('Schematron processor parameters must be scalar or Stringable, got array.');
		$this->invokeProtected($processor, 'prepareProcessor', [$xsltProcessor]);
	}

	/** @param array<int, mixed> $args */
	private function invokeProtected(object $object, string $method, array $args): mixed
	{
		$ref = new \ReflectionMethod($object, $method);
		return $ref->invoke($object, ...$args);
	}
}
