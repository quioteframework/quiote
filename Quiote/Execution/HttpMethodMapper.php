<?php
namespace Quiote\Execution;

/**
 * Central mapping from HTTP verbs to Quiote action method tokens.
 * Canonical values are lowercase: read, write, update, remove.
 * PUT maps to 'update' to match legacy Quiote routing conventions —
 * all validation XMLs use method="update" for PUT endpoints.
 */
final class HttpMethodMapper
{
    public static function toActionMethod(string $verb): string
    {
        $v = strtoupper($verb);
        return match($v) {
            'GET','HEAD','OPTIONS','TRACE' => 'read',
            'POST','PATCH' => 'write',
            'PUT' => 'update',
            'DELETE' => 'remove',
            default => 'read',
        };
    }
}
