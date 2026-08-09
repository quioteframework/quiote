<?php
use Nyholm\Psr7\ServerRequest;
use Quiote\Controller\Controller;
use Quiote\Exception\UnvalidatedParameterAccessException;
use Quiote\Execution\SlotDispatcher;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * Covers the parameter overlay SlotDispatcher applies around a slot dispatch.
 *
 * A slot's parameters are visible for that dispatch only. Undoing the overlay
 * must put back exactly what the validated parent request exposed -- so a name
 * the parent never exposed is removed rather than left behind, and no value
 * becomes readable that validation had not already whitelisted.
 */
class SlotDispatcherParameterOverlayTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $controller = $this->getContext()->getContainer()->get(Controller::class);
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache', 'Cache');
    }

    private function requestState(): RequestState
    {
        return $this->getContext()->getContainer()->get(RequestState::class);
    }

    private function currentRequest(): WebRequest
    {
        return $this->requestState()->current();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dispatchSlotWith(array $parameters): void
    {
        $controller = $this->getContext()->getContainer()->get(Controller::class);
        $dispatcher = new SlotDispatcher($controller);
        $request = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());

        $dispatcher->dispatch($request, 'Cache', 'Cache', $parameters);
    }

    public function testOverlayRestoresTheValueTheParentRequestExposed(): void
    {
        $this->requestState()->publish(
            $this->currentRequest()->setParameter('overlaid', 'parent-value')
        );

        $this->dispatchSlotWith(['overlaid' => 'slot-value']);

        $this->assertSame(
            'parent-value',
            $this->currentRequest()->getParameter('overlaid'),
            "the parent's own value must be back once the slot has rendered"
        );
    }

    public function testOverlayIsRemovedWhenTheParentNeverExposedThatName(): void
    {
        $this->assertFalse(
            $this->currentRequest()->hasParameter('slotOnly'),
            'precondition: the parent request must not expose this name'
        );

        $this->dispatchSlotWith(['slotOnly' => 'slot-value']);

        $this->assertFalse(
            $this->currentRequest()->hasParameter('slotOnly'),
            'a parameter that belonged to the slot alone must not outlive its dispatch'
        );
    }

    /**
     * The overlay must never widen the strict-validation whitelist. Restoring a
     * name the parent did not expose would make raw client input readable
     * through getParameter() for everything rendered after the slot.
     */
    public function testOverlayDoesNotWhitelistAParameterTheParentDidNotHave(): void
    {
        $this->dispatchSlotWith(['neverValidated' => 'slot-value']);

        $this->expectException(UnvalidatedParameterAccessException::class);
        $this->currentRequest()->getParameter('neverValidated');
    }

    public function testUnrelatedParentParametersSurviveASlotDispatch(): void
    {
        $this->requestState()->publish(
            $this->currentRequest()->setParameter('untouched', 'still-here')
        );

        $this->dispatchSlotWith(['overlaid' => 'slot-value']);

        $this->assertSame(
            'still-here',
            $this->currentRequest()->getParameter('untouched'),
            'a parameter the slot never overlaid must be unaffected'
        );
    }
}
