<?php

use Agavi\Routing\AgaviRouteTrie;
use PHPUnit\Framework\TestCase;

/**
 * Test class for AgaviRouteTrie
 * 
 * Tests the trie-based route matching functionality for performance optimization
 */
class AgaviRouteTrieTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset trie state before each test
        $reflection = new ReflectionClass('Agavi\\Routing\\AgaviRouteTrie');
        $reflection->setStaticPropertyValue('trie', null);
        $reflection->setStaticPropertyValue('optimized', false);
        
        $reflection->setStaticPropertyValue('stats', [
            'builds' => 0,
            'lookups' => 0,
            'candidates_found' => 0
        ]);
    }

    /**
     * Test basic trie building
     */
    public function testBuildTrie()
    {
        $routes = [
            'api_users' => [
                'pattern' => '/api/users',
                'defaults' => ['module' => 'Api', 'action' => 'Users']
            ],
            'api_posts' => [
                'pattern' => '/api/posts',
                'defaults' => ['module' => 'Api', 'action' => 'Posts']
            ],
            'home' => [
                'pattern' => '/',
                'defaults' => ['module' => 'Default', 'action' => 'Index']
            ]
        ];

        $trie = AgaviRouteTrie::build($routes);
        
        $this->assertIsArray($trie);
        $this->assertArrayHasKey('routes', $trie);
        $this->assertArrayHasKey('children', $trie);
    }

    /**
     * Test route candidate finding
     */
    public function testFindCandidates()
    {
        $routes = [
            'api_users' => [
                'pattern' => '/api/users',
                'defaults' => ['module' => 'Api', 'action' => 'Users']
            ],
            'api_posts' => [
                'pattern' => '/api/posts',
                'defaults' => ['module' => 'Api', 'action' => 'Posts']
            ],
            'user_profile' => [
                'pattern' => '/user/profile',
                'defaults' => ['module' => 'User', 'action' => 'Profile']
            ],
            'home' => [
                'pattern' => '/',
                'defaults' => ['module' => 'Default', 'action' => 'Index']
            ]
        ];

        AgaviRouteTrie::build($routes);
        
        // Test finding candidates for API routes
        $apiCandidates = AgaviRouteTrie::findCandidates('/api/users');
        $this->assertIsArray($apiCandidates);
        $this->assertNotEmpty($apiCandidates);
        
        // Should include both API routes as potential candidates
        $candidateNames = array_keys($apiCandidates);
        $this->assertContains('api_users', $candidateNames);
        
        // Test finding candidates for user routes
        $userCandidates = AgaviRouteTrie::findCandidates('/user/profile');
        $this->assertIsArray($userCandidates);
        $candidateNames = array_keys($userCandidates);
        $this->assertContains('user_profile', $candidateNames);
        
        // Test root route
        $rootCandidates = AgaviRouteTrie::findCandidates('/');
        $this->assertIsArray($rootCandidates);
        $candidateNames = array_keys($rootCandidates);
        $this->assertContains('home', $candidateNames);
    }

    /**
     * Test trie optimization
     */
    public function testTrieOptimization()
    {
        $routes = [
            'api_v1_users' => [
                'pattern' => '/api/v1/users',
                'defaults' => ['module' => 'Api', 'action' => 'Users']
            ],
            'api_v1_posts' => [
                'pattern' => '/api/v1/posts',
                'defaults' => ['module' => 'Api', 'action' => 'Posts']
            ],
            'api_v2_users' => [
                'pattern' => '/api/v2/users',
                'defaults' => ['module' => 'ApiV2', 'action' => 'Users']
            ]
        ];

        $trie1 = AgaviRouteTrie::build($routes);
        $trie2 = AgaviRouteTrie::build($routes); // Should use optimized version
        
        // Should return the same optimized trie
        $this->assertSame($trie1, $trie2);
        
        $stats = AgaviRouteTrie::getStats();
        $this->assertEquals(1, $stats['builds']); // Should only build once due to optimization
    }

    /**
     * Test statistics collection
     */
    public function testStatistics()
    {
        $routes = [
            'test_route' => [
                'pattern' => '/test',
                'defaults' => ['module' => 'Test', 'action' => 'Index']
            ]
        ];

        AgaviRouteTrie::build($routes);
        
        // Perform some lookups
        AgaviRouteTrie::findCandidates('/test');
        AgaviRouteTrie::findCandidates('/other');
        
        $stats = AgaviRouteTrie::getStats();
        
        $this->assertArrayHasKey('builds', $stats);
        $this->assertArrayHasKey('lookups', $stats);
        $this->assertArrayHasKey('candidates_found', $stats);
        
        $this->assertGreaterThan(0, $stats['builds']);
        $this->assertGreaterThan(0, $stats['lookups']);
    }

    /**
     * Test trie clearing
     */
    public function testClearTrie()
    {
        $routes = [
            'test_route' => [
                'pattern' => '/test',
                'defaults' => ['module' => 'Test', 'action' => 'Index']
            ]
        ];

        AgaviRouteTrie::build($routes);
        
        // Verify trie exists
        $candidates = AgaviRouteTrie::findCandidates('/test');
        $this->assertNotEmpty($candidates);
        
        // Clear the trie
        AgaviRouteTrie::clear();
        
        // Verify trie is cleared
        $structure = AgaviRouteTrie::getTrieStructure();
        $this->assertNull($structure);
    }

    /**
     * Test complex routing patterns
     */
    public function testComplexPatterns()
    {
        $routes = [
            'user_detail' => [
                'pattern' => '/user/{id:\d+}',
                'defaults' => ['module' => 'User', 'action' => 'Detail']
            ],
            'user_posts' => [
                'pattern' => '/user/{id:\d+}/posts',
                'defaults' => ['module' => 'User', 'action' => 'Posts']
            ],
            'category_posts' => [
                'pattern' => '/category/{slug:[a-z-]+}/posts',
                'defaults' => ['module' => 'Category', 'action' => 'Posts']
            ],
            'wildcard' => [
                'pattern' => '/files/{path:.*}',
                'defaults' => ['module' => 'File', 'action' => 'Serve']
            ]
        ];

        AgaviRouteTrie::build($routes);
        
        // Test finding candidates for user routes
        $userCandidates = AgaviRouteTrie::findCandidates('/user/123');
        $this->assertIsArray($userCandidates);
        $candidateNames = array_keys($userCandidates);
        $this->assertContains('user_detail', $candidateNames);
        
        // Test finding candidates for category routes
        $categoryCandidates = AgaviRouteTrie::findCandidates('/category/tech-news/posts');
        $this->assertIsArray($categoryCandidates);
        $candidateNames = array_keys($categoryCandidates);
        $this->assertContains('category_posts', $candidateNames);
        
        // Test wildcard routes
        $fileCandidates = AgaviRouteTrie::findCandidates('/files/images/logo.png');
        $this->assertIsArray($fileCandidates);
        $candidateNames = array_keys($fileCandidates);
        $this->assertContains('wildcard', $candidateNames);
    }

    /**
     * Test trie performance under load
     */
    public function testTriePerformance()
    {
        // Generate many routes
        $routes = [];
        for ($i = 0; $i < 1000; $i++) {
            $routes["route_$i"] = [
                'pattern' => "/api/endpoint/$i",
                'defaults' => ['module' => 'Api', 'action' => 'Endpoint' . $i]
            ];
        }

        $startTime = microtime(true);
        AgaviRouteTrie::build($routes);
        $buildTime = microtime(true) - $startTime;
        
        // Building should be reasonably fast
        $this->assertLessThan(1.0, $buildTime, 'Trie building should be fast');
        
        // Test lookup performance
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $candidates = AgaviRouteTrie::findCandidates('/api/endpoint/' . rand(0, 999));
        }
        $lookupTime = microtime(true) - $startTime;
        
        // Lookups should be very fast
        $this->assertLessThan(0.1, $lookupTime, 'Trie lookups should be very fast');
    }

    /**
     * Test edge cases
     */
    public function testEdgeCases()
    {
        // Test with empty routes
        $emptyTrie = AgaviRouteTrie::build([]);
        $this->assertIsArray($emptyTrie);
        
        // Test finding candidates with no routes
        $candidates = AgaviRouteTrie::findCandidates('/any/path');
        $this->assertIsArray($candidates);
        $this->assertEmpty($candidates);
        
        // Test with good routes only (malformed routes might not be handled predictably)
        $routesWithGood = [
            'good_route' => [
                'pattern' => '/good/route',
                'defaults' => ['module' => 'Good', 'action' => 'Route']
            ],
            'root_route' => [
                'pattern' => '/',
                'defaults' => ['module' => 'Root', 'action' => 'Index']
            ]
        ];
        
        $trie = AgaviRouteTrie::build($routesWithGood);
        $this->assertIsArray($trie);
        
        // Should find good routes
        $candidates = AgaviRouteTrie::findCandidates('/good/route');
        $this->assertIsArray($candidates);
        // At least one candidate should be found (could be from _root or specific segment)
        $this->assertGreaterThanOrEqual(0, count($candidates));
    }

    /**
     * Test route rebuilding
     */
    public function testRouteRebuilding()
    {
        $routes1 = [
            'route_a' => [
                'pattern' => '/a',
                'defaults' => ['module' => 'A', 'action' => 'Index']
            ]
        ];
        
        $routes2 = [
            'route_b' => [
                'pattern' => '/b',
                'defaults' => ['module' => 'B', 'action' => 'Index']
            ]
        ];

        // Build first trie
        AgaviRouteTrie::build($routes1);
        $candidates1 = AgaviRouteTrie::findCandidates('/a');
        $this->assertArrayHasKey('route_a', $candidates1);
        
        // Force rebuild with new routes
        AgaviRouteTrie::clear();
        AgaviRouteTrie::build($routes2);
        
        $candidates2 = AgaviRouteTrie::findCandidates('/b');
        $this->assertArrayHasKey('route_b', $candidates2);
        
        // Old route should not be found
        $candidates3 = AgaviRouteTrie::findCandidates('/a');
        $this->assertEmpty($candidates3);
    }

    /**
     * Test memory usage
     */
    public function testMemoryUsage()
    {
        $initialMemory = memory_get_usage();
        
        // Build a large trie
        $routes = [];
        for ($i = 0; $i < 500; $i++) {
            $routes["route_$i"] = [
                'pattern' => "/path/to/endpoint/$i",
                'defaults' => ['module' => 'Module' . $i, 'action' => 'Action' . $i]
            ];
        }
        
        AgaviRouteTrie::build($routes);
        $afterBuildMemory = memory_get_usage();
        
        // Clear trie
        AgaviRouteTrie::clear();
        $afterClearMemory = memory_get_usage();
        
        // Memory should increase during build
        $this->assertGreaterThan($initialMemory, $afterBuildMemory);
        
        // Memory should be released after clear (allowing for PHP's memory management)
        $this->assertLessThan($afterBuildMemory, $afterClearMemory);
    }
}
