<?php
declare(strict_types=1);

namespace Quiote\Openapi;

/**
 * A route path parsed into the shape OpenAPI wants: a template whose
 * placeholders are bare `{name}`, plus what the placeholders' inline syntax
 * said about them.
 *
 * Symfony route paths may carry more than a name inside the braces --
 * `/orders/{id<\d+>}`, `/list/{page?1}`, `/{!locale}/about`, or all at once --
 * and none of that is legal in an OpenAPI path template, where a placeholder
 * is the parameter name and nothing else. Rather than drop the extra syntax,
 * it is lifted out: an inline requirement becomes a `pattern` on the
 * parameter's schema and an inline default makes the parameter optional, which
 * is exactly the information a spec consumer needs.
 * @since      1.2.5
 */
final readonly class RoutePathTemplate
{
    /**
     * @param string                $path         The path with every placeholder reduced to `{name}`.
     * @param list<string>          $variables    Placeholder names, in the order they appear.
     * @param array<string, string> $requirements Inline `<...>` requirements, keyed by placeholder name.
     * @param array<string, string> $defaults     Inline `?...` defaults, keyed by placeholder name (an empty string for a bare `{name?}`).
     */
    private function __construct(
        public string $path,
        public array $variables,
        public array $requirements,
        public array $defaults,
    ) {
    }

    public static function parse(string $path): self
    {
        $normalized = '';
        $variables = [];
        $requirements = [];
        $defaults = [];

        $length = strlen($path);
        $offset = 0;
        while ($offset < $length) {
            $open = strpos($path, '{', $offset);
            if ($open === false) {
                $normalized .= substr($path, $offset);
                break;
            }

            $close = self::closingBrace($path, $open);
            if ($close === null) {
                // Unbalanced braces: not a template we can read, so pass the
                // rest through untouched rather than truncate the path.
                $normalized .= substr($path, $offset);
                break;
            }

            $normalized .= substr($path, $offset, $open - $offset);
            [$name, $requirement, $default] = self::parsePlaceholder(substr($path, $open + 1, $close - $open - 1));

            if ($name === '') {
                // Nothing usable inside the braces; keep the raw token.
                $normalized .= substr($path, $open, $close - $open + 1);
                $offset = $close + 1;
                continue;
            }

            $normalized .= '{' . $name . '}';
            if (!in_array($name, $variables, true)) {
                $variables[] = $name;
            }
            if ($requirement !== null) {
                $requirements[$name] = $requirement;
            }
            if ($default !== null) {
                $defaults[$name] = $default;
            }

            $offset = $close + 1;
        }

        return new self($normalized, $variables, $requirements, $defaults);
    }

    /**
     * The `}` that closes the placeholder opened at $open, skipping any `}`
     * that belongs to an inline requirement's own quantifier (`{id<\d{1,3}>}`).
     */
    private static function closingBrace(string $path, int $open): ?int
    {
        $inRequirement = false;
        $braceDepth = 0;
        for ($i = $open + 1, $length = strlen($path); $i < $length; $i++) {
            $char = $path[$i];
            if ($char === '<') {
                $inRequirement = true;
            } elseif ($char === '>') {
                $inRequirement = false;
            } elseif ($char === '{') {
                $braceDepth++;
            } elseif ($char === '}') {
                if ($inRequirement || $braceDepth > 0) {
                    $braceDepth = max(0, $braceDepth - 1);
                    continue;
                }

                return $i;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [name, inline requirement, inline default]
     */
    private static function parsePlaceholder(string $token): array
    {
        // A leading '!' marks the parameter as important (it defeats trailing-slash
        // optionality); it says nothing about the parameter itself.
        $token = ltrim($token, '!');

        $requirement = null;
        $requirementStart = strpos($token, '<');
        if ($requirementStart !== false) {
            $requirementEnd = strrpos($token, '>');
            if ($requirementEnd !== false && $requirementEnd > $requirementStart) {
                $requirement = substr($token, $requirementStart + 1, $requirementEnd - $requirementStart - 1);
                $token = substr($token, 0, $requirementStart) . substr($token, $requirementEnd + 1);
            }
        }

        $default = null;
        $defaultStart = strpos($token, '?');
        if ($defaultStart !== false) {
            $default = substr($token, $defaultStart + 1);
            $token = substr($token, 0, $defaultStart);
        }

        return [trim($token), $requirement, $default];
    }
}
