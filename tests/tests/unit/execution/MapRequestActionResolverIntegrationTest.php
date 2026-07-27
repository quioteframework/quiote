<?php

use Quiote\Action\Action;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Request\Attribute\Constraint\Email;
use Quiote\Request\Attribute\Constraint\StringLength;
use Quiote\Request\Attribute\MapRequest;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;

#[MapRequest]
final readonly class MapRequestIntegrationContactDto
{
    public function __construct(
        #[StringLength(min: 2, max: 20)] public string $title,
        #[Email] public ?string $authorEmail = null,
    ) {
    }
}

class MapRequestIntegrationAction extends Action
{
    public ?MapRequestIntegrationContactDto $received = null;

    public function executeWrite(WebRequest $rd, MapRequestIntegrationContactDto $dto): string
    {
        $this->received = $dto;
        return 'Success';
    }
}

/**
 * End-to-end coverage of the two production seams #[MapRequest] wires
 * into: Action::registerValidators() (derives validators from the DTO's
 * constraint attributes) and ActionResolver::execute() (constructs and
 * injects the DTO once validation has passed). Exercises the same
 * LightweightActionInitContext + ValidationManager + ActionResolver
 * objects the real middleware pipeline uses (see ValidationMiddleware /
 * DispatchMiddleware), without needing full HTTP routing.
 */
class MapRequestActionResolverIntegrationTest extends UnitTestCase
{
    private function initAction(MapRequestIntegrationAction $action, WebRequest $request): LightweightActionInitContext
    {
        $controller = $this->getContext()->getController();
        $descriptor = new ActionDescriptor('MapRequestDto', 'Create', 'Write', 'html', false);
        $initCtx = new LightweightActionInitContext(
            $this->getContext(),
            $descriptor->module,
            $descriptor->action,
            $descriptor->method,
            $descriptor->outputType,
            $request,
            $controller->getGlobalResponse()
        );
        $action->initialize($initCtx);
        return $initCtx;
    }

    private function requireValidationManager(LightweightActionInitContext $initCtx): ValidationManager
    {
        $vm = $initCtx->getValidationManager();
        if (!$vm instanceof ValidationManager) {
            $this->fail('Expected a ValidationManager instance.');
        }
        return $vm;
    }

    public function testValidRequestConstructsAndInjectsDtoIntoActionMethod(): void
    {
        $action = new MapRequestIntegrationAction();
        $request = $this->newWebRequest(['title' => 'Hello world', 'authorEmail' => 'a@example.com']);
        $initCtx = $this->initAction($action, $request);

        $action->registerValidators();
        $vm = $this->requireValidationManager($initCtx);
        $this->assertTrue($vm->execute($request));

        $validatedRequest = $this->getContext()->getRequest();
        $this->assertInstanceOf(WebRequest::class, $validatedRequest);

        $resolver = $this->getContext()->getActionResolver();
        $view = $resolver->execute($action, 'Write', $validatedRequest);

        $this->assertSame('Success', $view);
        $this->assertNotNull($action->received);
        $this->assertSame('Hello world', $action->received->title);
        $this->assertSame('a@example.com', $action->received->authorEmail);
    }

    public function testInvalidRequestFailsValidationBeforeDispatch(): void
    {
        $action = new MapRequestIntegrationAction();
        // Title 'A' is one character, violating StringLength(min: 2).
        $request = $this->newWebRequest(['title' => 'A']);
        $initCtx = $this->initAction($action, $request);

        $action->registerValidators();
        $vm = $this->requireValidationManager($initCtx);
        $this->assertFalse($vm->execute($request));

        // Mirrors production: DispatchMiddleware never calls ActionResolver
        // when validation has failed, so the action's execute*() method --
        // and therefore the DTO construction -- never runs.
        $this->assertNull($action->received);
    }
}
