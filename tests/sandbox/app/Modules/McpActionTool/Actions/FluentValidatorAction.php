<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;

/**
 * Regression fixture for ActionToolScanner's fluent-ValidatorBuilder schema
 * derivation: declares its validators via registerWriteValidators() only,
 * with no {module}/Validate/{action}.xml file at all -- the convention every
 * documented example and the app's own real actions actually use. Before the
 * fix, ActionToolScanner only understood the XML convention, so this shape
 * always fell back to the permissive `properties: {}` schema.
 */
#[Route('/mcp-action-tool-test/fluent', name: 'mcp_action_tool_test.fluent', methods: ['POST'], outputType: 'html')]
#[McpTool(name: 'fluent_via_action', description: 'Exercises fluent-ValidatorBuilder schema derivation')]
class FluentValidatorAction extends Action
{
    #[\Override]
    public function isSimple(): bool { return false; }

    public function executeWrite(): string
    {
        return 'Success';
    }

    public function registerWriteValidators(): void
    {
        $initContext = $this->getInitContext();
        $context = $this->getContext();
        if ($initContext === null || $context === null) {
            throw new \RuntimeException('FluentValidatorAction requires an initialized Action context.');
        }

        $v = ValidatorBuilder::on(
            $initContext->getValidationManager(),
            $context,
        );
        $v->string('title', required: true)
            ->minLength(2)
            ->maxLength(20)
            ->error('Title must be between 2 and 20 characters long.');
        $v->email('author_email', required: false)
            ->error('Author email must be valid.');
    }
}
