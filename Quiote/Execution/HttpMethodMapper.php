<?php
namespace Quiote\Execution;

/**
 * Central mapping from HTTP verbs to Quiote action method tokens.
 * Canonical values are lowercase: read, write, update, remove.
 * This is the single source of truth — ActionResolver derives its
 * execute<Token>() dispatch from this same mapping so the two never diverge.
 */
final class HttpMethodMapper
{
    public static function toActionMethod(string $verb): string
    {
        $v = strtoupper($verb);
        return match($v) {
            'GET','HEAD','OPTIONS','TRACE' => 'read',
            'POST' => 'write',
            'PUT','PATCH' => 'update',
            'DELETE' => 'remove',
            default => 'read',
        };
    }
}
