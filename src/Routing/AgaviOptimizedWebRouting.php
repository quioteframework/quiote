<?php
namespace Agavi\Routing;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Util\AgaviToolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Agavi Optimized Web Routing - High-performance routing implementation
 * 
 * This class extends AgaviWebRouting with advanced caching, trie-based
 * route matching, and performance monitoring for production environments.
 * Designed to work efficiently with FrankenPHP's persistent worker model.
 */
class AgaviOptimizedWebRouting extends AgaviRouting implements ResetInterface
{
    /** @deprecated Legacy optimized routing disabled in Symfony migration */
    protected function build(): array { return [new \Symfony\Component\Routing\RouteCollection(), []]; }
    /** Re-entrancy depth guard to avoid double miss counting. */
    private static int $executionDepth = 0;
    /** First-pass descriptor cache keyed by path|method for current worker lifecycle */
    private static array $firstPassDescriptors = [];
    /** Tracks which path|method pairs have already had a cache miss counted */
    private static array $missCountedKeys = [];
    /**
     * @var AgaviRouteTrie Route trie instance
     */
    private $routeTrie = null;
    
    /**
     * @var bool Whether optimizations are enabled
     */
    private $optimizationsEnabled = true;
    
    /**
     * @var array Configuration options
     */
    private $config = [
        'enable_cache' => true,
        'enable_trie' => true,
        'enable_monitoring' => true,
    'force_optimized' => false,
        'cache_key_prefix' => 'agavi_route_',
        'detailed_timing' => false
    ];

    /**
     * @var bool Whether every route is a trivial (simple) route allowing fast path
     */
    private $allRoutesSimple = false;

    /** @var bool Whether advanced features (callbacks, cut, imply etc.) require legacy engine */
    private $needsLegacy = false;
    /** @var bool Supports hierarchical optimized traversal (cut/imply/childs) */
    private $supportsHierarchy = false;
    
    /**
     * Initialize the optimized routing
     * 
     * @param AgaviContext $context
     * @param array $parameters
     */
    public function initialize(?AgaviContext $context = null, array $parameters = [])
    {
        parent::initialize($context, $parameters);
        
        // Merge configuration
        if (isset($parameters['optimizations'])) {
            $this->config = array_merge($this->config, $parameters['optimizations']);
        }

    // Analyze route set for feature complexity
    $this->analyzeRouteComplexity();
        
        // Configure performance monitoring
        if ($this->config['enable_monitoring']) {
            AgaviRoutingPerformanceMonitor::setDetailedTiming($this->config['detailed_timing']);
        }
    }
    
