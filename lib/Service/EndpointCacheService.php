<?php
/**
 * OpenConnector EndpointCacheService.
 *
 * Service for caching endpoint data to improve matching performance.
 *
 * This service caches endpoint data in memory to avoid database queries
 * on every request when matching paths to endpoints.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
 */

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for caching endpoint data to improve matching performance.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 */
class EndpointCacheService
{

    /**
     * Cache key for endpoint data.
     */
    private const CACHE_KEY = 'openconnector_endpoints_cache';

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * In-memory cache for request lifetime.
     *
     * @var array|null
     */
    private ?array $memoryCache = null;

    /**
     * Whether the in-memory cache is considered stale (set to true after a write).
     *
     * @var boolean
     */
    private bool $cacheDirty = false;

    /**
     * Constructor for EndpointCacheService.
     *
     * @param ICacheFactory                           $cacheFactory    Factory for creating cache instances.
     * @param \OCA\OpenRegister\Service\ObjectService $orObjectService OR object service for endpoint lookups.
     * @param LoggerInterface                         $logger          Logger for error handling.
     */
    public function __construct(
        private readonly ICacheFactory $cacheFactory,
        private readonly \OCA\OpenRegister\Service\ObjectService $orObjectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Find the best matching endpoint for a path and method using cached data with smart fallback.
     *
     * @param string  $path    The path to match against endpoint regex patterns.
     * @param string  $method  The HTTP method to filter by (GET, POST, etc).
     * @param boolean $isRetry Internal flag to prevent infinite recursion.
     *
     * @return ObjectEntity|null Returns the best matching endpoint or null if none found.
     *
     * @throws \Exception When multiple endpoints match (ambiguous routing).
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    public function findByPathRegex(string $path, string $method, bool $isRetry=false): ?ObjectEntity
    {
        $endpoints = $this->getAllEndpoints();

        $matches = array_filter(
                $endpoints,
                function ($endpoint) use ($path, $method) {
                    // Work with arrays directly - much faster than object reconstruction.
                    if ($endpoint instanceof ObjectEntity) {
                        $endpointData = $endpoint->getObject();
                    } else if (is_array($endpoint) === true) {
                        $endpointData = $endpoint;
                    } else {
                        $endpointData = [];
                    }

                    $pattern        = ($endpointData['endpointRegex'] ?? null);
                    $endpointMethod = ($endpointData['method'] ?? null);

                    // Skip if no regex pattern is set.
                    if (empty($pattern) === true) {
                        return false;
                    }

                    // Check if both path matches the regex pattern and method matches.
                    return (preg_match($pattern, $path) === 1 && $endpointMethod === $method);
                }
                );

        // Smart fallback: if no matches found and we haven't retried yet, refresh cache once and try again.
        if (empty($matches) === true && $isRetry === false) {
            $this->logger->info("No endpoint matches found for {$method} {$path}, refreshing cache and retrying");

            // Force refresh the cache.
            $this->refreshCache();

            // Try once more with fresh data.
            return $this->findByPathRegex(path: $path, method: $method, isRetry: true);
        }

        // Handle multiple matches - this is an ambiguous routing situation.
        if (count($matches) > 1) {
            $endpointNames = array_map(
                    function ($ep) {
                        if ($ep instanceof ObjectEntity) {
                            $data = $ep->getObject();
                        } else if (is_array($ep) === true) {
                            $data = $ep;
                        } else {
                            $data = [];
                        }

                        return ($data['name'] ?? 'unnamed');
                    },
                    $matches
                    );
            throw new \Exception(
                "Multiple endpoints found for path and method: {$path} {$method}. Matching endpoints: ".implode(', ', $endpointNames)
            );
        }

        // Return null if no matches.
        if (empty($matches) === true) {
            return null;
        }

        // Return the matched ObjectEntity endpoint.
        $matchedEndpoint = reset($matches);
        if ($matchedEndpoint instanceof ObjectEntity) {
            return $matchedEndpoint;
        }

        return null;

    }//end findByPathRegex()

    /**
     * Get all endpoints from cache or database as ObjectEntity arrays (for performance).
     *
     * @return array Array of ObjectEntity instances (for faster filtering).
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    public function getAllEndpoints(): array
    {
        // Return from memory cache if available and cache is not dirty (request lifetime).
        if ($this->memoryCache !== null && $this->cacheDirty === false) {
            return $this->memoryCache;
        }

        try {
            $cache = $this->cacheFactory->createDistributed('openconnector');

            // Check if cache is dirty (endpoints were modified).
            if ($this->cacheDirty === true) {
                $this->logger->info('Endpoint cache is dirty, forcing refresh');
                $this->refreshCache();
                return ($this->memoryCache ?? []);
            }

            // Try to get from persistent cache.
            $cachedData = $cache->get(self::CACHE_KEY);

            if ($cachedData !== null && is_array($cachedData) === true) {
                $this->memoryCache = $cachedData;
                return $cachedData;
            }

            // Cache miss - load from database.
            $this->refreshCache();

            return ($this->memoryCache ?? []);
        } catch (\Exception $e) {
            $this->logger->warning('Endpoint cache error, falling back to database: '.$e->getMessage());

            // Fallback to direct OR query.
            return $this->fetchEndpointsFromOr();
        }//end try

    }//end getAllEndpoints()

    /**
     * Fetch all endpoints from OpenRegister ObjectService.
     *
     * @return array Array of ObjectEntity instances.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    private function fetchEndpointsFromOr(): array
    {
        $matches   = $this->orObjectService->findAll(config: ['filters' => ['register' => 'openconnector', 'schema' => 'endpoint']], _rbac: false, _multitenancy: false);
        $endpoints = ($matches['results'] ?? $matches);
        if (is_array($endpoints) === true) {
            return $endpoints;
        }

        return [];

    }//end fetchEndpointsFromOr()

    /**
     * Refresh the endpoint cache from OpenRegister.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    public function refreshCache(): void
    {
        try {
            // Load fresh data from OR.
            $endpoints = $this->fetchEndpointsFromOr();

            // Store in memory cache (request lifetime).
            $this->memoryCache = $endpoints;
            $this->cacheDirty  = false;

            // Persist serialisable data (arrays) in distributed cache.
            $serialisable = array_map(
                static function ($ep) {
                    if ($ep instanceof ObjectEntity) {
                        return $ep->getObject();
                    } else if (is_array($ep) === true) {
                        return $ep;
                    }

                    return [];
                },
                $endpoints
            );

            $cache = $this->cacheFactory->createDistributed('openconnector');
            $cache->set(self::CACHE_KEY, $serialisable, self::CACHE_TTL);

            $this->logger->info('Endpoint cache refreshed with '.count($endpoints).' endpoints');
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh endpoint cache: '.$e->getMessage());
            throw $e;
        }//end try

    }//end refreshCache()

    /**
     * Clear the endpoint cache.
     *
     * This should be called when endpoints are created, updated, or deleted.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    public function clearCache(): void
    {
        try {
            // Clear memory cache.
            $this->memoryCache = null;

            // Clear persistent cache.
            $cache = $this->cacheFactory->createDistributed('openconnector');
            $cache->remove(self::CACHE_KEY);

            $this->logger->info('Endpoint cache cleared');
        } catch (\Exception $e) {
            $this->logger->warning('Failed to clear endpoint cache: '.$e->getMessage());
        }

    }//end clearCache()

    /**
     * Create endpoint regex pattern from endpoint path.
     *
     * This mirrors the logic from EndpointMapper::createEndpointRegex()
     * but is kept here to maintain cache service independence.
     *
     * @param string $endpoint The endpoint path pattern.
     *
     * @return string The regex pattern for matching.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    private function createEndpointRegex(string $endpoint): string
    {
        $regex = '#^'.preg_replace(
            ['#\/{{([^}}]+)}}\/#', '#\/{{([^}}]+)}}$#'],
            ['/([^/]+)/', '(/([^/]+))?'],
            $endpoint
        ).'#';

        // Replace only the LAST occurrence of "(/([^/]+))?#" with "(?:/([^/]+))?$#".
        $regex = preg_replace_callback(
            '/\(\/\(\[\^\/\]\+\)\)\?#/',
            function ($matches) {
                return '(?:/([^/]+))?$#';
            },
            $regex,
            // Limit to only one replacement.
            1
        );

        if (str_ends_with($regex, '?#') === false && str_ends_with($regex, '$#') === false) {
            $regex = (substr($regex, 0, -1).'$#');
        }

        return $regex;

    }//end createEndpointRegex()

    /**
     * Get cache statistics for monitoring.
     *
     * @return array Cache statistics.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-4
     */
    public function getCacheStats(): array
    {
        try {
            $cache      = $this->cacheFactory->createDistributed('openconnector');
            $cachedData = $cache->get(self::CACHE_KEY);

            if ($cachedData !== null && is_array($cachedData) === true) {
                $endpointCount = count($cachedData);
            } else {
                $endpointCount = 0;
            }

            return [
                'cached'         => ($cachedData !== null),
                'memory_cached'  => ($this->memoryCache !== null),
                'endpoint_count' => $endpointCount,
                'cache_key'      => self::CACHE_KEY,
                'cache_ttl'      => self::CACHE_TTL,
                'cache_dirty'    => $this->cacheDirty,
            ];
        } catch (\Exception $e) {
            return [
                'error'         => $e->getMessage(),
                'cached'        => false,
                'memory_cached' => ($this->memoryCache !== null),
                'cache_dirty'   => $this->cacheDirty,
            ];
        }//end try

    }//end getCacheStats()
}//end class
