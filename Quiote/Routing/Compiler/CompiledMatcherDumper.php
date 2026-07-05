<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Quiote\Config\Config;
use Quiote\Support\Compiler\EmittedArtifact;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;

/**
 * Back-end that dumps a RouteCollection to a Symfony CompiledUrlMatcher blob
 * (the same technique Symfony's own router uses: a static-prefix tree + merged
 * regexes emitted as plain PHP, so matching is opcache-native instead of
 * iterating/compiling the collection at runtime). Slots in alongside
 * RouteCollectionBuilder as a sibling back-end over the routing IR.
 *
 * Staleness safety: the artifact is written to a path keyed by a signature of
 * the route definitions. If the routes change and the dump is not regenerated,
 * the live signature no longer matches any file on disk, so Routing silently
 * falls back to the dynamic UrlMatcher — a stale dump can never route a request
 * to the wrong action.
 * @since      1.0.0
 */
final class CompiledMatcherDumper
{
    /**
     * Short, stable hash of everything about the collection that affects
     * matching (name, path, methods, host, requirements, defaults, condition),
     * in collection order (order is significant for same-path routes).
     */
    public static function signature(RouteCollection $routes): string
    {
        $parts = [];
        foreach ($routes->all() as $name => $route) {
            $parts[] = implode('|', [
                $name,
                $route->getPath(),
                implode(',', $route->getMethods()),
                (string) $route->getHost(),
                json_encode($route->getRequirements()),
                json_encode($route->getDefaults()),
                (string) $route->getCondition(),
            ]);
        }
        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }

    /**
     * The path the compiled matcher for these routes is written to / loaded
     * from — under the app cache dir, keyed by the route signature.
     */
    public static function targetFor(RouteCollection $routes): string
    {
        return self::targetForSignature(self::signature($routes));
    }

    public static function targetForSignature(string $signature): string
    {
        return rtrim(Config::getString('core.cache_dir'), '/') . '/routing/CompiledMatcher_' . $signature . '.php';
    }

    /**
     * Emit the compiled-routes PHP (a file that `return`s the array
     * CompiledUrlMatcher's constructor expects), without writing it.
     */
    public static function emit(RouteCollection $routes): EmittedArtifact
    {
        $php = (new CompiledUrlMatcherDumper($routes))->dump();
        return EmittedArtifact::fromSource($php, self::targetFor($routes));
    }
}
