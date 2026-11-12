<?php

/**
 * Integriq UserController.
 *
 * This controller handles user-related API endpoints including user information
 * retrieval, updates, and authentication with comprehensive security measures.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\SecondFactorGate;
use OCA\Integriq\Service\SecurityServiceInterface;
use OCA\Integriq\Service\UserApiOriginPolicy;
use OCA\Integriq\Service\UserServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The 13th type is IUser, the user
 *   IUserManager::checkPassword() already hands login(); naming it in the login
 *   helpers' signatures adds no dependency the class did not have.
 */
class UserController extends Controller {

	/**
	 * Core brute-force throttler action for a refused login (api-f2).
	 *
	 * @var string
	 */
	private const LOGIN_THROTTLE_ACTION = 'integriq_api_login';

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
	 * @var SecurityServiceInterface
	 */
	private readonly SecurityServiceInterface $securityService;

	/**
	 * User service for user-related business logic
	 *
	 * @var UserServiceInterface
	 */
	private readonly UserServiceInterface $userService;

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
	 * Decides whether an account needs a second factor and so cannot log in here
	 * @var SecondFactorGate
	 */
	private readonly SecondFactorGate $secondFactorGate;

	/**
	 * Decides which Origin may be granted CORS access to these endpoints
	 * @var UserApiOriginPolicy
	 */
	private readonly UserApiOriginPolicy $originPolicy;

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
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object for handling HTTP requests
	 * @param IUserManager $userManager The user manager for user operations
	 * @param IUserSession $userSession The user session manager
	 * @param SecurityServiceInterface $securityService Rate limiting, input sanitising and security headers
	 * @param LoggerInterface $logger The logger for security events
	 * @param UserServiceInterface $userService The user service for user-related operations
	 * @param IL10N $l The localization service
	 * @param SecondFactorGate $secondFactorGate The second-factor check for the login
	 * @param UserApiOriginPolicy $originPolicy The allow-list of CORS origins
	 * @param string $corsMethods Allowed CORS methods
	 * @param string $corsAllowedHeaders Allowed CORS headers
	 * @param int $corsMaxAge CORS max age
	 *
	 * @psalm-param   string $appName
	 * @psalm-param   IRequest $request
	 * @psalm-param   IUserManager $userManager
	 * @psalm-param   IUserSession $userSession
	 * @psalm-param   SecurityServiceInterface $securityService
	 * @psalm-param   LoggerInterface $logger
	 * @psalm-param   UserServiceInterface $userService
	 * @psalm-param   IL10N $l
	 * @psalm-param   string $corsMethods
	 * @psalm-param   string $corsAllowedHeaders
	 * @psalm-param   int $corsMaxAge
	 * @phpstan-param string $appName
	 * @phpstan-param IRequest $request
	 * @phpstan-param IUserManager $userManager
	 * @phpstan-param IUserSession $userSession
	 * @phpstan-param SecurityServiceInterface $securityService
	 * @phpstan-param LoggerInterface $logger
	 * @phpstan-param UserServiceInterface $userService
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
		SecurityServiceInterface $securityService,
		LoggerInterface $logger,
		UserServiceInterface $userService,
		IL10N $l,
		SecondFactorGate $secondFactorGate,
		UserApiOriginPolicy $originPolicy,
		string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
		int $corsMaxAge = 1728000,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->securityService = $securityService;
		$this->userService = $userService;
		$this->logger = $logger;
		$this->l = $l;
		$this->secondFactorGate = $secondFactorGate;
		$this->originPolicy = $originPolicy;

