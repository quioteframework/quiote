<?php

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Quiote\Action\Action;
use Quiote\Execution\ActionInitContext;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Request\WebRequest;
use Quiote\Response\WebResponse;
use Quiote\Testing\UnitTestCase;

/**
 * Action's attribute API is a facade over the init context's AttributeHolder.
 * Two things are worth pinning down: that each method really reaches the
 * holder (and with the right by-ref semantics), and that an action which has
 * no usable init context degrades to documented empty values instead of
 * throwing -- actions are routinely constructed before initialize() runs.
 */
class AttributeSampleAction extends Action
{
	public function execute(WebRequest $request): string
	{
		return 'Success';
	}
}

/**
 * An ActionInitContext that is deliberately NOT an AttributeHolder. The
 * interface does not require one (attribute methods are intentionally left off
 * it), so userland implementations like this are legal and must not make the
 * attribute facade blow up.
 */
class HolderlessInitContext implements ActionInitContext
{
	public function __construct(private readonly \Quiote\Context $ctx) {}
	public function getContext(): \Quiote\Context { return $this->ctx; }
	public function getModuleName(): string { return 'Foo'; }
	public function getActionName(): string { return 'Bar'; }
	public function getRequestMethod(): string { return 'read'; }
	public function getOutputTypeName(): string { return 'html'; }
	public function getRequestData(): ?\Psr\Http\Message\ServerRequestInterface { return null; }
	public function getResponse(): WebResponse { throw new \LogicException('not used in this test'); }
	public function setViewModuleName(?string $module): void {}
	public function setViewName(?string $name): void {}
	public function getViewModuleName(): ?string { return null; }
	public function getViewName(): ?string { return null; }
	public function getValidationManager() { return null; }
}

class ActionAttributesTest extends UnitTestCase
{
	private AttributeSampleAction $action;

	private LightweightActionInitContext $initContext;

	#[\Override]
	public function setUp(): void
	{
		$this->action = new AttributeSampleAction();
		$this->initContext = $this->makeInitContext();
		$this->action->initialize($this->initContext);
	}

	private function makeInitContext(): LightweightActionInitContext
	{
		return new LightweightActionInitContext(
			$this->getContext(),
			'Foo',
			'Bar',
			'read',
			'html',
			new WebRequest(),
			$this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse()
		);
	}

	// ---------------------------------------------------------------
	// Delegation to a real AttributeHolder init context.
	// ---------------------------------------------------------------

	public function testSetAndGetAttributeReachTheInitContext(): void
	{
		$this->action->setAttribute('colour', 'blue');

		$this->assertTrue($this->action->hasAttribute('colour'));
		$this->assertSame('blue', $this->action->getAttribute('colour'));
		// The holder, not a private copy on the action, is what was written to.
		$this->assertSame('blue', $this->initContext->getAttribute('colour'));
	}

	public function testGetAttributeReturnsDefaultForUnknownName(): void
	{
		$this->assertSame('fallback', $this->action->getAttribute('nope', 'fallback'));
		$this->assertFalse($this->action->hasAttribute('nope'));
	}

	public function testSetAttributesMergesOverExistingAttributes(): void
	{
		$this->action->setAttribute('first', 1);
		$this->action->setAttributes(['second' => 2, 'first' => 'replaced']);

		// The holder merges with `$new + $existing`: the supplied values win for
		// keys that collide, and they land in front of the untouched ones.
		$this->assertSame(['second' => 2, 'first' => 'replaced'], $this->action->getAttributes());
		$this->assertSame(['second', 'first'], $this->action->getAttributeNames());
	}

	public function testAppendAttributeBuildsAList(): void
	{
		$this->action->appendAttribute('log', 'one');
		$this->action->appendAttribute('log', 'two');

		$this->assertSame(['one', 'two'], $this->action->getAttribute('log'));
	}

	public function testRemoveAttributeReturnsTheValueAndDropsIt(): void
	{
		$this->action->setAttribute('doomed', 'value');

		$this->assertSame('value', $this->action->removeAttribute('doomed'));
		$this->assertFalse($this->action->hasAttribute('doomed'));
		$this->assertNull($this->action->removeAttribute('never-there'));
	}

	public function testClearAttributesEmptiesTheHolder(): void
	{
		$this->action->setAttributes(['a' => 1, 'b' => 2]);

		$this->action->clearAttributes();

		$this->assertSame([], $this->action->getAttributes());
		$this->assertFalse($this->action->hasAttribute('a'));
	}

	public function testSetAttributeByRefAliasesTheCallersVariable(): void
	{
		$value = 'before';
		$this->action->setAttributeByRef('aliased', $value);

		$value = 'after';

		// The point of the ByRef variant: the holder sees later writes to $value.
		$this->assertSame('after', $this->action->getAttribute('aliased'));
	}

	public function testAppendAttributeByRefAliasesTheAppendedElement(): void
	{
		$value = 'before';
		$this->action->appendAttributeByRef('list', $value);

		$value = 'after';

		$this->assertSame(['after'], $this->action->getAttribute('list'));
	}

