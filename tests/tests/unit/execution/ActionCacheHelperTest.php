<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ActionCacheHelper;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\SecurityDecision;

class ActionCacheHelperTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContext()->getController()->initializeModule('Cache');
        $this->getContext()->getController()->createActionInstance('Cache', 'Cache');
    }

    private function makeDescriptor(): ActionDescriptor
    {
        return new ActionDescriptor('Cache', 'Cache', 'GET', 'html', true);
    }

    private function makeActionInstance(): \Quiote\Action\Action
    {
        return $this->getContext()->getController()->createActionInstance('Cache', 'Cache');
    }

    public function testBuildContextFromPayloadHydratesStateAndContext(): void
    {
        $state = new ExecutionState();
        $payload = [
            'view_module' => 'Cache',
            'view_name' => 'CacheSuccess',
            'response_content' => 'hello world',
            'action_attributes' => ['foo' => 'bar'],
            'state' => [
                'validationDecision' => 'passed',
                'securityDecision' => SecurityDecision::Allow,
            ],
        ];

        $ctx = ActionCacheHelper::buildContextFromPayload(
            $payload,
            $this->makeDescriptor(),
            $state,
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );

        $this->assertTrue($state->cacheHit);
        $this->assertSame('Cache', $state->viewModule);
        $this->assertSame('CacheSuccess', $state->viewName);
        $this->assertTrue($state->validationDecision?->isPassed());
        $this->assertSame(SecurityDecision::Allow, $state->securityDecision);
        $this->assertSame('hello world', $ctx->content);
        $this->assertSame(['foo' => 'bar'], $ctx->actionAttributes);
        $this->assertSame('Cache', $ctx->viewModuleName);
        $this->assertSame('CacheSuccess', $ctx->viewName);
    }

    public function testBuildContextFromPayloadUsesContentOverride(): void
    {
        $state = new ExecutionState();
        $ctx = ActionCacheHelper::buildContextFromPayload(
            ['response_content' => 'ignored'],
            $this->makeDescriptor(),
            $state,
            $this->makeActionInstance(),
            $this->getContext()->getRequest(),
            'override content'
        );

        $this->assertSame('override content', $ctx->content);
    }

    public function testBuildContextFromPayloadThrowsWhenActionInstanceIsNull(): void
    {
        $this->expectException(\RuntimeException::class);
        ActionCacheHelper::buildContextFromPayload(
            [],
            $this->makeDescriptor(),
            new ExecutionState(),
            null,
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonStringViewModule(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['view_module' => 123],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonStringViewName(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['view_name' => ['nope']],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonArrayValidationErrors(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['state' => ['validationDecision' => 'failed', 'validationErrors' => 'not-an-array']],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsInvalidSecurityDecision(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['state' => ['securityDecision' => 'allow']],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonStringResponseContent(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['response_content' => ['not', 'a', 'string']],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonArrayActionAttributes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['action_attributes' => 'not-an-array'],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }

    public function testBuildContextFromPayloadRejectsNonStringActionAttributeKeys(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ActionCacheHelper::buildContextFromPayload(
            ['action_attributes' => [0 => 'value']],
            $this->makeDescriptor(),
            new ExecutionState(),
            $this->makeActionInstance(),
            $this->getContext()->getRequest()
        );
    }
}