		$this->corsMethods = $corsMethods;
		$this->corsAllowedHeaders = $corsAllowedHeaders;
		$this->corsMaxAge = $corsMaxAge;
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
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 480, period: 60)]
	public function preflightedCorsMe(): Response {
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
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 480, period: 60)]
	public function preflightedCorsLogin(): Response {
		return $this->buildCorsPreflightResponse();
	}//end preflightedCorsLogin()

	/**
	 * Build a CORS preflight response with credentials support.
	 *
	 * Used by both /api/user/me and /api/user/login OPTIONS preflights.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function buildCorsPreflightResponse(): Response {
		$response = new Response();
		$origin = $this->allowedCorsOrigin();
		if ($origin === null) {
			// Not an allow-listed origin: no CORS grant, so the browser refuses
			// the cross-origin call. Same-origin callers do not need one.
			return $response;
		}

		$response->addHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Vary', 'Origin');
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'true');

		return $response;
	}//end buildCorsPreflightResponse()

	/**
	 * The request's Origin when the administrator allow-listed it, null otherwise.
	 *
	 * These responses carry `Access-Control-Allow-Credentials: true`, so the
	 * origin they grant can read the signed-in user's profile and drive the
	 * login with the browser's cookies. Reflecting whatever Origin arrived gave
	 * that to every site. Only origins listed (comma or whitespace separated, as
	 * `scheme://host[:port]`) in the app setting `user_api_allowed_origins` are
	 * granted; the list is empty by default, which grants none.
	 *
	 * @return string|null The origin to grant, or null for no CORS grant.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function allowedCorsOrigin(): ?string {
		return $this->originPolicy->grantFor(origin: $this->request->getHeader('Origin'));
	}//end allowedCorsOrigin()

	/**
	 * Add CORS headers to a JSON response so browser clients receive cookies.
	 *
	 * @param JSONResponse $response The response to add CORS headers to.
	 *
	 * @return JSONResponse The response with CORS headers added.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function addCorsHeaders(JSONResponse $response): JSONResponse {
		$origin = $this->allowedCorsOrigin();
		if ($origin === null) {
			return $response;
		}

		$response->addHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Vary', 'Origin');
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
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function me(): JSONResponse {
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
					statusCode: Http::STATUS_UNAUTHORIZED
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
	 * @no-admin-idor-exempt Acts on the session's own account only: the user comes from
	 *   UserService::getCurrentUser() and no account or object id is read from the request.
	 *   SecurityService only sanitises input and adds headers.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateMe(): JSONResponse {
		try {
			// Get the current user from the session.
			$currentUser = $this->userService->getCurrentUser();

			// Check if user is logged in.
			if ($currentUser === null) {
				$response = new JSONResponse(
					data: ['error' => $this->l->t('User not authenticated')],
					statusCode: Http::STATUS_UNAUTHORIZED
				);
				return $this->securityService->addSecurityHeaders($response);
			}

			// Get and sanitize the request data to prevent XSS.
			$data = $this->request->getParams();
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
	 * RATE-LIMIT RATIONALE (ADR-082). A LOGIN endpoint, so much tighter than
	 * anything else in this app: this is the one public surface here where the
	 * caller presents a credential a stranger could guess.
	 *
	 * Brute-force protection has two layers. SecurityService::checkLoginRateLimit()
	 * gates entry (with a lockout and a delay), recordFailedLoginAttempt() feeds
	 * it on every failure path, and recordSuccessfulLogin() clears it, keyed on
	 * USERNAME + IP. BruteForceProtection adds core's per-IP throttler on top:
	 * every refused password guess calls throttle(), which core only registers
	 * when the method carries the attribute.
	 *
	 * An account that needs a second factor is refused with exactly the answer a
	 * wrong password gets (same status, message and headers) and counts as a
	 * failed attempt, so the refusal does not confirm a guessed password; the
	 * real reason is only logged.
	 *
	 * The AnonRateLimit ceiling below is the volume half, which app-local logic
	 * did not cover.
	 *
	 * @return JSONResponse A JSON response containing login result and user information
	 *
	 * @psalm-return   JSONResponse
	 * @phpstan-return JSONResponse
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::LOGIN_THROTTLE_ACTION)]
	public function login(): JSONResponse {
		try {
			// MEMORY MONITORING: Check initial memory usage to prevent OOM.
			$initialMemoryUsage = memory_get_usage(true);
			$memoryLimitBytes = $this->convertToBytes(memoryLimit: ini_get('memory_limit'));

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

			// Validate and sanitize credentials to prevent XSS attacks.
			$credentialValidation = $this->securityService->validateLoginCredentials($this->request->getParams());
			if ($credentialValidation['valid'] === false) {
				$response = new JSONResponse(
					data: ['error' => $credentialValidation['error']],
					statusCode: 400
				);
				return $this->securityService->addSecurityHeaders($response);
			}

			$credentials = $credentialValidation['credentials'];
			$user = $this->authenticateLogin(credentials: $credentials, clientIp: $clientIp);
			if ($user instanceof JSONResponse === true) {
				return $user;
			}

			// This endpoint creates a session without core's login chain, so it cannot
			// ask for a second factor. An account that has one (or must have one)
			// is refused here and logs in through Nextcloud's own login flow.
			// It gets the wrong-password answer, so the refusal does not tell an
			// unauthenticated caller that the guessed password was right.
			if ($this->secondFactorGate->requiresSecondFactor(user: $user) === true) {
				$this->logger->info(
					'Integriq API login refused: account requires two-factor authentication',
					['user' => $user->getUID(), 'ip' => $clientIp]
				);
				$this->securityService->recordFailedLoginAttempt($credentials['username'], $clientIp, 'second_factor_required');
				return $this->invalidCredentialsResponse();
			}

			// Authentication successful - record success and clear rate limits.
			$this->securityService->recordSuccessfulLogin($credentials['username'], $clientIp);

			$sessionFailure = $this->establishLoginSession(user: $user, password: $credentials['password']);
			if ($sessionFailure !== null) {
				return $sessionFailure;
			}

			return $this->loginSuccessResponse(user: $user, initialMemoryUsage: $initialMemoryUsage);
		} catch (\Exception $e) {
			// Log the actual error for debugging (sensitive info is in the trace, not the user response).
			$this->logger->error(
				'Login method exception',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
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
	 * Check the login rate limit and the credentials, and that the account is enabled.
	 *
	 * Both failures are recorded for the rate limit. A wrong password answers 401,
	 * not 400: the request is well formed, the authentication failed. The spec
	 * ("Failed login is rate-limited and anti-enumeration") reserves 400 for
	 * validateLoginCredentials(). A disabled account answers exactly like a wrong
	 * password, because checkPassword() succeeds for it and a distinct answer
	 * would confirm the guessed password (pp-f2); the real reason is logged. The
	 * message is generic, so it does not reveal whether the username exists.
	 *
	 * @param array  $credentials The sanitized `username` and `password`.
	 * @param string $clientIp    The client IP address.
	 *
	 * @return IUser|JSONResponse The user, or the 429 or 401 response.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function authenticateLogin(array $credentials, string $clientIp): IUser|JSONResponse {
		$username = $credentials['username'];

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
					'error' => $rateLimitCheck['reason'],
					'retry_after' => $rateLimitCheck['delay'] ?? null,
					'lockout_until' => $rateLimitCheck['lockout_until'] ?? null,
				],
				statusCode: 429
			);
			return $this->securityService->addSecurityHeaders($response);
		}

		$user = $this->userManager->checkPassword($username, $credentials['password']);
		if ($user === false) {
			$this->securityService->recordFailedLoginAttempt($username, $clientIp, 'invalid_credentials');

			return $this->invalidCredentialsResponse();
		}

		if ($user->isEnabled() === false) {
			$this->logger->info(
				'Integriq API login refused: account is disabled',
				['user' => $user->getUID(), 'ip' => $clientIp]
			);
			$this->securityService->recordFailedLoginAttempt($username, $clientIp, 'account_disabled');

			return $this->invalidCredentialsResponse();
		}

		return $user;
	}//end authenticateLogin()

	/**
	 * The answer to a refused password: 401 with a generic message, throttled.
	 *
	 * Used for a wrong password, a disabled account and an account that needs a
	 * second factor, so they cannot be told apart by an unauthenticated caller.
	 *
	 * @return JSONResponse The 401 response.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function invalidCredentialsResponse(): JSONResponse {
		$response = new JSONResponse(
			data: ['error' => $this->l->t('Invalid username or password')],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
		$response->throttle(['action' => self::LOGIN_THROTTLE_ACTION]);

		return $this->securityService->addSecurityHeaders($response);
	}//end invalidCredentialsResponse()

	/**
	 * Put the user in the session and create a session token, so later requests
	 * with the returned cookies are recognised as authenticated.
	 *
	 * @param IUser  $user     The authenticated user.
	 * @param string $password The password the user logged in with.
	 *
	 * @return JSONResponse|null The 500 response when the session did not take, null otherwise.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function establishLoginSession(IUser $user, string $password): ?JSONResponse {
		$this->userSession->setUser($user);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID(), $password);

		// Verify the session was actually established.
		$sessionUser = $this->userSession->getUser();
		if ($sessionUser !== null && $sessionUser->getUID() === $user->getUID()) {
			return null;
		}

		$response = new JSONResponse(
			data: ['error' => $this->l->t('Failed to establish persistent session')],
			statusCode: 500
		);
		$response = $this->securityService->addSecurityHeaders($response);
		return $this->addCorsHeaders(response: $response);
	}//end establishLoginSession()

	/**
	 * The 200 answer to a login: the sanitized user data, and the session id and
	 * name so browser clients can persist the cookie, with security and CORS headers.
	 *
	 * A warning is logged when building the user data took more than 10 MB of memory.
	 *
	 * @param IUser $user               The logged-in user.
	 * @param int         $initialMemoryUsage The memory in use when the login started, in bytes.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function loginSuccessResponse(IUser $user, int $initialMemoryUsage): JSONResponse {
		$userData = $this->userService->buildUserDataArray($user);

		$finalMemoryUsage = memory_get_usage(true);
		$memoryIncreaseBytes = ($finalMemoryUsage - $initialMemoryUsage);
		if ($memoryIncreaseBytes > (10 * 1024 * 1024)) {
			$this->logger->warning(
				'High memory usage during login',
				[
					'user' => $user->getUID(),
					'initial_memory' => $initialMemoryUsage,
					'final_memory' => $finalMemoryUsage,
					'increase_bytes' => $memoryIncreaseBytes,
					'increase_mb' => round($memoryIncreaseBytes / (1024 * 1024), 2),
				]
			);
		}

		$response = new JSONResponse(
			[
				'message' => $this->l->t('Login successful'),
				'user' => $userData,
				'session_created' => true,
				'session' => [
					'id' => session_id(),
					'name' => session_name(),
					'cookie_instructions' => 'Use the returned session cookies for subsequent authenticated requests',
				],
			]
		);

		$response = $this->securityService->addSecurityHeaders($response);
		return $this->addCorsHeaders(response: $response);
	}//end loginSuccessResponse()

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
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function convertToBytes(string $memoryLimit): int {
		// If memory limit is -1, it means unlimited.
		if ($memoryLimit === '-1') {
			return 0;
		}

		// Convert the memory limit to bytes.
		$memoryLimit = trim($memoryLimit);
		$last = strtolower($memoryLimit[(strlen($memoryLimit) - 1)]);
		$value = (int)$memoryLimit;

		switch ($last) {
			case 'g':
				$value *= 1024;
				// Fall through.
				// no break
			case 'm':
				$value *= 1024;
				// Fall through.
				// no break
			case 'k':
				$value *= 1024;
		}

		return $value;
	}//end convertToBytes()

	/**
	 * Logs out the user on the active user session
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function logout(): JSONResponse {
		$this->userSession->logout();

		return new JSONResponse(['logout' => true]);
	}//end logout()
}//end class
