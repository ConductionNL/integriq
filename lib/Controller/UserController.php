<?php
/**
 * OpenConnector UserController.
 *
 * This controller handles user-related API endpoints including user information
 * retrieval, updates, and authentication with comprehensive security measures.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\SecurityService;
use OCA\OpenConnector\Service\UserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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
     * CORS allowed methods
     *
     * @var string
     */
    private string $corsMethods;

    /**
     * CORS allowed headers
     *
     * @var string
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age
     *
     * @var integer
     */
    private int $corsMaxAge;

    /**
     * Constructor for the UserController
     *
     * Initializes the controller with required dependencies for user management
     * and authentication operations.
     *
     * @param string          $appName            The name of the app
     * @param IRequest        $request            The request object for handling HTTP requests
     * @param IUserManager    $userManager        The user manager for user operations
     * @param IUserSession    $userSession        The user session manager
     * @param ICacheFactory   $cacheFactory       The cache factory for rate limiting
     * @param LoggerInterface $logger             The logger for security events
     * @param UserService     $userService        The user service for user-related operations
     * @param IL10N           $l                  The localization service
     * @param string          $corsMethods        Allowed CORS methods
     * @param string          $corsAllowedHeaders Allowed CORS headers
     * @param int             $corsMaxAge         CORS max age
     *
     * @psalm-param   string $appName
     * @psalm-param   IRequest $request
     * @psalm-param   IUserManager $userManager
     * @psalm-param   IUserSession $userSession
     * @psalm-param   ICacheFactory $cacheFactory
     * @psalm-param   LoggerInterface $logger
     * @psalm-param   UserService $userService
     * @psalm-param   IL10N $l
     * @psalm-param   string $corsMethods
     * @psalm-param   string $corsAllowedHeaders
     * @psalm-param   int $corsMaxAge
     * @phpstan-param string $appName
     * @phpstan-param IRequest $request
     * @phpstan-param IUserManager $userManager
     * @phpstan-param IUserSession $userSession
     * @phpstan-param ICacheFactory $cacheFactory
     * @phpstan-param LoggerInterface $logger
     * @phpstan-param UserService $userService
     * @phpstan-param IL10N $l
     * @phpstan-param string $corsMethods
     * @phpstan-param string $corsAllowedHeaders
     * @phpstan-param int $corsMaxAge
     */
    public function __construct(
        string $appName,
        IRequest $request,
        IUserManager $userManager,
        IUserSession $userSession,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger,
        UserService $userService,
        IL10N $l,
        string $corsMethods='PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders='Authorization, Content-Type, Accept',
        int $corsMaxAge=1728000
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->userManager     = $userManager;
        $this->userSession     = $userSession;
        $this->securityService = new SecurityService(cacheFactory: $cacheFactory, logger: $logger);
        $this->userService     = $userService;
        $this->logger          = $logger;
        $this->l = $l;

        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;
    }//end __construct()

    /**
     * Implements a preflighted CORS response for OPTIONS requests on /api/user/me.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return Response The CORS response with appropriate headers.
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-4
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function preflightedCorsMe(): Response
    {
        return $this->buildCorsPreflightResponse();

    }//end preflightedCorsMe()

    /**
     * Implements a preflighted CORS response for OPTIONS requests on /api/user/login.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return Response The CORS response with appropriate headers.
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-4
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function preflightedCorsLogin(): Response
    {
        return $this->buildCorsPreflightResponse();

    }//end preflightedCorsLogin()

    /**
     * Build a CORS preflight response with credentials support.
     *
     * Used by both /api/user/me and /api/user/login OPTIONS preflights.
     *
     * @return Response
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-4
     */
    private function buildCorsPreflightResponse(): Response
    {
        $originHeader = $this->request->getHeader('Origin');
        if ($originHeader !== '') {
            $origin = $originHeader;
        } else {
            $origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');
        }

        // Credentials require a non-wildcard origin; fall back to the dev origin only when the
        // request truly has no Origin header (server-side test tooling).
        if ($origin === '*' && $this->request->getHeader('Origin') === '') {
            $origin = 'http://localhost:3000';
        }

        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'true');

        return $response;

    }//end buildCorsPreflightResponse()

    /**
     * Add CORS headers to a JSON response so browser clients receive cookies.
     *
     * @param JSONResponse $response The response to add CORS headers to.
     *
     * @return JSONResponse The response with CORS headers added.
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-4
     */
    private function addCorsHeaders(JSONResponse $response): JSONResponse
    {
        $originHeader = $this->request->getHeader('Origin');
        if ($originHeader !== '') {
            $origin = $originHeader;
        } else {
            $origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');
        }

        if ($origin === '*' && $this->request->getHeader('Origin') === '') {
            $origin = 'http://localhost:3000';
        }

        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'true');

        return $response;

    }//end addCorsHeaders()

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
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function me(): JSONResponse
    {
        try {
            // First try to get user from session.
            $currentUser = $this->userService->getCurrentUser();

            // If no session user, try basic authentication.
            if ($currentUser === null) {
                $authHeader = $this->request->getHeader('Authorization');
                if ($authHeader !== '' && str_starts_with($authHeader, 'Basic ') === true) {
                    $credentials = base64_decode(substr($authHeader, 6));
                    if ($credentials !== false && str_contains($credentials, ':') === true) {
                        [$username, $password] = explode(':', $credentials, 2);
                        $checked = $this->userManager->checkPassword($username, $password);
                        if ($checked !== false) {
                            $currentUser = $checked;
                        }
                    }
                }
            }

            // Check if user is authenticated (either via session or basic auth).
            if ($currentUser === null) {
                $response = new JSONResponse(
                    data: ['message' => $this->l->t('Current user is not logged in')],
                    statusCode: 401
                );
                $response = $this->securityService->addSecurityHeaders($response);
                return $this->addCorsHeaders(response: $response);
            }

            // Build user data array with essential information (already sanitized).
            $userData = $this->userService->buildUserDataArray($currentUser);

            $response = new JSONResponse($userData);
            $response = $this->securityService->addSecurityHeaders($response);
            return $this->addCorsHeaders(response: $response);
        } catch (\Exception $e) {
            // Log the error and return generic error response.
            $response = new JSONResponse(
                data: ['error' => $this->l->t('Failed to retrieve user information')],
                statusCode: 500
            );
            $response = $this->securityService->addSecurityHeaders($response);
            return $this->addCorsHeaders(response: $response);
        }//end try

    }//end me()

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
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function updateMe(): JSONResponse
    {
        try {
            // Get the current user from the session.
            $currentUser = $this->userService->getCurrentUser();

            // Check if user is logged in.
            if ($currentUser === null) {
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('User not authenticated')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Get and sanitize the request data to prevent XSS.
            $data          = $this->request->getParams();
            $sanitizedData = $this->securityService->sanitizeInput($data);

            // Remove system parameters that shouldn't be updated.
            foreach ($sanitizedData as $key => $value) {
                if (str_starts_with($key, '_') === true) {
                    unset($sanitizedData[$key]);
                }
            }

            // Update user properties based on provided data.
            $updateResult = $this->userService->updateUserProperties($currentUser, $sanitizedData);

            // Build updated user data array.
            $userData = $this->userService->buildUserDataArray($currentUser);

            // Add update result information to response.
            $responseData = $userData;
            if ($updateResult['organisation_updated'] === true) {
                $responseData['update_message'] = $updateResult['organisation_message'];
            }

            $response = new JSONResponse($responseData);
            return $this->securityService->addSecurityHeaders($response);
        } catch (\Exception $e) {
            // Log the error and return generic error response.
            $response = new JSONResponse(
                data: ['error' => $this->l->t('Failed to update user information')],
                statusCode: 500
            );
            return $this->securityService->addSecurityHeaders($response);
        }//end try
    }//end updateMe()

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
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-2
     */
    public function login(): JSONResponse
    {
        try {
            // MEMORY MONITORING: Check initial memory usage to prevent OOM.
            $initialMemoryUsage = memory_get_usage(true);
            $memoryLimit        = ini_get('memory_limit');

            // Convert memory limit to bytes for comparison.
            $memoryLimitBytes = $this->convertToBytes(memoryLimit: $memoryLimit);

            // If we're already using more than 80% of memory limit, return error.
            if ($memoryLimitBytes > 0 && $initialMemoryUsage > ($memoryLimitBytes * 0.8)) {
                $response = new JSONResponse(
                    // Service Unavailable.
                    data: ['error' => $this->l->t('Server memory usage too high, please try again later')],
                    statusCode: 503
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Get client IP address for rate limiting.
            $clientIp = $this->securityService->getClientIpAddress($this->request);

            // Get and sanitize login credentials from request.
            $data = $this->request->getParams();

            // Validate and sanitize credentials to prevent XSS attacks.
            $credentialValidation = $this->securityService->validateLoginCredentials($data);
            if ($credentialValidation['valid'] === false) {
                $response = new JSONResponse(
                    data: ['error' => $credentialValidation['error']],
                    statusCode: 400
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            $credentials = $credentialValidation['credentials'];
            $username    = $credentials['username'];
            $password    = $credentials['password'];

            // Check rate limiting before attempting authentication.
            $rateLimitCheck = $this->securityService->checkLoginRateLimit($username, $clientIp);
            if ($rateLimitCheck['allowed'] === false) {
                // Apply progressive delay if specified.
                if (isset($rateLimitCheck['delay']) === true) {
                    sleep($rateLimitCheck['delay']);
                }

                $response = new JSONResponse(
                    // Too Many Requests.
                    data: [
                        'error'         => $rateLimitCheck['reason'],
                        'retry_after'   => $rateLimitCheck['delay'] ?? null,
                        'lockout_until' => $rateLimitCheck['lockout_until'] ?? null,
                    ],
                    statusCode: 429
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Attempt to authenticate the user.
            $user = $this->userManager->checkPassword($username, $password);

            // Check if authentication was successful.
            if ($user === false) {
                // Record failed login attempt for rate limiting.
                $this->securityService->recordFailedLoginAttempt($username, $clientIp, 'invalid_credentials');

                // Return generic error message to prevent username enumeration.
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Invalid username or password')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Check if user account is enabled.
            if ($user->isEnabled() === false) {
                // Record failed login attempt for disabled account.
                $this->securityService->recordFailedLoginAttempt($username, $clientIp, 'account_disabled');

                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Account is disabled')],
                    statusCode: 401
                );
                return $this->securityService->addSecurityHeaders($response);
            }

            // Authentication successful - record success and clear rate limits.
            $this->securityService->recordSuccessfulLogin($username, $clientIp);

            // Set the user in the session using Nextcloud's session management.
            $this->userSession->setUser($user);

            // Create a complete login using Nextcloud's session-token flow so subsequent
            // requests with the returned cookies are recognised as authenticated.
            $this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID(), $password);

            // Verify the session was actually established.
            $sessionUser = $this->userSession->getUser();
            if ($sessionUser === null || $sessionUser->getUID() !== $user->getUID()) {
                $response = new JSONResponse(
                    data: ['error' => $this->l->t('Failed to establish persistent session')],
                    statusCode: 500
                );
                $response = $this->securityService->addSecurityHeaders($response);
                return $this->addCorsHeaders(response: $response);
            }

            // Build user data array for response (sanitized).
            $userData = $this->userService->buildUserDataArray($user);

            // MEMORY MONITORING: Check memory usage after building user data.
            $finalMemoryUsage    = memory_get_usage(true);
            $memoryIncreaseBytes = ($finalMemoryUsage - $initialMemoryUsage);

            // Log memory usage for monitoring.
            if ($memoryIncreaseBytes > (10 * 1024 * 1024)) {
                // 10MB threshold.
                $this->logger->warning(
                        'High memory usage during login',
                        [
                            'user'           => $user->getUID(),
                            'initial_memory' => $initialMemoryUsage,
                            'final_memory'   => $finalMemoryUsage,
                            'increase_bytes' => $memoryIncreaseBytes,
                            'increase_mb'    => round($memoryIncreaseBytes / (1024 * 1024), 2),
                        ]
                        );
            }

            // Surface the session id/name so browser clients can persist the cookie.
            $sessionId   = session_id();
            $sessionName = session_name();

            // Create successful response with security headers, CORS, and session info.
            $response = new JSONResponse(
                [
                    'message'         => $this->l->t('Login successful'),
                    'user'            => $userData,
                    'session_created' => true,
                    'session'         => [
                        'id'                  => $sessionId,
                        'name'                => $sessionName,
                        'cookie_instructions' => 'Use the returned session cookies for subsequent authenticated requests',
                    ],
                ]
            );

            $response = $this->securityService->addSecurityHeaders($response);
            return $this->addCorsHeaders(response: $response);
        } catch (\Exception $e) {
            // Log the actual error for debugging (sensitive info is in the trace, not the user response).
            $this->logger->error(
                'Login method exception',
                [
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            $response = new JSONResponse(
                data: ['error' => $this->l->t('Login failed due to a system error')],
                statusCode: 500
            );
            $response = $this->securityService->addSecurityHeaders($response);
            return $this->addCorsHeaders(response: $response);
        }//end try

    }//end login()

    /**
     * Convert PHP memory limit string to bytes.
     *
     * This helper method converts PHP memory limit strings (like "128M", "1G")
     * to bytes for memory usage comparisons.
     *
     * @param string $memoryLimit The memory limit string from PHP ini.
     *
     * @return integer The memory limit in bytes, or 0 if unlimited.
     *
     * @psalm-param    string $memoryLimit
     * @psalm-return   int
     * @phpstan-param  string $memoryLimit
     * @phpstan-return int
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-2
     */
    private function convertToBytes(string $memoryLimit): int
    {
        // If memory limit is -1, it means unlimited.
        if ($memoryLimit === '-1') {
            return 0;
        }

        // Convert the memory limit to bytes.
        $memoryLimit = trim($memoryLimit);
        $last        = strtolower($memoryLimit[(strlen($memoryLimit) - 1)]);
        $value       = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // Fall through.
            case 'm':
                $value *= 1024;
                // Fall through.
            case 'k':
                $value *= 1024;
        }

        return $value;

    }//end convertToBytes()

    /**
     * Logs out the user on the active user session
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @return          JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-3
     */
    public function logout(): JSONResponse
    {
        $this->userSession->logout();

        return new JSONResponse(['logout' => true]);
    }//end logout()
}//end class
