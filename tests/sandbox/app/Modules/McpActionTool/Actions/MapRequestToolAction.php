<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Actions;

use Mcp\Capability\Attribute\McpTool;
use Quiote\Action\Action;
use Quiote\Request\Attribute\Constraint\Email;
use Quiote\Request\Attribute\Constraint\StringLength;
use Quiote\Request\Attribute\MapRequest;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;

#[MapRequest]
final readonly class MapRequestToolContactDto
{
    public function __construct(
        #[StringLength(min: 2, max: 20)] public string $title,
        #[Email] public ?string $authorEmail = null,
    ) {
    }
}

/**
 * Regression fixture proving #[MapRequest]-derived validators feed the same
 * ActionToolScanner fluent-fallback path FluentValidatorAction exercises --
 * i.e. the "reuses the existing validator IR, which already maps to JSON
 * Schema for MCP" claim in FEATURE_GAPS.md item 3 holds in practice.
 */
#[Route('/mcp-action-tool-test/map-request', name: 'mcp_action_tool_test.map_request', methods: ['POST'], outputType: 'html')]
#[McpTool(name: 'map_request_via_action', description: 'Exercises #[MapRequest]-derived schema')]
class MapRequestToolAction extends Action
{
    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }

    public function executeWrite(WebRequest $rd, MapRequestToolContactDto $dto): string
    {
        return 'Success';
    }
}
