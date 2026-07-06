<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpDiscovery\Mcp;

use Mcp\Capability\Attribute\McpTool;

/**
 * Test-only fixture for plain-class attribute discovery (as opposed to the
 * actions-as-tools bridge, which requires #[Route]): a class with no #[Route]
 * at all, decorated with #[McpTool] directly on the method, living under a
 * module's Mcp/ subdirectory -- the convention Quiote\Mcp\Compiler\McpDirectoryResolver
 * scans. Exercised by McpAttributeDiscoveryTest.
 */
final class GreeterTool
{
    #[McpTool(name: 'greet_via_discovery', description: 'Greets someone via plain-class attribute discovery')]
    public function greet(string $name): string
    {
        return "Hello from discovery, {$name}!";
    }
}
