<?php
declare(strict_types=1);

namespace Agavi\Test\Routing;

use Agavi\Routing\AgaviRouting;
use Symfony\Component\Routing\RouteCollection;

/**
 * GeneratedTestRouting loads a pre-generated Routes aggregator class (per context)
 * produced by generate_symfony_routes.php. It avoids any runtime XML parsing.
 */
class GeneratedTestRouting extends AgaviRouting
{
    /** Map context => callable returning [RouteCollection, meta] */
    private static array $contextLoaders = [];

    public function __construct(private readonly string $activeContext)
    {
        // Ensure contexts are registered before parent constructor triggers build()
    self::bootstrapDefaultContexts();
        parent::__construct();
    }

    public static function registerContext(string $context, callable $loader): void
    {
        self::$contextLoaders[$context] = $loader;
    }

    public static function bootstrapDefaultContexts(): void
    {
        if(isset(self::$contextLoaders['test1'])) return; // already bootstrapped
        // Dynamically include generated route aggregators if present
        $map = [
            'test1' => \AgaviTestGeneratedTest1\Routes::class,
            'test2' => \AgaviTestGeneratedTest2\Routes::class,
        ];
        foreach($map as $ctx=>$cls){
            if(!class_exists($cls)) {
                // Fallback manual require if autoload not configured for generated namespace yet.
                $segments = explode('\\\\', $cls);
                $base = strtolower($segments[0]); // not used; just attempt predictable path
                $short = end($segments);
                $candidate = __DIR__ . '/../../GeneratedRoutes/' . $ctx . '/Routes.php';
                if(is_file($candidate)) { require_once $candidate; }
            }
            if(class_exists($cls)) {
                self::registerContext($ctx, static fn() => $cls::build());
            }
        }
    }

    protected function build(): array
    {
        if (!isset(self::$contextLoaders[$this->activeContext])) {
            throw new \RuntimeException("No generated routes registered for context {$this->activeContext}");
        }
        [$rc, $meta] = (self::$contextLoaders[$this->activeContext])();
        if(!$rc instanceof RouteCollection) { throw new \RuntimeException('Route loader did not return RouteCollection'); }
        return [$rc, $meta];
    }
}
