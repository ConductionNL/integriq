<?php

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Db\Endpoint;
use OCA\OpenConnector\Db\EndpointMapper;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for caching endpoint data to improve matching performance
 *
 * This service caches endpoint data in memory to avoid database queries
 * on every request when matching paths to endpoints.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 * @author   Ruben van der Linde <ruben@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/openconnector
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
     * Cache key for endpoint data
     */
    private const CACHE_KEY = 'openconnector_endpoints_cache';

    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * In-memory cache for request lifetime
     *
     * @var array|null
     */
    private ?array $memoryCache = null;

    /**
     * Constructor for EndpointCacheService
     *
     * @param ICacheFactory $cacheFactory Factory for creating cache instances
     * @param EndpointMapper $endpointMapper Mapper for endpoint database operations
     * @param LoggerInterface $logger Logger for error handling
     */
    public function __construct(
        private readonly ICacheFactory $cacheFactory,
        private readonly EndpointMapper $endpointMapper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Find the best matching endpoint for a path and method using cached data with smart fallback
     *
     * @param string $path The path to match against endpoint regex patterns
     * @param string $method The HTTP method to filter by (GET, POST, etc)
     * @param bool $isRetry Internal flag to prevent infinite recursion
     * @return Endpoint|null Returns the best matching endpoint or null if none found
     * @throws \Exception When multiple endpoints match (ambiguous routing)
     */
    public function findByPathRegex(string $path, string $method, bool $isRetry = false): ?Endpoint
    {
        $endpoints = $this->getAllEndpoints();

        $matches = array_filter($endpoints, function($endpoint) use ($path, $method) {
            // Work with arrays directly - much faster than object reconstruction
            $pattern = is_array($endpoint) ? ($endpoint['endpointRegex'] ?? null) : $endpoint->getEndpointRegex();
            $endpointMethod = is_array($endpoint) ? ($endpoint['method'] ?? null) : $endpoint->getMethod();

            // Skip if no regex pattern is set
            if (empty($pattern) === true) {
                return false;
            }

            // Check if both path matches the regex pattern and method matches
            return preg_match($pattern, $path) === 1 && $endpointMethod === $method;
        });

        // Smart fallback: if no matches found and we haven't retried yet, refresh cache once and try again
        if (empty($matches) && !$isRetry) {
            $this->logger->info("No endpoint matches found for {$method} {$path}, refreshing cache and retrying");
            
            // Force refresh the cache
            $this->refreshCache();
            
            // Try once more with fresh data
            return $this->findByPathRegex($path, $method, true);
        }

        // Handle multiple matches - this is an ambiguous routing situation
        if (count($matches) > 1) {
            $endpointNames = array_map(function($ep) {
                return is_array($ep) ? ($ep['name'] ?? 'unnamed') : ($ep->getName() ?? 'unnamed');
            }, $matches);
            throw new \Exception(
                "Multiple endpoints found for path and method: {$path} {$method}. " .
                "Matching endpoints: " . implode(', ', $endpointNames)
            );
        }

        // Return null if no matches
        if (empty($matches)) {
            return null;
        }

        // Reconstruct only the matched endpoint to an object and return it
        $matchedEndpoint = reset($matches);
        return $this->reconstructSingleEndpoint($matchedEndpoint);
    }

    /**
     * Get all endpoints from cache or database as arrays (for performance)
     *
     * @return array Array of endpoint arrays (not objects - for faster filtering)
     */
    public function getAllEndpoints(): array
    {
        // Return from memory cache if available and cache is not dirty (request lifetime)
        if ($this->memoryCache !== null && !$this->endpointMapper->isCacheDirty()) {
            return $this->memoryCache;
        }

        try {
            $cache = $this->cacheFactory->createDistributed('openconnector');
            
            // Check if cache is dirty (endpoints were modified)
            if ($this->endpointMapper->isCacheDirty()) {
                $this->logger->info('Endpoint cache is dirty, forcing refresh');
                $this->refreshCache();
                return $this->memoryCache ?? [];
            }
            
            // Try to get from persistent cache
            $cachedData = $cache->get(self::CACHE_KEY);
            
            if ($cachedData !== null && is_array($cachedData)) {
                // Store arrays directly in memory cache - no need to reconstruct all objects
                $this->memoryCache = $cachedData;
                return $cachedData;
            }

            // Cache miss - load from database
            $this->refreshCache();
            
            return $this->memoryCache ?? [];

        } catch (\Exception $e) {
            $this->logger->warning('Endpoint cache error, falling back to database: ' . $e->getMessage());
            
            // Fallback to direct database query - convert objects to arrays for consistency
            $rawEndpoints = $this->endpointMapper->findAll();
            return $this->convertEndpointsToArrays($rawEndpoints);
        }
    }

    /**
     * Refresh the endpoint cache from database
     *
     * @return void
     */
    public function refreshCache(): void
    {
        try {
            // Load fresh data from database and convert to arrays for caching
            $rawEndpoints = $this->endpointMapper->findAll();
            $endpointArrays = $this->convertEndpointsToArrays($rawEndpoints);
            
            // Store arrays in memory cache (request lifetime) - much lighter than objects
            $this->memoryCache = $endpointArrays;
            
            // Store arrays in persistent cache - lighter and faster than objects
            $cache = $this->cacheFactory->createDistributed('openconnector');
            $cache->set(self::CACHE_KEY, $endpointArrays, self::CACHE_TTL);
            
            // Mark cache as clean since we just loaded fresh data
            $this->endpointMapper->setCacheClean();
            
            $this->logger->info('Endpoint cache refreshed with ' . count($endpointArrays) . ' endpoints');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh endpoint cache: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Clear the endpoint cache
     *
     * This should be called when endpoints are created, updated, or deleted.
     *
     * @return void
     */
    public function clearCache(): void
    {
        try {
            // Clear memory cache
            $this->memoryCache = null;
            
            // Clear persistent cache
            $cache = $this->cacheFactory->createDistributed('openconnector');
            $cache->remove(self::CACHE_KEY);
            
            $this->logger->info('Endpoint cache cleared');
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to clear endpoint cache: ' . $e->getMessage());
        }
    }

    /**
     * Convert Endpoint objects to arrays for lighter caching
     *
     * This method converts endpoints to arrays and ensures proper regex patterns 
     * and endpoint arrays are set for all endpoints.
     *
     * @param array $endpoints Array of Endpoint entities from database
     * @return array Array of endpoint arrays ready for caching
     */
    private function convertEndpointsToArrays(array $endpoints): array
    {
        $endpointArrays = [];
        
        foreach ($endpoints as $endpoint) {
            // Ensure endpoint has proper regex pattern
            if (empty($endpoint->getEndpointRegex())) {
                $pattern = $this->createEndpointRegex($endpoint->getEndpoint());
                $endpoint->setEndpointRegex($pattern);
            }
            
            // Ensure endpoint has proper endpoint array
            if (empty($endpoint->getEndpointArray())) {
                $endpointArray = explode('/', $endpoint->getEndpoint());
                $endpoint->setEndpointArray($endpointArray);
            }
            
            // Convert to array using jsonSerialize (lighter than full object)
            $endpointArrays[] = $endpoint->jsonSerialize();
        }
        
        return $endpointArrays;
    }

    /**
     * Create endpoint regex pattern from endpoint path
     *
     * This mirrors the logic from EndpointMapper::createEndpointRegex()
     * but is kept here to maintain cache service independence.
     *
     * @param string $endpoint The endpoint path pattern
     * @return string The regex pattern for matching
     */
    private function createEndpointRegex(string $endpoint): string
    {
        $regex = '#^' . preg_replace(
            ['#\/{{([^}}]+)}}\/#', '#\/{{([^}}]+)}}$#'],
            ['/([^/]+)/', '(/([^/]+))?'],
            $endpoint
        ) . '#';

        // Replace only the LAST occurrence of "(/([^/]+))?#" with "(?:/([^/]+))?$#"
        $regex = preg_replace_callback(
            '/\(\/\(\[\^\/\]\+\)\)\?#/',
            function ($matches) {
                return '(?:/([^/]+))?$#';
            },
            $regex,
            1 // Limit to only one replacement
        );

        if (str_ends_with($regex, '?#') === false && str_ends_with($regex, '$#') === false) {
            $regex = substr($regex, 0, -1) . '$#';
        }

        return $regex;
    }

    /**
     * Reconstruct a single Endpoint object from cached array data
     *
     * @param mixed $endpointData Either an array or already an Endpoint object
     * @return Endpoint The reconstructed Endpoint object
     */
    private function reconstructSingleEndpoint($endpointData): Endpoint
    {
        // If it's already an Endpoint object, return it directly
        if ($endpointData instanceof Endpoint) {
            return $endpointData;
        }

        // If it's not an array, log warning and create empty endpoint
        if (!is_array($endpointData)) {
            $this->logger->warning('Unexpected endpoint data type for reconstruction: ' . gettype($endpointData));
            return new Endpoint();
        }

        try {
            // Create new Endpoint object and hydrate it with cached data
            $endpoint = new Endpoint();
            $endpoint->hydrate($endpointData);
            return $endpoint;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to reconstruct single endpoint from cache: ' . $e->getMessage());
            return new Endpoint();
        }
    }

    /**
     * Reconstruct Endpoint objects from cached array data (DEPRECATED - kept for compatibility)
     *
     * When objects are stored in distributed cache, they get serialized to arrays.
     * This method converts them back to proper Endpoint objects.
     *
     * @param array $cachedData Array of endpoint arrays from cache
     * @return array Array of Endpoint objects
     */
    private function reconstructEndpointObjects(array $cachedData): array
    {
        $endpoints = [];
        
        foreach ($cachedData as $data) {
            // If it's already an Endpoint object, use it directly
            if ($data instanceof Endpoint) {
                $endpoints[] = $data;
                continue;
            }
            
            // Skip if data is not an array (shouldn't happen but be safe)
            if (!is_array($data)) {
                $this->logger->warning('Unexpected cached endpoint data type: ' . gettype($data));
                continue;
            }
            
            try {
                // Create new Endpoint object and hydrate it with cached data
                $endpoint = new Endpoint();
                $endpoint->hydrate($data);
                $endpoints[] = $endpoint;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to reconstruct endpoint from cache: ' . $e->getMessage());
                continue;
            }
        }
        
        return $endpoints;
    }

    /**
     * Get cache statistics for monitoring
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array
    {
        try {
            $cache = $this->cacheFactory->createDistributed('openconnector');
            $cachedData = $cache->get(self::CACHE_KEY);
            
            return [
                'cached' => $cachedData !== null,
                'memory_cached' => $this->memoryCache !== null,
                'endpoint_count' => $cachedData && is_array($cachedData) ? count($cachedData) : 0,
                'cache_key' => self::CACHE_KEY,
                'cache_ttl' => self::CACHE_TTL,
                'cache_dirty' => $this->endpointMapper->isCacheDirty()
            ];
            
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'cached' => false,
                'memory_cached' => $this->memoryCache !== null,
                'cache_dirty' => false
            ];
        }
    }
}
