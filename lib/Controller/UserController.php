<?php

declare(strict_types=1);

/**
 * UserController
 *
 * This controller handles user-related API endpoints including user information
 * retrieval, updates, and authentication with comprehensive security measures.
 *
 * @category  Controller
 * @package   OpenConnector
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/opencatalogi
 */

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\SecurityService;
use OCA\OpenConnector\Service\UserService;
use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller class for handling user-related API endpoints
 *
 * This controller provides secure API endpoints for user management operations
 * including authentication, profile retrieval, and updates with comprehensive
 * security measures against XSS and brute force attacks.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ShortMethodName)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class UserController extends Controller
{
    /**
     * User manager for user operations
     *
     * @var IUserManager
     */
    private readonly IUserManager $userManager;

    /**
     * User session manager for session operations
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Authorization service for handling authentication
     *
     * @var AuthorizationService
     */
    private readonly AuthorizationService $authorizationService;

    /**
     * Security service for handling rate limiting and XSS protection
     *
     * @var SecurityService
     */
    private readonly SecurityService $securityService;

    /**
     * User service for user-related business logic
     *
     * @var UserService
     */
    private readonly UserService $userService;

    /**
     * Organisation bridge service for organization management
     *
     * @var OrganisationBridgeService
     */
    private readonly OrganisationBridgeService $organisationBridgeService;

    /**
     * Logger for application events
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Localization service
     *
     * @var IL10N
     */
    private readonly IL10N $l;

    /**
     * Constructor for the UserController
     *
     * Initializes the controller with required dependencies for user management
     * and authentication operations.
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object for handling HTTP requests
     * @param IUserManager $userManager The user manager for user operations
     * @param IUserSession $userSession The user session manager
     * @param AuthorizationService $authorizationService The authorization service
     * @param ICacheFactory $cacheFactory The cache factory for rate limiting
     * @param LoggerInterface $logger The logger for security events
     * @param UserService $userService The user service for user-related operations
     * @param OrganisationBridgeService $organisationBridgeService The organization bridge service
     *
     * @psalm-param string $appName
     * @psalm-param IRequest $request
     * @psalm-param IUserManager $userManager
     * @psalm-param IUserSession $userSession
     * @psalm-param AuthorizationService $authorizationService
     * @psalm-param ICacheFactory $cacheFactory
     * @psalm-param LoggerInterface $logger
     * @psalm-param UserService $userService
     * @psalm-param OrganisationBridgeService $organisationBridgeService
     * @phpstan-param string $appName
     * @phpstan-param IRequest $request
     * @phpstan-param IUserManager $userManager
     * @phpstan-param IUserSession $userSession
     * @phpstan-param AuthorizationService $authorizationService
     * @phpstan-param ICacheFactory $cacheFactory
     * @phpstan-param LoggerInterface $logger
     * @phpstan-param UserService $userService
     * @phpstan-param OrganisationBridgeService $organisationBridgeService
     */
    public function __construct(
        string $appName,
        IRequest $request,
        IUserManager $userManager,
        IUserSession $userSession,
        AuthorizationService $authorizationService,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger,
        UserService $userService,
        OrganisationBridgeService $organisationBridgeService,
        IL10N $l
    ) {
        parent::__construct($appName, $request);
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->authorizationService = $authorizationService;
        $this->securityService = new SecurityService($cacheFactory, $logger);
        $this->userService = $userService;
        $this->organisationBridgeService = $organisationBridgeService;
        $this->logger = $logger;
        $this->l = $l;
    }

    /**
     * Get current user information as JSON object
     *
     * This method returns the current authenticated user's information
     * in JSON format for external API consumption with security headers.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the current user's information
     *
     * @psalm-return JSONResponse
     * @phpstan-return JSONResponse
     */
    public function me(): JSONResponse
    {
        try {
            // Get the current user from the session
            $currentUser = $this->userService->getCurrentUser();

            // Check if user is logged in
            if ($currentUser === null) {
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('User not authenticated')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Build user data array with essential information (already sanitized)
            $userData = $this->userService->buildUserDataArray($currentUser);

            $response = new JSONResponse($userData);
            return $this->securityService->addSecurityHeaders($response);
        } catch (\Exception $e) {
            // Log the error and return generic error response
            $response = new JSONResponse(
                data: ['error' => $this->l->t('Failed to retrieve user information')],
                statusCode: 500
            );
            return $this->securityService->addSecurityHeaders($response);
        }
    }

    /**
     * Update current user information from JSON object
     *
     * This method securely updates the current authenticated user's information
     * based on the provided JSON data with input sanitization and validation.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the updated user information
     *
     * @psalm-return JSONResponse
     * @phpstan-return JSONResponse
     */
    public function updateMe(): JSONResponse
    {
        try {
            // Get the current user from the session
            $currentUser = $this->userService->getCurrentUser();

            // Check if user is logged in
            if ($currentUser === null) {
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('User not authenticated')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Get and sanitize the request data to prevent XSS
            $data = $this->request->getParams();
            $sanitizedData = $this->securityService->sanitizeInput($data);

            // Remove system parameters that shouldn't be updated
            foreach ($sanitizedData as $key => $value) {
                if (str_starts_with($key, '_') === true) {
                    unset($sanitizedData[$key]);
                }
            }

            // Update user properties based on provided data
            $updateResult = $this->userService->updateUserProperties($currentUser, $sanitizedData);

            // Build updated user data array
            $userData = $this->userService->buildUserDataArray($currentUser);

            // Add update result information to response
            $responseData = $userData;
            if ($updateResult['organisation_updated'] === true) {
                $responseData['update_message'] = $updateResult['organisation_message'];
            }

            $response = new JSONResponse($responseData);
            return $this->securityService->addSecurityHeaders($response);
        } catch (\Exception $e) {
            // Log the error and return generic error response
            $response = new JSONResponse(
                data: ['error' => $this->l->t('Failed to update user information')],
                statusCode: 500
            );
            return $this->securityService->addSecurityHeaders($response);
        }
    }

    /**
     * Login a user based on username and password combination
     *
     * This method securely authenticates a user using their username/email and password,
     * with comprehensive protection against XSS and brute force attacks including:
     * - Input validation and sanitization
     * - Rate limiting per user and IP
     * - Progressive delays for repeated attempts
     * - Account and IP lockout mechanisms
     * - Security event logging
     * - Security headers in response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return JSONResponse A JSON response containing login result and user information
     *
     * @psalm-return JSONResponse
     * @phpstan-return JSONResponse
     */
    public function login(): JSONResponse
    {
        try {
            // MEMORY MONITORING: Check initial memory usage to prevent OOM
            $initialMemoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');

            // Convert memory limit to bytes for comparison
            $memoryLimitBytes = $this->convertToBytes($memoryLimit);

            // If we're already using more than 80% of memory limit, return error
            if ($memoryLimitBytes > 0 && $initialMemoryUsage > ($memoryLimitBytes * 0.8)) {
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Server memory usage too high, please try again later')],
                    statusCode: 503 // Service Unavailable
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Get client IP address for rate limiting
            $clientIp = $this->securityService->getClientIpAddress($this->request);

            // Get and sanitize login credentials from request
            $data = $this->request->getParams();

            // Validate and sanitize credentials to prevent XSS attacks
            $credentialValidation = $this->securityService->validateLoginCredentials($data);
            if ($credentialValidation['valid'] === false) {
                $response = new JSONResponse(
                    data: ['error' => $credentialValidation['error']],
                    statusCode: 400
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            $credentials = $credentialValidation['credentials'];
            $username = $credentials['username'];
            $password = $credentials['password'];

            // Check rate limiting before attempting authentication
            $rateLimitCheck = $this->securityService->checkLoginRateLimit($username, $clientIp);
            if ($rateLimitCheck['allowed'] === false) {
                // Apply progressive delay if specified
                if (isset($rateLimitCheck['delay']) === true) {
                    sleep($rateLimitCheck['delay']);
                }

                $response = new JSONResponse(
                    data: [
                        'error' => $rateLimitCheck['reason'],
                        'retry_after' => $rateLimitCheck['delay'] ?? null,
                        'lockout_until' => $rateLimitCheck['lockout_until'] ?? null
                    ],
                    statusCode: 429 // Too Many Requests
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Attempt to authenticate the user
            $user = $this->userManager->checkPassword($username, $password);

            // Check if authentication was successful
            if ($user === false) {
                // Record failed login attempt for rate limiting
                $this->securityService->recordFailedLoginAttempt($username, $clientIp, 'invalid_credentials');

                // Return generic error message to prevent username enumeration
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Invalid username or password')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Check if user account is enabled
            if ($user->isEnabled() === false) {
                // Record failed login attempt for disabled account
                $this->securityService->recordFailedLoginAttempt($username, $clientIp, 'account_disabled');

                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Account is disabled')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Authentication successful - record success and clear rate limits
            $this->securityService->recordSuccessfulLogin($username, $clientIp);

            // Set the user in the session to create login session
            $this->userSession->setUser($user);

            // Build user data array for response (sanitized)
            $userData = $this->userService->buildUserDataArray($user);

            // MEMORY MONITORING: Check memory usage after building user data
            $finalMemoryUsage = memory_get_usage(true);
            $memoryIncreaseBytes = $finalMemoryUsage - $initialMemoryUsage;

            // Log memory usage for monitoring
            if ($memoryIncreaseBytes > 10 * 1024 * 1024) { // 10MB threshold
                $this->logger->warning('High memory usage during login', [
                    'user' => $user->getUID(),
                    'initial_memory' => $initialMemoryUsage,
                    'final_memory' => $finalMemoryUsage,
                    'increase_bytes' => $memoryIncreaseBytes,
                    'increase_mb' => round($memoryIncreaseBytes / (1024 * 1024), 2)
                ]);
            }

            // Create successful response with security headers
            $response = new JSONResponse([
                'message' => $this->l->t('Login successful'),
                'user' => $userData,
                'session_created' => true
            ]);

            return $this->securityService->addSecurityHeaders($response);
        } catch (\Exception $e) {
            // Log the error securely without exposing sensitive information
            $response = new JSONResponse(
                data: ['error' => $this->l->t('Login failed due to a system error')],
                statusCode: 500
            );
            return $this->securityService->addSecurityHeaders($response);
        }
    }

    /**
     * Convert PHP memory limit string to bytes
     *
     * This helper method converts PHP memory limit strings (like "128M", "1G")
     * to bytes for memory usage comparisons.
     *
     * @param string $memoryLimit The memory limit string from PHP ini
     * @return int The memory limit in bytes, or 0 if unlimited
     *
     * @psalm-param string $memoryLimit
     * @psalm-return int
     * @phpstan-param string $memoryLimit
     * @phpstan-return int
     */
    private function convertToBytes(string $memoryLimit): int
    {
        // If memory limit is -1, it means unlimited
        if ($memoryLimit === '-1') {
            return 0;
        }

        // Convert the memory limit to bytes
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Logs out the user on the active user session
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @return JSONResponse
     */
    public function logout(): JSONResponse {
        $this->userSession->logout();

        return new JSONResponse(['logout' => true]);
    }
}
