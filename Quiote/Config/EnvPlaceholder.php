<?php

declare(strict_types=1);

namespace Quiote\Config;

use Quiote\Exception\ConfigurationException;
use Quiote\Support\Environment\Environment;
use Quiote\Util\Toolkit;

/**
 * The `%env(NAME)%` / `%env(NAME, fallback)%` placeholder: a configuration
 * value that comes from the process environment.
 *
 * Unlike a `%directive%`, which {@see Toolkit::expandDirectives()} resolves
 * while a configuration file is being compiled, this is resolved when the
 * compiled artifact is *loaded*. That difference is the whole point:
 *
 * - the compiled cache never holds the value, so a warmed cache baked into a
 *   container image carries the placeholder rather than the build machine's
 *   environment, and a secret does not land in a file on disk;
 * - changing the variable and restarting the process is enough to change the
 *   setting -- no recompilation, no cache invalidation, nothing to key the
 *   cache on.
 *
 * A placeholder standing alone as the whole value is literalized the same way
 * a configuration file's own literals are -- `true`/`off`/`42`/`0.5` become
 * bool/int/float -- so `Config::getBool()` and `Config::getInt()`, both of
 * which reject a string, work on it. A placeholder embedded in a longer string
 * (`https://%env(API_HOST)%/v1`) is substituted textually and the result stays
 * a string.
 *
 * The resolved text is never re-expanded: neither `%directive%` nor a further
 * `%env(...)%` inside a variable's value or a fallback means anything. What a
 * configuration value resolves to should not depend on data arriving from
 * outside the configuration.
 *
 * "The environment" is whatever {@see \Quiote\Support\Environment\Environment}'s reader answers
 * with, which covers both a variable the platform exported and one a dotenv bootstrap loaded into
 * `$_ENV` without calling `putenv()`. A placeholder does not care which of the two a deployment
 * used.
 *
 * This resolves compiled configuration declarations and nothing else. Never
 * hand it a request parameter, a header or any other client-supplied string:
 * the variable to read comes from the text it is given, so anything a client
 * can steer would turn it into a way to read the process's environment. The
 * `HTTP_` namespace is refused outright for the same reason -- see
 * {@see self::assertNotRequestControlled()}.
 *
 * @since      4.2.0
 */
final class EnvPlaceholder
{
    /**
     * The cheap test for "is a placeholder possibly in here", used to skip the
     * regex on the overwhelming majority of values that have none.
     */
    private const MARKER = '%env(';

    /**
     * `%env(NAME)%` or `%env(NAME, fallback)%`, without delimiters.
     *
     * The name is a C identifier, which is what a shell can export and every
     * environment this runs in accepts. A fallback runs to the closing paren,
     * so a fallback cannot itself contain `)`; anything that elaborate belongs
     * in the configuration file rather than in a placeholder's default.
     */
    private const BODY = '%env\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:,([^)]*))?\)%';

    /** {@see self::BODY} anywhere in a value. */
    private const PATTERN = '/' . self::BODY . '/';

    /** {@see self::BODY} as the whole value, which is what makes its type negotiable. */
    private const PATTERN_WHOLE = '/^' . self::BODY . '$/';

    /**
     * Whether $value contains anything that looks like a placeholder, at any
     * depth of a compiled declaration's arrays.
     *
     * "Looks like" rather than "is": a malformed `%env(...)%` answers true here
     * so that {@see resolve()} gets the chance to reject it by name, instead of
     * it being silently cached and applied as a literal string.
     */
    public static function contains(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::MARKER);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every placeholder in $value replaced by what the environment says, at any
     * depth of a compiled declaration's arrays.
     *
     * Array keys are left alone -- a setting's name is schema, not deployment
     * input -- as is any value that is not a string.
     *
     * @param      ?string $sourceRef The configuration file the value was compiled from, named in
     *                    any error raised here. This is the one thing the failure needs and the one
     *                    thing the loaded artifact still knows.
     * @throws     ConfigurationException If a variable is unset and has no fallback, or a
     *                                   placeholder is malformed.
     */
    public static function resolve(mixed $value, ?string $sourceRef = null): mixed
    {
        if (is_string($value)) {
            return self::resolveString($value, $sourceRef);
        }

        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = self::resolve($item, $sourceRef);
        }

