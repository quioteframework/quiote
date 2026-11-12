<?php

use Quiote\Controller\Controller;
use Quiote\Exception\ViewException;
use Quiote\Execution\ViewFactory;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\ParameterHolder;
use Quiote\View\View;

/**
 * View::populateForm() records the values for one form on the current request, where
 * FormPopulationMiddleware reads them once the page is rendered.
 */
final class ViewPopulateFormTest extends UnitTestCase
{
    private ?RequestState $requestState = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->requestState = $this->getContext()->getContainer()->get(RequestState::class);
        $this->forgetPopulate();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->forgetPopulate();
        $this->requestState = null;
        parent::tearDown();
    }

    private function requestState(): RequestState
    {
        if ($this->requestState === null) {
            throw new \RuntimeException('setUp() did not run.');
        }
        return $this->requestState;
    }

    /** The current request is shared across tests in this process, so each starts and ends clean. */
    private function forgetPopulate(): void
    {
        if ($this->requestState === null) {
            return;
        }
        $current = $this->requestState->current();
        $this->requestState->publish($current->withoutAttribute('org.quiote.filter.FormPopulationFilter'));
    }

    private function view(): View
    {
        $controller = $this->getContext()->getContainer()->get(Controller::class);
        $controller->initializeModule('Cache');
        return (new ViewFactory($controller))->create('Cache', 'CacheSuccess', 'Cache', 'Cache', 'html', null, []);
    }

    /** @return array<string, mixed> */
    private function populated(): array
    {
        $populate = FormPopulationConfig::getScopedValue($this->requestState()->current(), 'populate');
        $this->assertIsArray($populate);
        return $populate;
    }

    public function testRecordsTheValuesForTheNamedForm(): void
    {
        $this->view()->populateForm('record', ['Name' => 'Stored name']);

        $populate = $this->populated();
        $this->assertSame(['record'], array_keys($populate));
        $this->assertInstanceOf(ParameterHolder::class, $populate['record']);
        $this->assertSame('Stored name', $populate['record']->getParameter('Name'));
    }

    public function testEachCallAddsAForm(): void
    {
        $view = $this->view();
        $view->populateForm('record', ['Name' => 'Stored name']);
        $view->populateForm('address', ['Street' => 'Main St']);

        $this->assertSame(['record', 'address'], array_keys($this->populated()));
    }

    public function testALaterCallForTheSameFormReplacesItsValues(): void
    {
        $view = $this->view();
        $view->populateForm('record', ['Name' => 'first', 'Notes' => 'kept?']);
        $view->populateForm('record', ['Name' => 'second']);

        $record = $this->populated()['record'];
        $this->assertInstanceOf(ParameterHolder::class, $record);
        $this->assertSame(['Name' => 'second'], $record->getParameters());
    }

    public function testAParameterHolderIsUsedAsGiven(): void
    {
        $holder = new ParameterHolder(['Name' => 'Stored name']);
        $this->view()->populateForm('record', $holder);

        $this->assertSame($holder, $this->populated()['record']);
    }

    public function testAPopulateSourceForEveryFormGivesWayToTheNamedForms(): void
    {
        $everyForm = FormPopulationConfig::merge($this->requestState()->current(), ['populate' => true]);
        $this->assertInstanceOf(WebRequest::class, $everyForm);
        $this->requestState()->publish($everyForm);

        $this->view()->populateForm('record', ['Name' => 'Stored name']);

        $this->assertSame(['record'], array_keys($this->populated()));
    }

    public function testAnEmptyFormIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->view()->populateForm('', ['Name' => 'Stored name']);
    }

    public function testAViewThatIsNotInitializedCannotPopulate(): void
    {
        $view = new class extends View {
            public function execute(\Quiote\Request\WebRequest $rd): ?string
            {
                return null;
            }
        };

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('cannot populate form "record" before it is initialized');
        $view->populateForm('record', ['Name' => 'Stored name']);
    }
}
