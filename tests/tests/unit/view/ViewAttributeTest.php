<?php

use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;

class SampleAttributeView extends View
{
    public function execute(WebRequest $rd)
    {
    }
}

/**
 * Happy + failure path coverage for View's attribute accessor cluster.
 *
 * The contract under test: every accessor answers from one coherent store. Reads consult
 * the view's own attribute store first and fall back to the init context's holder; writes
 * land in the view's store whenever it exists. So a value written by any mutator is
 * observable through every reader, and $default is honoured for a name nobody set.
 */
class ViewAttributeTest extends UnitTestCase
{
    private function makeView(): View
    {
        $ctx = $this->getContext();
        $ctx->initialize();
        $controller = $ctx->getContainer()->get(\Quiote\Controller\Controller::class);
        $descriptor = new ActionDescriptor('Test', 'Test', 'GET', 'html', false);
        $init = new LightweightActionInitContext(
            $ctx,
            $descriptor->module,
            $descriptor->action,
            $descriptor->method,
            $descriptor->outputType,
            new WebRequest(),
            $controller->getGlobalResponse()
        );
        $view = new SampleAttributeView();
        $view->initialize($init);
        return $view;
    }

    public function testSetAttributeIsVisibleThroughEveryReader(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');

        $this->assertSame('bar', $view->getAttribute('foo'));
        $this->assertTrue($view->hasAttribute('foo'));
        $this->assertSame(['foo'], $view->getAttributeNames());
        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testGetAttributeReturnsDefaultForUnknownName(): void
    {
        $view = $this->makeView();

        $this->assertNull($view->getAttribute('nope'));
        $this->assertSame('fallback', $view->getAttribute('nope', 'fallback'));
        $this->assertSame(0, $view->getAttribute('nope', 0));
    }

    public function testGetAttributeDefaultIsNotUsedForAnExplicitNullValue(): void
    {
        $view = $this->makeView();
        $view->setAttribute('explicit', null);

        $this->assertTrue($view->hasAttribute('explicit'));
        $this->assertNull($view->getAttribute('explicit', 'fallback'));
    }

    public function testAppendAttributeIsVisibleThroughEveryReader(): void
    {
        $view = $this->makeView();
        $view->appendAttribute('list', 'first');
        $view->appendAttribute('list', 'second');

        $this->assertSame(['first', 'second'], $view->getAttribute('list'));
        $this->assertTrue($view->hasAttribute('list'));
        $this->assertSame(['list'], $view->getAttributeNames());
        $this->assertSame(['list' => ['first', 'second']], $view->getAttributes());
    }

    public function testAppendAttributePromotesAnExistingScalarToAList(): void
    {
        $view = $this->makeView();
        $view->setAttribute('mixed', 'scalar');
        $view->appendAttribute('mixed', 'appended');

        $this->assertSame(['scalar', 'appended'], $view->getAttribute('mixed'));
    }

    public function testSetAttributesMergesIntoTheSameStoreAsSetAttribute(): void
    {
        $view = $this->makeView();
        $view->setAttribute('via_set_attribute', 'x');
        $view->setAttributes(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $view->getAttribute('a'));
        $this->assertSame(2, $view->getAttribute('b'));
        $this->assertSame(
            ['via_set_attribute' => 'x', 'a' => 1, 'b' => 2],
            $view->getAttributes()
        );
    }

    public function testSetAttributeByRefTracksLaterWritesToTheSource(): void
    {
        $view = $this->makeView();
        $value = 'before';
        $view->setAttributeByRef('ref', $value);

        $value = 'after';

        $this->assertSame('after', $view->getAttribute('ref'));
    }

    public function testSetAttributesByRefTracksLaterWritesToTheSource(): void
    {
        $view = $this->makeView();
        $attributes = ['one' => 'before'];
        $view->setAttributesByRef($attributes);

        $attributes['one'] = 'after';

        $this->assertSame('after', $view->getAttribute('one'));
    }

    public function testAppendAttributeByRefTracksLaterWritesToTheSource(): void
    {
        $view = $this->makeView();
        $value = 'before';
        $view->appendAttributeByRef('list', $value);

        $value = 'after';

        $this->assertSame(['after'], $view->getAttribute('list'));
    }

    public function testClearAttributesEmptiesEveryReader(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');
        $view->appendAttribute('list', 'first');

        $view->clearAttributes();

        $this->assertSame([], $view->getAttributeNames());
        $this->assertSame([], $view->getAttributes());
        $this->assertFalse($view->hasAttribute('foo'));
        $this->assertFalse($view->hasAttribute('list'));
    }

    public function testRemoveAttributeRemovesFromEveryReaderAndReturnsTheValue(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');
        $view->appendAttribute('list', 'first');

        $removed = $view->removeAttribute('list');

        $this->assertSame(['first'], $removed);
        $this->assertFalse($view->hasAttribute('list'));
        $this->assertNull($view->getAttribute('list'));
        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testRemoveAttributeOnAnUnknownNameIsHarmless(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');

        $this->assertNull($view->removeAttribute('nope'));
        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testGetAttributeNamesIsEmptyBeforeAnyWrite(): void
    {
        $view = $this->makeView();

        $this->assertSame([], $view->getAttributeNames());
    }

    /**
     * A view that was never initialized has no store of its own, so the facade must
     * degrade to its defaults rather than erroring.
     */
    public function testUninitializedViewFallsBackToDefaults(): void
    {
        $view = new SampleAttributeView();

        $this->assertNull($view->getAttribute('foo'));
        $this->assertSame('fallback', $view->getAttribute('foo', 'fallback'));
        $this->assertFalse($view->hasAttribute('foo'));
        $this->assertSame([], $view->getAttributeNames());
        $this->assertSame([], $view->getAttributes());
        $this->assertNull($view->removeAttribute('foo'));
    }

    public function testResetClearsContext(): void
    {
        $view = $this->makeView();
        $view->reset();

        $this->assertNull($view->getContext());
    }
}