        return $resolved;
    }

    /**
     * @throws     ConfigurationException
     */
    private static function resolveString(string $value, ?string $sourceRef): mixed
    {
        if (!str_contains($value, self::MARKER)) {
            return $value;
        }

        // Counted before substituting, not after: a variable's value is free to contain "%env("
        // itself, and a leftover marker in the *result* would then be mistaken for a syntax error in
        // the configuration file.
        $matches = preg_match_all(self::PATTERN, $value);
        if ($matches === false || $matches !== substr_count($value, self::MARKER)) {
            throw new ConfigurationException(sprintf(
                'Configuration value "%s" in "%s" contains a malformed environment placeholder. '
                . 'The syntax is %%env(NAME)%% or %%env(NAME, fallback)%%, where NAME is a letter or '
                . 'underscore followed by letters, digits or underscores, and a fallback may not contain ")".',
                $value,
                $sourceRef ?? '(unknown)'
            ));
        }

        if (preg_match(self::PATTERN_WHOLE, $value, $whole) === 1) {
            // The placeholder is the entire value, so the value's *type* can come from the
            // environment too -- "false" here means the bool, the way it would in the config file.
            return Toolkit::literalize(self::valueOf($whole, $sourceRef), expandDirectives: false);
        }

        $substituted = preg_replace_callback(
            self::PATTERN,
            fn(array $match): string => self::valueOf($match, $sourceRef),
            $value
        );
        if ($substituted === null) {
            throw new ConfigurationException(sprintf(
                'Failed to substitute the environment placeholders in configuration value "%s" in "%s": '
                . 'the regular expression substitution failed.',
                $value,
                $sourceRef ?? '(unknown)'
            ));
        }

        return $substituted;
    }

    /**
     * The environment's answer for one matched placeholder, or its fallback.
     *
     * @param      array<int, string> $match A {@see self::PATTERN} match: name in [1], fallback in [2] when present.
     * @throws     ConfigurationException If the name is one a request can forge, or the variable is
     *                                   unset and the placeholder has no fallback.
     */
    private static function valueOf(array $match, ?string $sourceRef): string
    {
        $name = $match[1];
        self::assertNotRequestControlled($name, $sourceRef);
        $value = Environment::instance()->get($name);
        if ($value !== false) {
            return $value;
        }

        // A fallback that is present but empty is a fallback: '%env(PREFIX,)%' deliberately means
        // "empty unless the environment says otherwise", which is why this tests the match group's
        // presence rather than its content.
        if (array_key_exists(2, $match)) {
            return trim($match[2]);
        }

        throw new ConfigurationException(sprintf(
            'Configuration file "%s" reads environment variable "%s", which is not set. Set it in the '
            . 'environment, or give the placeholder a fallback: %%env(%s, some-default)%%.',
            $sourceRef ?? '(unknown)',
            $name,
            $name
        ));
    }

    /**
     * Refuses a variable name an HTTP request can forge.
     *
     * `getenv()` does not read the process environment alone: under CGI and FastCGI it answers from
     * the SAPI's request environment too, where every request header arrives as `HTTP_<NAME>`. A
     * placeholder naming one of those would be configuration taken from whoever sent the request --
     * the same collision that made `HTTP_PROXY` into httpoxy -- and under PHP-FPM, where the
     * configuration is loaded per request, it would be re-read on every one of them.
     *
     * A deployment variable that genuinely belongs to the app is renamed out of the way
     * (`APP_HTTP_TIMEOUT` rather than `HTTP_TIMEOUT`); nothing else legitimately lives in that
     * namespace. Matched case-insensitively, because the lowercase spelling is read by other
     * libraries in the same process and is forgeable in exactly the same way.
     *
     * @throws     ConfigurationException If the name is in the request-controlled namespace.
     */
    private static function assertNotRequestControlled(string $name, ?string $sourceRef): void
    {
        if (stripos($name, 'HTTP_') !== 0) {
            return;
        }

        throw new ConfigurationException(sprintf(
            'Configuration file "%s" reads environment variable "%s", which a request can forge: under '
            . 'CGI and FastCGI every request header arrives in the environment as HTTP_<NAME>, so this '
            . 'would take configuration from the client. Rename the deployment variable out of the '
            . 'HTTP_ namespace, for example APP_%s.',
            $sourceRef ?? '(unknown)',
            $name,
            $name
        ));
    }
}
