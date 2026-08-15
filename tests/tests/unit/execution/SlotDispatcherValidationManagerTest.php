<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\SlotDispatcher;
use Quiote\Middleware\SlotMiddleware;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;

/**
 * Regression test: SlotDispatcher must hand ViewFactory::create() the live
 * ValidationManager populated during this dispatch's validate() call, not
 * let it fall through to ViewFactory's default null (which resolves a fresh,
 * empty SCOPE_TRANSIENT instance from the container). Otherwise a slot's
 * error view silently reports no validation errors at all.
 */
class SlotDispatcherValidationManagerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->initializeModule('Snapshot');
    }

    public function testErrorViewSeesThisRequestsValidationIncidents(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $dispatcher = new SlotDispatcher($controller);
        $req = (new ServerRequest('POST', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());

        $content = $dispatcher->dispatch($req, 'Snapshot', 'SlotValidationEchoAction', ['name' => 'ab']);

        $this->assertNotSame('', $content, 'Error view must render content');
        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'Error view must render valid Problem Details JSON: ' . $content);
        $this->assertArrayHasKey('errors', $decoded);
        $errors = $decoded['errors'];
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('name', $errors, 'Live validation manager must expose the "name" field incident');
        $nameErrors = $errors['name'];
        $this->assertIsArray($nameErrors);
        $nameErrorStrings = [];
        foreach ($nameErrors as $nameError) {
            $this->assertIsString($nameError);
            $nameErrorStrings[] = $nameError;
        }
        $this->assertStringContainsString(
            'Name must be at least 3 characters long.',
            implode(' ', $nameErrorStrings),
            'Slot must see this request\'s own validation error, not an empty transient manager'
        );
    }

    public function testValidSubmissionRunsSuccessPathWithoutValidationErrors(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $dispatcher = new SlotDispatcher($controller);
        $req = (new ServerRequest('POST', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());

        $content = $dispatcher->dispatch($req, 'Snapshot', 'SlotValidationEchoAction', ['name' => 'abcdef']);

        $this->assertSame('Success', $content);
    }
}
