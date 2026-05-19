<?php

declare(strict_types=1);

/**
 * SecurityService
 *
 * Service for handling security measures including rate limiting and XSS protection
 *
 * @category   Service
 * @package    OCA\OpenConnector\Service
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openconnector
 */

namespace OCA\OpenConnector\Service;

use DateTime;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Service for handling security measures
 *
 * This service provides comprehensive security features including:
 * - Rate limiting for login attempts
 * - XSS protection through input sanitization
 * - Brute force protection with IP-based blocking
 * - Login attempt logging and monitoring
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class SecurityService
{
    /**
     * Cache instance for storing rate limit data
     *
     * @var ICache
     */
    private readonly ICache $cache;

    /**
     * Logger for security events
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Rate limiting configuration constants
     */
    private const RATE_LIMIT_ATTEMPTS = 5; // Max attempts per time window
    private const RATE_LIMIT_WINDOW = 900; // 15 minutes in seconds
    private const LOCKOUT_DURATION = 3600; // 1 hour in seconds
    private const PROGRESSIVE_DELAY_BASE = 2; // Base delay in seconds
    private const MAX_PROGRESSIVE_DELAY = 60; // Maximum delay in seconds

    /**
     * Cache key prefixes for different security features
     */
    private const CACHE_PREFIX_LOGIN_ATTEMPTS = 'openconnector_login_attempts_';
    private const CACHE_PREFIX_IP_ATTEMPTS = 'openconnector_ip_attempts_';
    private const CACHE_PREFIX_USER_LOCKOUT = 'openconnector_user_lockout_';
    private const CACHE_PREFIX_IP_LOCKOUT = 'openconnector_ip_lockout_';
    private const CACHE_PREFIX_PROGRESSIVE_DELAY = 'openconnector_progressive_delay_';

    /**
     * Constructor for SecurityService
     *
     * Initializes the security service with caching and logging capabilities
     * for comprehensive security monitoring and protection.
     *
     * @param ICacheFactory $cacheFactory Factory for creating cache instances
     * @param LoggerInterface $logger Logger for security event logging
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        // Create distributed cache for rate limiting data
        $this->cache = $cacheFactory->createDistributed('openconnector_security');
        $this->logger = $logger;
    }

    /**
     * Check if login attempt is allowed based on rate limiting rules
     *
     * This method implements comprehensive rate limiting including:
     * - Per-user rate limiting
     * - Per-IP rate limiting
     * - Account lockout mechanisms
     * - Progressive delay system
     *
     * @param string $username The username attempting to login
     * @param string $ipAddress The IP address of the request
     * @return array Result with 'allowed' boolean and optional 'delay' or 'lockout_until'
     */
    public function checkLoginRateLimit(string $username, string $ipAddress): array
    {
        // Sanitize inputs to prevent cache key injection
        $username = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

        // Check if user is currently locked out
        $userLockoutKey = self::CACHE_PREFIX_USER_LOCKOUT . $username;
        $userLockoutUntil = $this->cache->get($userLockoutKey);
        if ($userLockoutUntil !== null && $userLockoutUntil > time()) {
            $this->logSecurityEvent('login_attempt_during_lockout', [
                'username' => $username,
                'ip_address' => $ipAddress,
                'lockout_until' => $userLockoutUntil
            ]);

            return [
                'allowed' => false,
                'lockout_until' => $userLockoutUntil,
                'reason' => 'Account temporarily locked due to too many failed login attempts'
            ];
        }

        // Check if IP is currently locked out
        $ipLockoutKey = self::CACHE_PREFIX_IP_LOCKOUT . $ipAddress;
        $ipLockoutUntil = $this->cache->get($ipLockoutKey);
        if ($ipLockoutUntil !== null && $ipLockoutUntil > time()) {
            $this->logSecurityEvent('login_attempt_from_blocked_ip', [
                'username' => $username,
                'ip_address' => $ipAddress,
                'lockout_until' => $ipLockoutUntil
            ]);

            return [
                'allowed' => false,
                'lockout_until' => $ipLockoutUntil,
                'reason' => 'IP address temporarily blocked due to suspicious activity'
            ];
        }

        // Check user-specific rate limit
        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS . $username;
        $userAttempts = $this->cache->get($userAttemptsKey) ?? 0;

        // Check IP-specific rate limit
        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS . $ipAddress;
        $ipAttempts = $this->cache->get($ipAttemptsKey) ?? 0;

        // Check if either user or IP has exceeded rate limit
        if ($userAttempts >= self::RATE_LIMIT_ATTEMPTS || $ipAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            // Implement progressive delay for repeated attempts
            $delayKey = self::CACHE_PREFIX_PROGRESSIVE_DELAY . $username . '_' . $ipAddress;
            $currentDelay = $this->cache->get($delayKey) ?? self::PROGRESSIVE_DELAY_BASE;

            // Calculate next delay with exponential backoff
            $nextDelay = min($currentDelay * 2, self::MAX_PROGRESSIVE_DELAY);
            $this->cache->set($delayKey, $nextDelay, self::RATE_LIMIT_WINDOW);

            $this->logSecurityEvent('rate_limit_exceeded', [
                'username' => $username,
                'ip_address' => $ipAddress,
                'user_attempts' => $userAttempts,
                'ip_attempts' => $ipAttempts,
                'delay' => $currentDelay
            ]);

            return [
                'allowed' => false,
                'delay' => $currentDelay,
                'reason' => 'Too many login attempts. Please wait before trying again.'
            ];
        }

        // Login attempt is allowed
        return ['allowed' => true];
    }

    /**
     * Record a failed login attempt
     *
     * This method tracks failed login attempts for both users and IP addresses
     * and implements automatic lockout when thresholds are exceeded.
     *
     * @param string $username The username that failed authentication
     * @param string $ipAddress The IP address of the failed attempt
     * @param string $reason The reason for login failure
     * @return void
     */
    public function recordFailedLoginAttempt(string $username, string $ipAddress, string $reason = 'invalid_credentials'): void
    {
        // Sanitize inputs
        $username = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

        // Increment user attempt counter
        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS . $username;
        $userAttempts = ($this->cache->get($userAttemptsKey) ?? 0) + 1;
        $this->cache->set($userAttemptsKey, $userAttempts, self::RATE_LIMIT_WINDOW);

        // Increment IP attempt counter
        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS . $ipAddress;
        $ipAttempts = ($this->cache->get($ipAttemptsKey) ?? 0) + 1;
        $this->cache->set($ipAttemptsKey, $ipAttempts, self::RATE_LIMIT_WINDOW);

        // Check if user should be locked out
        if ($userAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            $lockoutUntil = time() + self::LOCKOUT_DURATION;
            $userLockoutKey = self::CACHE_PREFIX_USER_LOCKOUT . $username;
            $this->cache->set($userLockoutKey, $lockoutUntil, self::LOCKOUT_DURATION);

            $this->logSecurityEvent('user_locked_out', [
                'username' => $username,
                'ip_address' => $ipAddress,
                'attempts' => $userAttempts,
                'lockout_until' => $lockoutUntil
            ]);
        }

        // Check if IP should be locked out
        if ($ipAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            $lockoutUntil = time() + self::LOCKOUT_DURATION;
            $ipLockoutKey = self::CACHE_PREFIX_IP_LOCKOUT . $ipAddress;
            $this->cache->set($ipLockoutKey, $lockoutUntil, self::LOCKOUT_DURATION);

            $this->logSecurityEvent('ip_locked_out', [
                'username' => $username,
                'ip_address' => $ipAddress,
                'attempts' => $ipAttempts,
                'lockout_until' => $lockoutUntil
            ]);
        }

        // Log the failed attempt
        $this->logSecurityEvent('failed_login_attempt', [
            'username' => $username,
            'ip_address' => $ipAddress,
            'reason' => $reason,
            'user_attempts' => $userAttempts,
            'ip_attempts' => $ipAttempts
        ]);
    }

    /**
     * Record a successful login attempt
     *
     * This method clears rate limiting counters and progressive delays
     * when a user successfully authenticates.
     *
     * @param string $username The username that successfully authenticated
     * @param string $ipAddress The IP address of the successful attempt
     * @return void
     */
    public function recordSuccessfulLogin(string $username, string $ipAddress): void
    {
        // Sanitize inputs
        $username = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

        // Clear user rate limit counters
        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS . $username;
        $this->cache->remove($userAttemptsKey);

        // Clear progressive delay
        $delayKey = self::CACHE_PREFIX_PROGRESSIVE_DELAY . $username . '_' . $ipAddress;
        $this->cache->remove($delayKey);

        // Note: We don't clear IP counters as they might be used by other users from same IP

        // Log successful login
        $this->logSecurityEvent('successful_login', [
            'username' => $username,
            'ip_address' => $ipAddress
        ]);
    }

    /**
     * Sanitize input data to prevent XSS and injection attacks
     *
     * This method provides comprehensive input sanitization including:
     * - HTML entity encoding
     * - Script tag removal
     * - Dangerous character filtering
     * - Length limiting
     *
     * @param mixed $input The input to sanitize
     * @param int $maxLength Maximum allowed length for strings
     * @return mixed Sanitized input
     */
    public function sanitizeInput(mixed $input, int $maxLength = 255): mixed
    {
        // Handle different input types
        if (is_array($input)) {
            // Recursively sanitize array elements
            return array_map(fn($item) => $this->sanitizeInput($item, $maxLength), $input);
        }

        if (!is_string($input)) {
            // For non-string inputs, return as-is (numbers, booleans, etc.)
            return $input;
        }

        // Trim whitespace
        $input = trim($input);

        // Limit input length to prevent DOS attacks
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }

        // Remove null bytes to prevent null byte injection
        $input = str_replace("\0", '', $input);

        // Encode HTML entities to prevent XSS
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove potentially dangerous patterns
        $dangerousPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', // Script tags
            '/javascript:/i', // JavaScript protocol
            '/vbscript:/i', // VBScript protocol
            '/onload\s*=/i', // onload events
            '/onerror\s*=/i', // onerror events
            '/onclick\s*=/i', // onclick events
            '/onmouseover\s*=/i', // onmouseover events
        ];

        foreach ($dangerousPatterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        return $input;
    }

    /**
     * Validate and sanitize login credentials
     *
     * This method provides specific validation and sanitization for login
     * credentials to ensure they meet security requirements.
     *
     * @param array $credentials The login credentials to validate
     * @return array Validated and sanitized credentials or error information
     */
    public function validateLoginCredentials(array $credentials): array
    {
        // Check for required fields
        if (empty($credentials['username']) || empty($credentials['password'])) {
            return [
                'valid' => false,
                'error' => 'Username and password are required'
            ];
        }

        // Sanitize username (but preserve original for authentication)
        $sanitizedUsername = $this->sanitizeInput($credentials['username'], 320); // Max email length

        // Validate username format (basic checks)
        if (strlen($sanitizedUsername) < 2) {
            return [
                'valid' => false,
                'error' => 'Username must be at least 2 characters long'
            ];
        }

        // Check for suspicious patterns in username
        if (preg_match('/[<>"\'\\/\\\\]/', $sanitizedUsername)) {
            return [
                'valid' => false,
                'error' => 'Username contains invalid characters'
            ];
        }

        // Password validation
        $password = $credentials['password'];
        if (strlen($password) > 1000) { // Prevent extremely long passwords
            return [
                'valid' => false,
                'error' => 'Password is too long'
            ];
        }

        return [
            'valid' => true,
            'credentials' => [
                'username' => $sanitizedUsername,
                'password' => $password // Don't sanitize password as it might change the actual value
            ]
        ];
    }

    /**
     * Add security headers to response
     *
     * This method adds various security headers to the HTTP response
     * to protect against common web vulnerabilities.
     *
     * @param JSONResponse $response The response to add headers to
     * @return JSONResponse The response with added security headers
     */
    public function addSecurityHeaders(JSONResponse $response): JSONResponse
    {
        // Prevent clickjacking
        $response->addHeader('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        // Enable XSS protection
        $response->addHeader('X-XSS-Protection', '1; mode=block');

        // Referrer policy
        $response->addHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Content Security Policy for API responses
        $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");

        // Prevent caching of sensitive responses
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Expires', '0');

        return $response;
    }

    /**
     * Get client IP address from request
     *
     * This method extracts the real client IP address from the request,
     * considering various proxy headers while preventing spoofing.
     *
     * @param IRequest $request The request object
     * @return string The client IP address
     */
    public function getClientIpAddress(IRequest $request): string
    {
        // Get the remote address as fallback
        $ipAddress = $request->getRemoteAddress();

        // Check for forwarded IP headers (in order of preference)
        $forwardedHeaders = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR', // Standard proxy header
            'HTTP_X_REAL_IP', // Nginx
            'HTTP_X_FORWARDED', // Alternative
            'HTTP_FORWARDED_FOR', // Alternative
            'HTTP_FORWARDED' // RFC 7239
        ];

        foreach ($forwardedHeaders as $header) {
            $headerValue = $request->getHeader($header);
            if (!empty($headerValue)) {
                // Handle comma-separated IPs (take the first one)
                $ips = explode(',', $headerValue);
                $ip = trim($ips[0]);

                // Validate IP address format and use if valid
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ipAddress = $ip;
                    break;
                }
            }
        }

        return $ipAddress;
    }

    /**
     * Sanitize string for safe cache key usage
     *
     * This helper method ensures that strings used as cache keys are safe
     * and don't contain characters that could cause issues.
     *
     * @param string $input The input string to sanitize
     * @return string Sanitized cache key
     */
    private function sanitizeForCacheKey(string $input): string
    {
        // Remove or replace characters that could be problematic in cache keys
        $sanitized = preg_replace('/[^a-zA-Z0-9._@-]/', '_', $input);

        // Limit length to prevent extremely long cache keys
        return substr($sanitized, 0, 64);
    }

    /**
     * Log security events
     *
     * This method logs security-related events for monitoring and analysis.
     *
     * @param string $event The event type
     * @param array $context Additional context data
     * @return void
     */
    private function logSecurityEvent(string $event, array $context = []): void
    {
        // Add timestamp and event type to context
        $context['event'] = $event;
        $context['timestamp'] = (new DateTime())->format('Y-m-d H:i:s');

        // Log with appropriate level based on event type
        switch($event) {
            case 'user_locked_out':
            case 'login_attempt_during_lockout':
                $this->logger->warning("Security event: {$event}", $context);
                break;
            case 'rate_limit_exceeded':
            case 'failed_login_attempt':
            case 'succesful_login':
            default:
                $this->logger->info("Security event: {$event}", $context);
                break;
        }
    }
}
