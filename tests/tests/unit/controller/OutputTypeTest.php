<?php

use Quiote\Controller\OutputType;
use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Renderer\PhpRenderer;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Deliberately does NOT implement Quiote\Renderer\Renderer. Used to exercise
 * the failure path of OutputType::getRenderer() when a configured renderer
 * class doesn't satisfy the Renderer contract.
 */
class NotARendererRenderer
{
}

#[IsolationEnvironment('testing')]
class OutputTypeTest extends PhpUnitTestCase
{
	public function testGetRendererBuildsAndCachesAReusableRenderer(): void
	{
		$outputType = new OutputType();
		$outputType->initialize(
			Context::getInstance(),
			[],
			'html',
			[
				'php' => [
					'class' => PhpRenderer::class,
					'instance' => null,
					'parameters' => [],
				],
			],
			'php',
			[],
			null,
		);

		$renderer = $outputType->getRenderer();
		$this->assertInstanceOf(PhpRenderer::class, $renderer);

		// PhpRenderer implements IReusableRenderer, so the second call must
		// return the exact same cached instance rather than building a new one.
		$this->assertSame($renderer, $outputType->getRenderer('php'));
	}

	public function testGetRendererAppliesConfiguredExtensionAsDefaultExtensionParameter(): void
	{
		$outputType = new OutputType();
		$outputType->initialize(
			Context::getInstance(),
			[],
			'html',
			[
				'php' => [
					'class' => PhpRenderer::class,
					'instance' => null,
					'parameters' => [],
					'extension' => '.tpl.php',
				],
			],
			'php',
			[],
			null,
		);

		$renderer = $outputType->getRenderer();
		$this->assertInstanceOf(PhpRenderer::class, $renderer);
		$this->assertSame('.tpl.php', $renderer->getDefaultExtension());
	}

	public function testGetRendererReturnsNullWhenNoRenderersAreConfigured(): void
	{
		$outputType = new OutputType();
		$outputType->initialize(Context::getInstance(), [], 'html', [], null, [], null);

		$this->assertNull($outputType->getRenderer());
	}

	public function testGetRendererThrowsForUnknownRendererName(): void
	{
		$outputType = new OutputType();
		$outputType->initialize(
			Context::getInstance(),
			[],
			'html',
			[
				'php' => [
					'class' => PhpRenderer::class,
					'instance' => null,
					'parameters' => [],
				],
			],
			'php',
			[],
			null,
		);

		$this->expectException(QuioteException::class);
		$outputType->getRenderer('nonexistent');
	}

	public function testGetRendererThrowsWhenConfiguredClassDoesNotImplementRenderer(): void
	{
		$outputType = new OutputType();
		$outputType->initialize(
			Context::getInstance(),
			[],
			'html',
			[
				'broken' => [
					'class' => NotARendererRenderer::class,
					'instance' => null,
					'parameters' => [],
				],
			],
			'broken',
			[],
			null,
		);

		$this->expectException(QuioteException::class);
		$outputType->getRenderer();
	}
}
