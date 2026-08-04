<?php

namespace Quiote;

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Logging\Log;

/**
 * Owns the live {@see Context} instances -- one per named profile.
 *
 * A context profile is a configuration identity (web, console, a named profile), and something
 * has to guarantee there is exactly one context per identity for the life of the process. That
 * guarantee was a static map on Context itself, which made Context responsible both for being a
 * context and for knowing about all the others. This is the second half, on its own.
 *
 * Constructor-inject this to reach a context by name. {@see Context::getInstance()} answers from
 * {@see shared()}, so both reach the same instances.
 *
 * @since      4.0.0
 */
final class ContextRegistry
{
    /**
     * @var        array<string, Context> Live contexts, keyed by lower-cased profile name.
     */
    private array $instances = [];

    /**
     * @var        ?self The process-wide registry {@see Context::getInstance()} answers from.
     */
    private static ?self $shared = null;

    /**
     * The process-wide registry. Built on first use.
     *
     * @since      4.0.0
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Retrieve the context for a profile, initializing it on first request.
     *
     * @param      ?string $profile A profile name, or null for `core.default_context`.
     * @param      ?class-string<Context> $fallbackClass The implementation to build when
     *             `core.context_implementation` is unset. Null means {@see Context} itself. This
     *             is how {@see Context::getInstance()} keeps its late-static-binding behaviour:
     *             `SubContext::getInstance()` builds a SubContext without needing the setting.
     * @throws     \Exception Whatever construction or initialize() raised. Bootstrap runs before
     *             any PSR-15 pipeline exists, so there is no ErrorHandlingMiddleware to hand a
     *             failure to; it is logged here and propagated rather than rendered.
     * @since      4.0.0
     */
    public function get(?string $profile = null, ?string $fallbackClass = null): Context
    {
        try {
            $name = $this->normalize($profile);
            if (!isset($this->instances[$name])) {
                $context = $this->create($name, $fallbackClass);
                // Registered before initialize() runs, so a context that reaches back for its own
                // profile during initialization finds itself rather than recursing into a second
                // instance.
                $this->instances[$name] = $context;
                $context->initialize();
            }

            return $this->instances[$name];
        } catch (\Exception $e) {
            Log::for(self::class)->error(
                'ContextRegistry::get() failed: ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine(),
            );
            throw $e;
        }
    }

    /**
     * Whether a profile has a live context. Does not create one -- this is for callers that need
     * to act on what exists rather than bring it into being.
     *
     * @since      4.0.0
     */
    public function has(?string $profile = null): bool
    {
        return isset($this->instances[$this->normalize($profile)]);
    }

    /**
     * The profile names of every live context.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    public function names(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Reset every live context at a worker request boundary.
     *
     * Every context is reset, not just $preferred: a context other than the one that served the
     * request still holds request-scoped state of its own -- its session bag and its user -- and
     * carrying those across a request boundary is a cross-user authentication leak rather than a
     * stale-data annoyance.
     *
     * $preferred only decides what goes *first*, so the context that actually served the request
     * is cleared even when some other context's reset() throws. Each is guarded for the same
     * reason. Deliberately iterates what already exists rather than going through {@see get()},
     * which would instantiate a context at the request boundary just to reset it.
     *
     * @param      ?string $preferred The profile that served the request, reset first.
     * @since      4.0.0
     */
    public function resetAll(?string $preferred = null): void
    {
        $ordered = [];
        if ($preferred !== null && $preferred !== '') {
            $name = $this->normalize($preferred);
            if (isset($this->instances[$name])) {
                $ordered[$name] = $this->instances[$name];
            }
        }
        foreach ($this->instances as $name => $context) {
            $ordered[$name] ??= $context;
        }

        foreach ($ordered as $name => $context) {
            try {
                $context->reset();
            } catch (\Throwable $e) {
                Log::for(self::class)->error(
                    "[ContextRegistry::resetAll] reset of context '$name' failed: "
                    . $e->getMessage(),
                );
            }
        }
    }

    /**
     * Forget every live context without resetting them.
     *
     * For tests that need a clean process-level slate. Not a request-boundary operation --
     * {@see resetAll()} is, and dropping contexts there would rebuild the whole configuration on
     * every request.
     *
     * @since      4.0.0
     */
    public function clear(): void
    {
        $this->instances = [];
    }

    /**
     * Construct the configured context implementation for a profile.
     *
     * @param      ?class-string<Context> $fallbackClass
     * @throws     QuioteException When `core.context_implementation` does not name a Context.
     * @since      4.0.0
     */
    private function create(string $profile, ?string $fallbackClass = null): Context
    {
        $class = Config::getString('core.context_implementation', $fallbackClass ?? Context::class);
        if (!is_a($class, Context::class, true)) {
            throw new QuioteException(sprintf(
                'core.context_implementation "%s" does not extend %s',
                $class,
                Context::class,
            ));
        }

        return $class::create($profile);
    }

    /**
     * Profile names are matched case-insensitively, so 'Web' and 'web' are one context rather
     * than two configurations of the same application.
     *
     * @since      4.0.0
     */
    private function normalize(?string $profile): string
    {
        return strtolower($profile ?? Config::getString('core.default_context'));
    }
}