    /**
     * Execute routing with optimizations.
     * Returns descriptor array [module, action, output_type, method, parameters, matched_routes]
     * or AgaviResponse shortcut (from callbacks via legacy parent) when applicable.
     */
    public function execute()
    {
    if (!$this->optimizationsEnabled || !$this->config['force_optimized']) {
            // Ensure legacy engine sees current path from request object (tests mutate request URI directly)
            try {
                /** @var \Agavi\Request\AgaviWebRequest $req */
                $req = $this->context->getRequest();
                $rawUri = $req->getRequestUri();
                if($rawUri === null || $rawUri === '') { $rawUri = $_SERVER['REQUEST_URI'] ?? '/'; }
                $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';
                if($path === '') { $path = '/'; }
                if($path[0] !== '/') { $path = '/' . $path; }
                // Base AgaviWebRouting expects $this->input to represent current request path
                $this->input = $path;
            } catch(\Throwable $e) {
                // Fallback silently; parent may still compute input
            }
            return parent::execute();
        }

        $startTime = null;
        if ($this->config['enable_monitoring']) {
            $startTime = AgaviRoutingPerformanceMonitor::startTiming();
        }

        self::$executionDepth++;
        try {
            $result = $this->executeOptimized();
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::recordRouteMatch();
            }
        } catch (\Throwable $e) {
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::recordRouteFailure();
            }
            throw $e;
        } finally {
            self::$executionDepth--;
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::endTiming($startTime);
            }
        }
        return $result;
    }
    
    /**
     * Execute optimized routing logic and return a routing descriptor array.
     */
    private function executeOptimized()
    {
        /** @var \Agavi\Request\AgaviWebRequest $request */
        $request = $this->context->getRequest();
        // Normalize path: prefer request object's stored URI (tests call setRequestUri), fallback to server var
        $rawUri = $request->getRequestUri();
        if($rawUri === null || $rawUri === '') { $rawUri = $_SERVER['REQUEST_URI'] ?? '/'; }
        $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';
        if($path === '') { $path = '/'; }
        if($path[0] !== '/') { $path = '/' . $path; }
        $method = $request->getMethod();
        $firstKey = $path . '|' . $method;
    // Keep internal input in sync so legacy parent execute() (used when needsLegacy) sees current path
    $this->input = $path;
        // Fast return if we've already resolved this path+method in this worker lifecycle
        // Disabled early-return cache for test accuracy; rely on external caching only.
        // if(isset(self::$firstPassDescriptors[$firstKey])) {
        //     return self::$firstPassDescriptors[$firstKey];
        // }
        // If advanced features present, fall back to legacy engine (parity) with caching.
        if($this->needsLegacy) {
            if ($this->config['enable_cache']) {
                $cached = $this->tryCache($path, $method);
                if ($cached !== null) { return $cached; }
            }
            $legacy = parent::execute();
            if ($legacy instanceof \Agavi\Routing\RoutingResult && $this->config['enable_cache']) {
                \Agavi\Routing\AgaviRouteCacheManager::recordMiss();
                $this->cacheResult($path, $method, $this->resultToArray($legacy));
            }
            return $legacy;
        }

        // Ensure we only optimize truly simple route sets.
        if(!$this->allRoutesSimple) {
            // Safety: if classification changed dynamically (routes imported later), recalc.
            $this->analyzeRouteComplexity();
            if($this->needsLegacy || !$this->allRoutesSimple) {
                $legacy = parent::execute();
                if ($legacy instanceof \Agavi\Routing\RoutingResult && $this->config['enable_cache']) {
                    \Agavi\Routing\AgaviRouteCacheManager::recordMiss();
                    $this->cacheResult($path, $method, $this->resultToArray($legacy));
                }
                return $legacy;
            }
        }
        
    // Try cache first
    $cacheMissPending = false;
    $missRecorded = false;
        if ($this->config['enable_cache']) {
            $container = $this->tryCache($path, $method);
            if ($container !== null) {
                return $container; // hit
            } else {
                $cacheMissPending = true; // defer counting until we have a descriptor
            }
        }
        
        // Build route trie if enabled and needed
        if ($this->config['enable_trie'] && $this->routeTrie === null) {
            $this->buildRouteTrie();
        }
        
        // Find and test routes
        $descriptor = $this->matchRoute($path, $method);

    // Count miss only if we'll cache result (non-404) below
    $shouldRecordMiss = $cacheMissPending;
        // Cache successful non-404 result
        if ($descriptor instanceof \Agavi\Routing\RoutingResult && $this->config['enable_cache']) {
            if($shouldRecordMiss && !$missRecorded) { \Agavi\Routing\AgaviRouteCacheManager::recordMiss(); $missRecorded = true; }
            $this->cacheResult($path, $method, $this->resultToArray($descriptor));
        }

        // Store first-pass descriptor for potential re-entrant calls (avoid duplicate miss and work)
        if ($descriptor instanceof \Agavi\Routing\RoutingResult) {
            self::$firstPassDescriptors[$firstKey] = $descriptor;
            // Periodic pruning to avoid unbounded growth for long workers
            if(count(self::$firstPassDescriptors) > 1024) { self::$firstPassDescriptors = []; self::$missCountedKeys = []; }
        }
        
        return $descriptor;
    }
    
    /**
     * Try to get result from cache
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return \Agavi\Routing\RoutingResult|null Cached routing result
     */
    private function tryCache($path, $method)
    {
        $cacheKey = $this->generateCacheKey($path, $method);
        $cached = AgaviRouteCacheManager::get($cacheKey, false);
        if ($cached !== null) {
            if ($this->config['enable_monitoring']) {
                AgaviRoutingPerformanceMonitor::recordCacheHit();
            }
            return $this->buildDescriptorFromCache($cached);
        }
    // Do not increment miss here; defer until descriptor built so tests count exactly first resolution.
        return null;
    }
    
    /**
     * Generate cache key for request
     * 
     * @param string $path Request path
    // (stats normalization removed; not needed with deferred counting)
     * @param string $method HTTP method
     * @return string Cache key
     */
    private function generateCacheKey($path, $method)
    {
        return $this->config['cache_key_prefix'] . md5($path . '|' . $method);
    }
    
    private function buildDescriptorFromCache(array $cached): \Agavi\Routing\RoutingResult
    {
        $request = $this->context->getRequest();
        $rd = $request->getRequestData();
        foreach(($cached['parameters'] ?? []) as $k=>$v) { $rd->setParameter($k,$v); }
        if(!empty($cached['matched_routes'])) { $request->setAttribute('matched_routes',$cached['matched_routes'],'org.agavi.routing'); }
        if(!empty($cached['locale'])) { try { $tm=$this->context->getTranslationManager(); if($tm) { $tm->setLocale($cached['locale']); } } catch(\Throwable) {} }
        if(!empty($cached['request_method'])) { try { $request->setMethod($cached['request_method']); } catch(\Throwable) {} }
        $outputType = $cached['output_type'] ?? $this->context->getController()->getOutputType()->getName();
        $module = $cached['module'] ?? AgaviConfig::get('actions.default_module');
        $action = $cached['action'] ?? AgaviConfig::get('actions.default_action');
        $method = $cached['request_method'] ?? $request->getMethod();
        return new \Agavi\Routing\RoutingResult($module,$action,$outputType,$method,$cached['parameters'] ?? [], $cached['matched_routes'] ?? []);
    }
    
    /**
     * Build the route trie
     */
    private function buildRouteTrie()
    {
        if ($this->routes && $this->config['enable_trie']) {
            $this->routeTrie = AgaviRouteTrie::build($this->routes);
        }
    }
    
    /**
     * Match route using optimized algorithm
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return \Agavi\Routing\RoutingResult Descriptor result (may be 404 descriptor)
     */
    private function matchRoute($path, $method)
    {
        if($this->supportsHierarchy) {
            $hier = $this->hierarchicalMatch($path, $method);
            if($hier) { return $hier; }
            return $this->build404Descriptor();
        }
        $candidates = $this->getCandidateRoutes($path);
        foreach ($candidates as $name => $route) {
            $matchData = $this->testRoute($route, $path, $method);
            if ($matchData === false) { continue; }
            if (!is_array($matchData)) { continue; }
            return $this->buildDescriptor($route, $name, $matchData);
        }
        return $this->build404Descriptor();

    }

    private function buildDescriptor(array $route, string $name, array $matchData): \Agavi\Routing\RoutingResult
    {
        $request = $this->context->getRequest();
        $rd = $request->getRequestData();
        $opt = $route['opt'];
        $vars = $matchData['vars'];
        foreach($vars as $k=>$v) { $rd->setParameter($k,$v); }
        // Variable expansion parity with legacy: build map of both named and positional capture values
        $expand = [];
        if(!empty($route['matches'])) { foreach($route['matches'] as $mk=>$mv) { $expand[$mk] = $mv; $expand[] = $mv; } }
        foreach($vars as $mk=>$mv) { if(!isset($expand[$mk])) { $expand[$mk]=$mv; $expand[]=$mv; } }
        $module = $opt['module'] ?? null; if($module && method_exists(\Agavi\Util\AgaviToolkit::class,'expandVariables')) { $module = \Agavi\Util\AgaviToolkit::expandVariables($module,$expand); }
        $action = $opt['action'] ?? null; if($action && method_exists(\Agavi\Util\AgaviToolkit::class,'expandVariables')) { $action = \Agavi\Util\AgaviToolkit::expandVariables($action,$expand); }
        $outputType = $opt['output_type'] ?? $this->context->getController()->getOutputType()->getName(); if($opt['output_type'] && method_exists(\Agavi\Util\AgaviToolkit::class,'expandVariables')) { $outputType = \Agavi\Util\AgaviToolkit::expandVariables($opt['output_type'],$expand); }
        if(!empty($opt['locale'])) { try { $loc = method_exists(\Agavi\Util\AgaviToolkit::class,'expandVariables') ? \Agavi\Util\AgaviToolkit::expandVariables($opt['locale'],$expand) : $opt['locale']; if($loc) { $this->context->getTranslationManager()?->setLocale($loc); } } catch(\Throwable) {} }
        $method = $opt['method'] ?? $request->getMethod(); if($opt['method'] && method_exists(\Agavi\Util\AgaviToolkit::class,'expandVariables')) { try { $nm = \Agavi\Util\AgaviToolkit::expandVariables($opt['method'],$expand); if($nm) { $request->setMethod($nm); $method=$nm; } } catch(\Throwable) {} }
        // Implied routes: append nostops that are imply=true
        $matched = [$opt['name']];
        if(!empty($opt['nostops'])) {
            foreach(array_reverse($opt['nostops']) as $ns) {
                if(isset($this->routes[$ns]) && $this->routes[$ns]['opt']['imply']) { $matched[] = $ns; }
            }
        }
        if(!$module || !$action) { $module = $module ?? AgaviConfig::get('actions.error_404_module','Default'); $action = $action ?? AgaviConfig::get('actions.error_404_action','Error404'); }
        $request->setAttribute('matched_routes',$matched,'org.agavi.routing');
        return new \Agavi\Routing\RoutingResult($module,$action,$outputType,$method,$vars,$matched);
    }

    private function build404Descriptor(): \Agavi\Routing\RoutingResult
    {
        $req = $this->context->getRequest();
        return new \Agavi\Routing\RoutingResult(
            AgaviConfig::get('actions.error_404_module','Default'),
            AgaviConfig::get('actions.error_404_action','Error404'),
            $this->context->getController()->getOutputType()->getName(),
            $req->getMethod(),
            [],
            []
        );
    }

    /**
     * Optimized hierarchical matcher supporting cut/imply/child relationships (no callbacks or sources).
     */
    private function hierarchicalMatch(string $input, string $method)
    {
        $request = $this->context->getRequest();
        $requestData = $request->getRequestData();
        $matchedRoutes = [];
        $vars = [];

        // root routes
        $root = [];
        foreach($this->routes as $n => $r) { if(!$r['opt']['parent']) { $root[] = $n; } }
        $stack = [$root];
        while(($list = array_pop($stack)) !== null) {
            foreach($list as $rName) {
                $route =& $this->routes[$rName];
                $opt = $route['opt'];
                if(!empty($opt['constraint']) && !in_array($method, (array)$opt['constraint'], true)) { continue; }
                $matches = [];
                if(!$this->rawMatchRoute($route, $input, $matches)) { continue; }

                $matchedRoutes[] = $opt['name'];
                $ignores = !empty($opt['ignores']) ? array_flip($opt['ignores']) : [];
                if(!empty($opt['defaults'])) {
                    foreach($opt['defaults'] as $k=>$rv) { if(isset($ignores[$k])) continue; if(is_object($rv)&&method_exists($rv,'getValue')) { $v=$rv->getValue(); if($v!==null) { $vars[$k]=$v; } } }
                }
                if(!empty($route['par'])) {
                    foreach($route['par'] as $p) { if(isset($ignores[$p])) continue; if(isset($matches[$p]) && $matches[$p][1] != -1) { $val=$matches[$p][0]; $vars[$p]=$val; $route['matches'][$p]=$val; } }
                }
                if(!empty($opt['module'])) { $module = $opt['module']; }
                if(!empty($opt['action'])) { $action = $opt['action']; }
                // Variable expansion support (parity with buildContainer path)
                $expandVars = $vars;
                if(!empty($opt['output_type'])) { try { $ot = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['output_type'],$expandVars) : $opt['output_type']; if($ot !== null && $ot !== '') { $outputType = $ot; } } catch(\Throwable) {} }
                if(!empty($opt['locale'])) {
                    try { $tm=$this->context->getTranslationManager(); if($tm) {
                        $loc = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['locale'],$expandVars) : $opt['locale'];
                        if($loc !== null && $loc !== '') { $tm->setLocale($loc); }
                    } } catch(\Throwable) {}
                }
                if(!empty($opt['method'])) { try { $nm = method_exists(AgaviToolkit::class,'expandVariables') ? AgaviToolkit::expandVariables($opt['method'],$expandVars) : $opt['method']; if($nm !== null && $nm !== '') { $request->setMethod($nm); $reqMethod = $nm; } } catch(\Throwable) {} }

                // cut semantics
                if($opt['cut'] || (count($opt['childs']) && $opt['cut'] === null)) {
                    $m0 = $matches[0];
                    $newInput = '';
                    if($m0[1] > 0) { $newInput = substr((string)$input, 0, $m0[1]); }
                    $newInput .= substr((string)$input, $m0[1] + strlen((string)$m0[0]));
                    $input = $newInput;
                }

                if(count($opt['childs'])) { $stack[] = $opt['childs']; break; }
                if($opt['stop']) { break 2; }
            }
        }
        // Handle implied nostops for last matched route chain (simple approximation): when a route matched we include any nostops flagged as imply
        // This approximates legacy getAffectedRoutes() inclusion semantics for generation context.
        if($matchedRoutes) {
            // Iterate matched routes in reverse to gather nostops
            $extra = [];
            foreach(array_reverse($matchedRoutes) as $mr) {
                $r = $this->routes[$mr];
                foreach(array_reverse($r['opt']['nostops']) as $ns) {
                    $nr = $this->routes[$ns];
                    if(!$nr['opt']['imply']) { continue; }
                    if(!in_array($ns, $matchedRoutes, true) && !in_array($ns, $extra, true)) { $extra[] = $ns; }
                }
            }
            if($extra) { $matchedRoutes = array_merge($matchedRoutes, $extra); }
        }
        foreach($vars as $k=>$v) { $requestData->setParameter($k,$v); }
        if(!$matchedRoutes) { return null; }
        if(empty($module) || empty($action)) { $module = AgaviConfig::get('actions.error_404_module','Default'); $action = AgaviConfig::get('actions.error_404_action','Error404'); }
        $request->setAttribute('matched_routes',$matchedRoutes,'org.agavi.routing');
        return new \Agavi\Routing\RoutingResult(
            $module,
            $action,
            $outputType ?? $this->context->getController()->getOutputType()->getName(),
            $reqMethod ?? $request->getMethod(),
            $vars,
            $matchedRoutes
        );
    }

    private function rawMatchRoute(array $route, string $input, array &$matches): bool
    {
        $pattern = $this->getRoutePattern($route);
        if(!$pattern) { return false; }
        if(!preg_match($pattern, $input, $matches, PREG_OFFSET_CAPTURE)) {
            if(!($input === '/' && preg_match($pattern, '', $matches, PREG_OFFSET_CAPTURE))) { return false; }
        }
        return true;
    }
    
    /**
     * Get candidate routes for path
     * 
     * @param string $path Request path
     * @return array Candidate routes
     */
    private function getCandidateRoutes($path)
    {
        if ($this->config['enable_trie'] && $this->routeTrie !== null) {
            return AgaviRouteTrie::findCandidates($path);
        }
        
        // Fallback to all routes
        return $this->routes;
    }
    
    /**
     * Test if route matches the request
     * 
     * @param array $route Route definition
     * @param string $path Request path
     * @param string $method HTTP method
     * @return bool Whether route matches
     */
    private function testRoute(array &$route, string $path, string $method): array|false
    {
        $opt = $route['opt'] ?? [];
    // Unsupported advanced features -> skip (legacy engine handles). 'imply' affects generation only, so allow it.
    if(!empty($opt['cut']) || !empty($opt['childs']) || !empty($opt['callbacks']) || $opt['source'] !== null) {
            return false;
        }
        // Enforce HTTP method constraint if present
        if(!empty($opt['constraint']) && !in_array($method, (array)$opt['constraint'], true)) {
            return false;
        }
        $pattern = $this->getRoutePattern($route);
        if(!$pattern) { return false; }
        $matches = [];
        if(!preg_match($pattern, $path, $matches, PREG_OFFSET_CAPTURE)) {
            if(!($path === '/' && preg_match($pattern, '', $matches, PREG_OFFSET_CAPTURE))) { return false; }
        }
        $ignores = [];
        if(!empty($opt['ignores'])) { $ignores = array_flip($opt['ignores']); }
        $vars = [];
        if(!empty($opt['defaults'])) {
            foreach($opt['defaults'] as $k => $routingValue) {
                if(isset($ignores[$k])) { continue; }
                if(is_object($routingValue) && method_exists($routingValue, 'getValue')) {
                    $val = $routingValue->getValue();
                    if($val !== null) { $vars[$k] = $val; }
                }
            }
        }
        if(!empty($route['par'])) {
            foreach($route['par'] as $paramName) {
                if(isset($ignores[$paramName])) { continue; }
                if(isset($matches[$paramName]) && $matches[$paramName][1] != -1) {
                    $vars[$paramName] = $matches[$paramName][0];
                    $route['matches'][$paramName] = $matches[$paramName][0];
                }
            }
        }
        return ['vars' => $vars];
    }
    
    /**
     * Analyze all routes to determine if we can apply the simple fast path or must fall back.
     * Simple route definition criteria (all must hold):
     * - No childs, callbacks, implies, cut, ignores, defaults, source, constraint, output_type, locale
     * - Has explicit module & action
     * - No pattern parameters (par empty)
     */
    private function analyzeRouteComplexity(): void
    {
        if(!$this->routes) {
            $this->allRoutesSimple = true;
            $this->needsLegacy = false;
            return;
        }
        $allSimple = true;
        $needsLegacy = false;
        $this->supportsHierarchy = false;
        foreach($this->routes as $route) {
            $opt = $route['opt'];
            $pattern = $this->getRoutePattern($route) ?? '';
            $hasParams = !empty($route['par']);
            $hasNamedSyntax = str_contains($pattern, '(') || str_contains($pattern, ':');
            $complex = $hasParams || $hasNamedSyntax || !empty($opt['childs']) || !empty($opt['callbacks']) || !empty($opt['imply']) || !empty($opt['cut']) ||
                !empty($opt['ignores']) || !empty($opt['defaults']) || $opt['source'] !== null || !empty($opt['constraint']) ||
                !empty($opt['output_type']) || !empty($opt['locale']) || !empty($opt['method']) || empty($opt['module']) ||
                empty($opt['action']);
            if($complex) { $allSimple = false; $needsLegacy = true; break; }
        }
        $this->allRoutesSimple = $allSimple;
        $this->needsLegacy = $needsLegacy;
    }
    
    /**
     * Get route pattern for matching
     * 
     * @param array $route Route definition
     * @return string|null Route pattern
     */
    private function getRoutePattern($route)
    {
        if (isset($route['pattern'])) {
            return $route['pattern'];
        }
        if (isset($route['rxp'])) {
            return $route['rxp'];
        }
        if (isset($route['opt']['pat'])) {
            return $route['opt']['pat'];
        }
        return null;
    }
    
    // buildContainer & build404Container removed (descriptor-only path).
    
    /**
     * Cache routing result
     * 
     * @param string $path Request path
     * @param string $method HTTP method
    * @param array $descriptor Result descriptor
     */
    private function cacheResult($path, $method, array $descriptor)
    {
        $cacheKey = $this->generateCacheKey($path, $method);
        // Skip caching 404 descriptor so new routes become visible without worker restart
        $errMod = AgaviConfig::get('actions.error_404_module', 'Default');
        $errAct = AgaviConfig::get('actions.error_404_action', 'Error404');
        if(($descriptor['module'] ?? null) === $errMod && ($descriptor['action'] ?? null) === $errAct) { return; }
        $cacheData = [
            'module' => $descriptor['module'] ?? null,
            'action' => $descriptor['action'] ?? null,
            'parameters' => [],
            'matched_routes' => $this->context->getRequest()->getAttribute('matched_routes', 'org.agavi.routing', []),
            'output_type' => $descriptor['output_type'] ?? null,
            'locale' => ($this->context->getTranslationManager()?->getCurrentLocaleIdentifier()) ?? null,
            'request_method' => $descriptor['method'] ?? $this->context->getRequest()->getMethod(),
        ];
        
        // Store relevant parameters
        $request = $this->context->getRequest();
        $requestData = $request->getRequestData();
        foreach ($requestData->getParameterNames() as $name) {
            if (strpos($name, '_') !== 0) { // Skip internal parameters
                $cacheData['parameters'][$name] = $requestData->getParameter($name);
            }
        }
        
        AgaviRouteCacheManager::set($cacheKey, $cacheData);
    }
    
    /**
     * Get comprehensive performance statistics
     * 
     * @return array Performance metrics
     */
    public function getPerformanceStats()
    {
        $stats = [];
        
        if ($this->config['enable_monitoring']) {
            $stats['routing'] = AgaviRoutingPerformanceMonitor::getStats();
        }
        
        if ($this->config['enable_cache']) {
            $stats['cache'] = AgaviRouteCacheManager::getStats();
        }
        
        if ($this->config['enable_trie']) {
            $stats['trie'] = AgaviRouteTrie::getStats();
        }
        
        $stats['callbacks'] = AgaviRoutingCallbackPool::getStats();
        
        return $stats;
    }
    
    /**
     * Clear all caches and reset optimizations
     */
    public function clearOptimizations()
    {
        AgaviRouteCacheManager::clear();
        AgaviRouteTrie::clear();
        AgaviRoutingCallbackPool::clearPool();
        AgaviRoutingPerformanceMonitor::getResetInstance()->reset();
        $this->routeTrie = null;
    self::$firstPassDescriptors = [];
    self::$missCountedKeys = [];
    }
    
    /**
     * Enable or disable optimizations
     * 
     * @param bool $enabled Whether optimizations are enabled
     */
    public function setOptimizationsEnabled($enabled)
    {
        $this->optimizationsEnabled = $enabled;
    }
    
    /**
     * Configure optimization settings
     * 
     * @param array $config Configuration options
     */
    public function setOptimizationConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
        
        if (isset($config['detailed_timing'])) {
            AgaviRoutingPerformanceMonitor::setDetailedTiming($config['detailed_timing']);
        }

        return null; // if nothing matched allow outer 404 descriptor
    }

    private function resultToArray(\Agavi\Routing\RoutingResult $r): array
    {
        return [
            'module' => $r->getModuleName(),
            'action' => $r->getActionName(),
            'output_type' => $r->getOutputType(),
            'method' => $r->getRequestMethod(),
            'parameters' => $r->getParameters(),
            'matched_routes' => $r->getMatchedRoutes(),
        ];
    }
    
    /**
     * Get optimization configuration
     * 
     * @return array Current configuration
     */
    public function getOptimizationConfig()
    {
        return $this->config;
    }

    public function reset() : void
    {
        $this->clearOptimizations();
    $this->context = null; // context reattached via initialize() on reuse
        $this->routeTrie = null;
        $this->optimizationsEnabled = true;
        $this->config = [
            'enable_cache' => true,
            'enable_trie' => true,
            'enable_monitoring' => true,
            'force_optimized' => false,
            'cache_key_prefix' => 'agavi_route_',
            'detailed_timing' => false
        ];
    self::$firstPassDescriptors = [];
    self::$missCountedKeys = [];
    }
}
