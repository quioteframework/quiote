<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Relay\Relay; // external runner

/**
 * Flexible middleware pipeline with phase + relative (before/after) ordering.
 * Provides feature parity with legacy pre/post filter chain semantics while embracing PSR-15.
 *
 * Phases (low -> high):
 *  bootstrap  (very early setup, metrics, context wiring)
 *  pre        (former global pre-filters; runs before routing)
 *  routing    (routing + route-derived container attachment)
 *  before_action (security, authorization, population, etc.)
 *  action     (action execution / dispatch)
 *  post       (former global post-filters; response mutation)
 *  finalize   (late logging, timing footer, emit side effects)
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    private const PHASE_ORDER = [
        'bootstrap' => 10,
        'pre' => 20,
        'routing' => 30,
        'before_action' => 40,
        'action' => 50,
        'post' => 60,
        'finalize' => 70,
    ];

    private array $entries = []; // [ name => Entry ]
    private array $insertionSequence = [];
    private RequestHandlerInterface $finalHandler;

    public function __construct(RequestHandlerInterface $finalHandler)
    { $this->finalHandler = $finalHandler; }

    public static function phaseExists(string $phase): bool
    { return isset(self::PHASE_ORDER[$phase]); }

    public function add(string $name, MiddlewareInterface $mw, string $phase = 'pre', int $priority = 0, ?string $before = null, ?string $after = null): void
    {
        if(!self::phaseExists($phase)) {
            throw new \InvalidArgumentException("Unknown phase '$phase'");
        }
        $this->entries[$name] = [
            'name' => $name,
            'mw' => $mw,
            'phase' => $phase,
            'priority' => $priority,
            'before' => $before,
            'after' => $after,
            'added' => microtime(true) + count($this->insertionSequence) * 0.000001,
        ];
        $this->insertionSequence[] = $name;
    }

    public function addPre(string $name, MiddlewareInterface $mw, int $priority = 0): void { $this->add($name, $mw, 'pre', $priority); }
    public function addPost(string $name, MiddlewareInterface $mw, int $priority = 0): void { $this->add($name, $mw, 'post', $priority); }

    public function addBefore(string $targetName, string $name, MiddlewareInterface $mw, ?string $phase = null, int $priority = 0): void
    { $this->add($name, $mw, $phase ?? ($this->entries[$targetName]['phase'] ?? 'pre'), $priority, $targetName, null); }

    public function addAfter(string $targetName, string $name, MiddlewareInterface $mw, ?string $phase = null, int $priority = 0): void
    { $this->add($name, $mw, $phase ?? ($this->entries[$targetName]['phase'] ?? 'pre'), $priority, null, $targetName); }

    /** Build a concrete handler chain and return a handler that executes it */
    public function build(): RequestHandlerInterface
    {
        $ordered = $this->resolveOrder();
        // Append terminal handler as final middleware via small adapter
        $stack = [];
        foreach($ordered as $mw) { $stack[] = $mw; }
        $stack[] = new class($this->finalHandler) implements MiddlewareInterface {
            public function __construct(private RequestHandlerInterface $final) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { return $this->final->handle($request); }
        };
        $relay = new Relay($stack);
        return new class($relay) implements RequestHandlerInterface {
            public function __construct(private Relay $relay) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->relay->handle($request); }
        };
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    { return $this->build()->handle($request); }

    /**
     * Resolve ordering taking into account phases, priorities and before/after constraints.
     * Simple algorithm: sort by (phaseOrder, priority DESC, added ASC), then adjust for before/after iteratively.
     */
    private function resolveOrder(): array
    {
        $list = $this->entries;
        uasort($list, function($a,$b){
            $pa = self::PHASE_ORDER[$a['phase']];
            $pb = self::PHASE_ORDER[$b['phase']];
            if($pa !== $pb) return $pa <=> $pb;
            if($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority']; // higher priority first
            return $a['added'] <=> $b['added'];
        });

        $names = array_keys($list);
        $changed = true;
        $guard = 0;
        while($changed && $guard < 1000) {
            $changed = false; $guard++;
            foreach($list as $name => $entry) {
                if($entry['before'] && isset($list[$entry['before']])) {
                    $currentIndex = array_search($name, $names, true);
                    $targetIndex = array_search($entry['before'], $names, true);
                    if($currentIndex > $targetIndex) { // move before target
                        array_splice($names, $currentIndex, 1);
                        $targetIndex = array_search($entry['before'], $names, true); // recompute
                        array_splice($names, $targetIndex, 0, [$name]);
                        $changed = true;
                    }
                }
                if($entry['after'] && isset($list[$entry['after']])) {
                    $currentIndex = array_search($name, $names, true);
                    $targetIndex = array_search($entry['after'], $names, true);
                    if($currentIndex < $targetIndex) { // move after target
                        array_splice($names, $currentIndex, 1);
                        $targetIndex = array_search($entry['after'], $names, true); // recompute after removal
                        array_splice($names, $targetIndex+1, 0, [$name]);
                        $changed = true;
                    }
                }
            }
        }
        if($guard >= 999) {
            throw new \RuntimeException('Failed to resolve middleware ordering (possible cyclic before/after references).');
        }
        return array_map(fn($n) => $list[$n]['mw'], $names);
    }

    /** Convenience: build from configuration array. Each item: [name,class,phase,priority,before,after,options,enabled]. */
    public static function fromArray(array $config, RequestHandlerInterface $finalHandler, ?callable $factory = null): self
    {
        $pipeline = new self($finalHandler);
        foreach($config as $item) {
            if(($item['enabled'] ?? true) === false) continue;
            $name = $item['name'];
            $class = $item['class'];
            $phase = $item['phase'] ?? 'pre';
            $priority = (int)($item['priority'] ?? 0);
            $before = $item['before'] ?? null;
            $after = $item['after'] ?? null;
            $options = $item['options'] ?? [];
            $creator = $factory ?? fn($cls,$opts) => new $cls(...(array) $opts);
            /** @var MiddlewareInterface $instance */
            $instance = $creator($class, $options);
            $pipeline->add($name, $instance, $phase, $priority, $before, $after);
        }
        return $pipeline;
    }
}
