<?php
namespace Quiote\Execution;

use Quiote\Config\Config;

/**
 * Central mapping from HTTP verbs to Quiote action method tokens.
 * Canonical values are lowercase: read, write, update, remove.
 * This is the single source of truth — ActionResolver derives its
 * execute<Token>() dispatch from this same mapping so the two never diverge.
 *
 * The default mapping can be extended or overridden via the
 * `routing.http_method_map` setting. A bare <settings> block always lands
 * under the `core.` prefix, so setting `routing.http_method_map` needs a
 * `prefix` attribute on the wrapping <settings> element (settings.xml):
 *   <settings prefix="routing.">
 *     <setting name="http_method_map">
 *       <ae:parameter name="PATCH">write</ae:parameter>
 *       <ae:parameter name="LOCK">lock</ae:parameter>
 *     </setting>
 *   </settings>
 * or programmatically: Config::set('routing.http_method_map', ['LOCK' => 'lock']).
 * Keys are matched case-insensitively; values become the `execute<Token>()`
 * method name on the action (ucfirst-ed), so a custom token like 'lock' needs
 * a matching executeLock() method on any action that should handle it.
 */
final class HttpMethodMapper
{
    private const DEFAULT_MAP = [
        'GET' => 'read',
        'HEAD' => 'read',
        'OPTIONS' => 'read',
        'TRACE' => 'read',
        'POST' => 'write',
        'PUT' => 'update',
        'PATCH' => 'update',
        'DELETE' => 'remove',
    ];

    public static function toActionMethod(string $verb): string
    {
        return self::map()[strtoupper($verb)] ?? 'read';
    }

    /**
     * @return array<string,string>
     */
    private static function map(): array
    {
        $overrides = Config::getArray('routing.http_method_map', []);
        if ($overrides === []) {
            return self::DEFAULT_MAP;
        }
        $map = self::DEFAULT_MAP;
        foreach ($overrides as $verb => $token) {
            if (is_string($verb) && is_string($token) && $token !== '') {
                $map[strtoupper($verb)] = strtolower($token);
            }
        }
        return $map;
    }
}
