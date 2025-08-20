<?php
namespace Agavi\Execution;

/**
 * Central mapping from HTTP verbs to Agavi action method tokens.
 * Canonical values are lowercase: read, write, create, remove.
 * Historical semantics: PUT => create (distinct from POST => write).
 */
final class HttpMethodMapper
{
    public static function toActionMethod(string $verb): string
    {
        $v = strtoupper($verb);
        return match($v) {
            'GET','HEAD','OPTIONS','TRACE' => 'read',
            'POST','PATCH' => 'write',
            'PUT' => 'create',
            'DELETE' => 'remove',
            default => 'read',
        };
    }
}