	public function testSetAttributesByRefAliasesTheWholeArray(): void
	{
		$attributes = ['x' => 'before'];
		$this->action->setAttributesByRef($attributes);

		$attributes['x'] = 'after';

		$this->assertSame('after', $this->action->getAttribute('x'));
	}

	public function testGetAttributesIsReturnedByReference(): void
	{
		$this->action->setAttribute('counter', 1);

		$attributes =& $this->action->getAttributes();
		$attributes['counter'] = 2;

		$this->assertSame(2, $this->action->getAttribute('counter'));
	}

	// ---------------------------------------------------------------
	// No init context at all (action constructed but not initialized).
	// ---------------------------------------------------------------

	public function testUninitializedActionExposesNoContextOrInitContext(): void
	{
		$action = new AttributeSampleAction();

		$this->assertNull($action->getContext());
		$this->assertNull($action->getInitContext());
	}

	public function testUninitializedActionAttributeReadsReturnEmptyValues(): void
	{
		$action = new AttributeSampleAction();

		$this->assertSame('default', $action->getAttribute('anything', 'default'));
		$this->assertSame([], $action->getAttributeNames());
		$this->assertSame([], $action->getAttributes());
		$this->assertFalse($action->hasAttribute('anything'));
		$this->assertNull($action->removeAttribute('anything'));
	}

	public function testUninitializedActionAttributeWritesAreNoOps(): void
	{
		$action = new AttributeSampleAction();
		$byRef = 'value';

		// None of these may throw; there is simply nowhere to store the value.
		$action->setAttribute('a', 1);
		$action->appendAttribute('b', 2);
		$action->setAttributes(['c' => 3]);
		$action->setAttributeByRef('d', $byRef);
		$action->appendAttributeByRef('e', $byRef);
		$byRefArray = ['f' => 4];
		$action->setAttributesByRef($byRefArray);
		$action->clearAttributes();

		$this->assertSame([], $action->getAttributes());
	}

	public function testRegisterValidatorsIsANoOpWithoutAnInitContext(): void
	{
		$action = new AttributeSampleAction();

		$action->registerValidators();

		$this->assertNull($action->getInitContext());
	}

	// ---------------------------------------------------------------
	// Init context that is not an AttributeHolder.
	// ---------------------------------------------------------------

	public function testHolderlessInitContextFallsBackToEmptyValues(): void
	{
		$action = new AttributeSampleAction();
		$action->initialize(new HolderlessInitContext($this->getContext()));
		$byRef = 'value';

		$action->setAttribute('a', 1);
		$action->appendAttribute('b', 2);
		$action->setAttributes(['c' => 3]);
		$action->setAttributeByRef('d', $byRef);
		$action->appendAttributeByRef('e', $byRef);
		$action->clearAttributes();

		$this->assertSame('default', $action->getAttribute('a', 'default'));
		$this->assertSame([], $action->getAttributeNames());
		$this->assertSame([], $action->getAttributes());
		$this->assertFalse($action->hasAttribute('a'));
		$this->assertNull($action->removeAttribute('a'));
		// The context itself still resolves -- only the attribute facade degrades.
		$this->assertSame($this->getContext(), $action->getContext());
	}

	public function testHolderlessInitContextSetAttributesByRefIsANoOp(): void
	{
		$action = new AttributeSampleAction();
		$action->initialize(new HolderlessInitContext($this->getContext()));
		$attributes = ['x' => 'value'];

		$action->setAttributesByRef($attributes);

		$this->assertFalse($action->hasAttribute('x'));
	}

	// ---------------------------------------------------------------
	// Lifecycle.
	// ---------------------------------------------------------------

	#[IgnoreDeprecations]
	public function testGetContainerIsAnAliasOfGetInitContext(): void
	{
		$this->assertSame($this->initContext, $this->action->getContainer());
		$this->assertSame($this->action->getInitContext(), $this->action->getContainer());
	}

	public function testResetClearsRequestScopedState(): void
	{
		$this->action->setAttribute('leaky', 'value');

		$this->action->reset();

		// Nothing from the previous request may still be reachable: a worker
		// reuses the action instance across requests.
		$this->assertNull($this->action->getContext());
		$this->assertNull($this->action->getInitContext());
		$this->assertFalse($this->action->hasAttribute('leaky'));
		$this->assertSame([], $this->action->getAttributes());
	}

	public function testReinitializeAfterResetRebindsTheInitContext(): void
	{
		$this->action->reset();
		$fresh = $this->makeInitContext();

		$this->action->initialize($fresh);

		$this->assertSame($fresh, $this->action->getInitContext());
		$this->assertSame($this->getContext(), $this->action->getContext());
	}

	// ---------------------------------------------------------------
	// Defaults every action inherits.
	// ---------------------------------------------------------------

	public function testCacheabilityDefaultsToOff(): void
	{
		$this->assertFalse($this->action->isCacheable());
		$this->assertFalse($this->action->isCacheable('html'));
		$this->assertNull($this->action->cacheTtlSeconds());
		$this->assertNull($this->action->cacheTtlSeconds('html'));
	}

	public function testSimpleAndSecureDefaultToFalse(): void
	{
		$this->assertFalse($this->action->isSimple());
		$this->assertFalse($this->action->isSecure());
	}
}
