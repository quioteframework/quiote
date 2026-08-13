<?php

use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\View\TemplateLayer;

class AccessorTestTemplateLayer extends TemplateLayer
{
	public function getResourceStreamIdentifier()
	{
		return null;
	}
}

/**
 * TemplateLayer's name/module/template accessors used to be dispatched
 * through __call() onto ParameterHolder's set/get/hasParameter(). They are
 * now real, typed methods; these tests cover both the happy path and the
 * failure path (a non-string value already sitting under the parameter key,
 * e.g. because it was set via setParameter()/the constructor array directly).
 */
class TemplateLayerAccessorsTest extends UnitTestCase
{
	/**
	 * @param      array<string, mixed> $parameters
	 */
	private function layer(array $parameters = []): AccessorTestTemplateLayer
	{
		return new AccessorTestTemplateLayer($parameters);
	}

	public function testNameIsNullByDefault(): void
	{
		$layer = $this->layer();

		$this->assertNull($layer->getName());
	}

	public function testSetNameThenGetNameRoundTrips(): void
	{
		$layer = $this->layer();

		$layer->setName('content');

		$this->assertSame('content', $layer->getName());
	}

	public function testGetNameRejectsNonStringParameter(): void
	{
		$layer = $this->layer(['name' => 42]);

		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('The "name" parameter must be a string.');

		$layer->getName();
	}

	public function testSetModuleThenGetModuleRoundTrips(): void
	{
		$layer = $this->layer();

		$layer->setModule('Orders');

		$this->assertSame('Orders', $layer->getModule());
	}

	public function testGetModuleRejectsNonStringParameter(): void
	{
		$layer = $this->layer(['module' => ['not', 'a', 'string']]);

		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('The "module" parameter must be a string.');

		$layer->getModule();
	}

	public function testSetTemplateThenGetTemplateRoundTrips(): void
	{
		$layer = $this->layer();

		$layer->setTemplate('Index');

		$this->assertSame('Index', $layer->getTemplate());
		$this->assertTrue($layer->hasTemplate());
	}

	public function testSetTemplateToNullClearsHasTemplate(): void
	{
		$layer = $this->layer(['template' => 'Index']);

		$layer->setTemplate(null);

		$this->assertNull($layer->getTemplate());
		$this->assertFalse($layer->hasTemplate());
	}

	public function testGetTemplateRejectsNonStringParameter(): void
	{
		$layer = $this->layer(['template' => false]);

		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('The "template" parameter must be a string.');

		$layer->getTemplate();
	}

	public function testHasTemplateIsFalseByDefault(): void
	{
		$layer = $this->layer();

		$this->assertFalse($layer->hasTemplate());
	}

	public function testRemoveTemplateClearsIt(): void
	{
		$layer = $this->layer(['template' => 'Index']);

		$layer->removeTemplate();

		$this->assertNull($layer->getTemplate());
		$this->assertFalse($layer->hasTemplate());
	}
}
