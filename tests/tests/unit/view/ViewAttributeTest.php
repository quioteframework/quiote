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
 * IMPORTANT DISCOVERED BUG: View::initialize() always populates $localAttributes
 * as a snapshot copy of the init context's attributes (never leaves it null).
 * setAttribute() and getAttributes() (the only two methods with a
 * "$localAttributes !== null" branch) read/write that snapshot. Every other
 * mutator (appendAttribute, setAttributeByRef, appendAttributeByRef,
 * setAttributes, setAttributesByRef) and every other individual reader
 * (getAttribute, hasAttribute, getAttributeNames, removeAttribute) instead
 * delegate straight to `$this->initContext` (a real, separate AttributeHolder
 * here), completely bypassing $localAttributes.
 *
 * The practical effect: setAttribute()'s writes are only visible via
 * getAttributes(), never via getAttribute()/hasAttribute(). And every other
 * mutator's writes are only visible via getAttribute()/hasAttribute()/
 * getAttributeNames(), never via getAttributes(). The two stores never merge.
 * This is a real, currently-shipping inconsistency; the tests below document
 * the ACTUAL behavior rather than silently "fixing" it, since changing it is
 * a behavioral decision outside the scope of adding coverage.
 */
class ViewAttributeTest extends UnitTestCase
{
    private function makeView(): View
    {
        $ctx = $this->getContext();
        $ctx->initialize();
        $controller = $ctx->getController();
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

    public function testGetAttributesReflectsValuesSetViaSetAttribute(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');

        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testGetAttributeDoesNotSeeValuesWrittenBySetAttribute(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');

        // Discovered inconsistency: setAttribute() writes into $localAttributes,
        // but getAttribute() reads from initContext -- two different stores.
        $this->assertNull($view->getAttribute('foo'));
        $this->assertSame('fallback', $view->getAttribute('foo', 'fallback'));
    }

    public function testHasAttributeDoesNotSeeValuesWrittenBySetAttribute(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');

        $this->assertFalse($view->hasAttribute('foo'));
    }

    public function testAppendAttributeIsVisibleViaGetAttributeButNotGetAttributes(): void
    {
        $view = $this->makeView();
        $view->appendAttribute('list', 'first');
        $view->appendAttribute('list', 'second');

        // appendAttribute() writes into initContext, so the individual accessor sees it...
        $this->assertSame(['first', 'second'], $view->getAttribute('list'));
        $this->assertTrue($view->hasAttribute('list'));
        $this->assertSame(['list'], $view->getAttributeNames());
        // ...but the bulk accessor still only reflects the (empty) $localAttributes snapshot.
        $this->assertSame([], $view->getAttributes());
    }

    public function testSetAttributesWritesIntoInitContextNotLocalStore(): void
    {
        $view = $this->makeView();
        $view->setAttribute('via_set_attribute', 'x');
        $view->setAttributes(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $view->getAttribute('a'));
        $this->assertSame(2, $view->getAttribute('b'));
        // The earlier setAttribute() call is untouched -- still only in $localAttributes.
        $this->assertSame(['via_set_attribute' => 'x'], $view->getAttributes());
    }

    public function testClearAttributesTargetsInitContextNotLocalStore(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');
        $view->appendAttribute('list', 'first');

        $view->clearAttributes();

        // clearAttributes() empties initContext's store (so 'list' is gone; an
        // empty AttributeHolder reports its name list as null, not [])...
        $this->assertNull($view->getAttributeNames());
        // ...but $localAttributes (where setAttribute() wrote 'foo') is untouched.
        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testRemoveAttributeTargetsInitContextNotLocalStore(): void
    {
        $view = $this->makeView();
        $view->setAttribute('foo', 'bar');
        $view->appendAttribute('list', 'first');

        $view->removeAttribute('list');

        $this->assertFalse($view->hasAttribute('list'));
        // The setAttribute() value survives untouched, since removeAttribute() never
        // looks at $localAttributes.
        $this->assertSame(['foo' => 'bar'], $view->getAttributes());
    }

    public function testGetAttributeNamesReflectsInitContextWrites(): void
    {
        $view = $this->makeView();
        $this->assertNull($view->getAttributeNames());

        $view->appendAttribute('list', 'first');

        $this->assertSame(['list'], $view->getAttributeNames());
    }

    public function testResetClearsContext(): void
    {
        $view = $this->makeView();
        $view->reset();

        $this->assertNull($view->getContext());
    }
}
