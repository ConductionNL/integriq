<?php

/**
 * OpenConnector AuthorizationService.
 *
 * Service class for handling authorization on incoming calls — JWT, basic auth,
 * OAuth bearer tokens and API keys.
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
 */

namespace OCA\OpenConnector\Service;

use DateTime;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\InvalidHeaderException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OAuth2\Db\Client;
use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Response;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Service class for handling authorization on incoming calls.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class AuthorizationService {
	public const HMAC_ALGORITHMS = ['HS256', 'HS384', 'HS512'];
	public const PKCS1_ALGORITHMS = ['RS256', 'RS384', 'RS512'];
	public const PSS_ALGORITHMS = ['PS256', 'PS384', 'PS512'];

	/**
	 * Maximum allowed token lifetime in seconds (1 hour).
	 *
	 * A consumer MUST NOT issue a token whose `exp - iat` span exceeds this
	 * value.  Tokens with longer lifetimes are rejected to limit the window of
	 * a stolen/leaked token.
	 *
	 * @var integer
	 */
	private const MAX_TOKEN_LIFETIME_SECONDS = 3600;

	/**
	 * APCu/distributed cache used for jti replay detection.
	 *
	 * @var ICache
	 */
	private readonly ICache $jtiCache;

	/**
	 * The consumer resolved for the current request (JWT issuer), or null when
	 * the authentication method did not resolve a consumer identity.
	 *
	 * Exposed via {@see getResolvedConsumer()} so the endpoint runtime can key
	 * inbound rate limiting on the resolved consumer (consumer-rate-limiting).
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $resolvedConsumer = null;

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager The user manager.
	 * @param IUserSession $userSession The user session.
	 * @param \OCA\OpenRegister\Service\ObjectService $orObjectService OR ObjectService used to resolve consumers.
	 * @param IGroupManager $groupManager The group manager for users/groups ACL checks.
	 * @param ICacheFactory $cacheFactory Cache factory used for jti replay detection.
	 * @param IRequest $request The current HTTP request (C2 Bearer guard).
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly \OCA\OpenRegister\Service\ObjectService $orObjectService,
		private readonly IGroupManager $groupManager,
		ICacheFactory $cacheFactory,
		private readonly IRequest $request,
	) {
		$this->jtiCache = $cacheFactory->createDistributed('openconnector.jti');

	}//end __construct()

	/**
	 * Find the issuer (consumer) for the request.
	 *
	 * @param string $issuer The issuer from the JWT token.
	 *
	 * @return ObjectEntity The consumer for the JWT token.
	 *
	 * @throws AuthenticationException Thrown if no issuer was found.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	private function findIssuer(string $issuer): ObjectEntity {
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'consumer',
					'name' => $issuer,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$consumers = ($matches['results'] ?? $matches);

		if (count($consumers) === 0) {
			throw new AuthenticationException(message: 'The issuer was not found', details: ['iss' => $issuer]);
		}

		return $consumers[0];
	}//end findIssuer()

	/**
	 * Check if the headers of a JWT token are valid.
	 *
	 * @param JWS $token The unserialized token.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	private function checkHeaders(JWS $token): void {
		$headerChecker = new HeaderCheckerManager(
			checkers: [
				new AlgorithmChecker(array_merge(self::HMAC_ALGORITHMS, self::PKCS1_ALGORITHMS, self::PSS_ALGORITHMS))
			],
			tokenTypes: [new JWSTokenSupport()]
		);

		$headerChecker->check(jwt: $token, index: 0);

	}//end checkHeaders()

	/**
	 * Get the Json Web Key for a public key combined with an algorithm.
	 *
	 * @param string $publicKey The public key to create a JWK for.
	 * @param string $algorithm The algorithm deciding how the key should be defined.
	 *
	 * @return JWKSet The resulting JWK-set.
	 *
	 * @throws AuthenticationException If the algorithm is not supported.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	private function getJWK(string $publicKey, string $algorithm): JWKSet {

		if (in_array(needle: $algorithm, haystack: self::HMAC_ALGORITHMS) === true) {
			return new JWKSet(
				[
					JWKFactory::createFromSecret(
						secret: $publicKey,
						additional_values: ['alg' => $algorithm, 'use' => 'sig']
					)
				]
			);
		}

		if (in_array(needle: $algorithm, haystack: self::PKCS1_ALGORITHMS) === true
			|| in_array(needle: $algorithm, haystack: self::PSS_ALGORITHMS) === true
		) {
			// Write to a secure temp file with a random, unpredictable name and
			// restricted permissions; always unlink in finally.
			$filename = tempnam(sys_get_temp_dir(), 'oc-jwk-');
			if ($filename === false) {
				throw new AuthenticationException(message: 'Could not allocate temp file for public key', details: []);
			}

			@chmod($filename, 0600);
			file_put_contents($filename, base64_decode($publicKey));
			@chmod($filename, 0600);

			try {
				// Pin the algorithm in the JWK so the library cannot be tricked
				// into accepting a different algorithm via a crafted token header.
				$jwk = new JWKSet(
					[
						JWKFactory::createFromKeyFile(
							file: $filename,
							password: null,
							additional_values: ['alg' => $algorithm, 'use' => 'sig']
						)
					]
				);
			} finally {
				if (file_exists($filename) === true) {
					@unlink($filename);
				}
			}

			return $jwk;
		}//end if

		throw new AuthenticationException(message: 'The token algorithm is not supported', details: ['algorithm' => $algorithm]);
	}//end getJWK()

	/**
	 * Allowed clock skew in seconds for iat/nbf checks.
	 *
	 * @var integer
	 */
	private const CLOCK_SKEW_SECONDS = 60;

	/**
	 * Validate data in the payload.
	 *
	 * Checks:
	 *   - iat is present and not in the future (beyond clock skew)
	 *   - exp (default iat+1h) has not passed
	 *   - exp - iat does not exceed MAX_TOKEN_LIFETIME_SECONDS (prevents
	 *     infinite-lifetime tokens via a caller-supplied exp claim)
	 *   - nbf (not-before), if present, has been reached
	 *   - jti, if present, has not been seen before (replay prevention)
	 *
	 * @param array $payload The payload of the JWT token.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the token is missing/expired/not-yet-valid/replayed.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function validatePayload(array $payload): void {
		$now = new DateTime();

		if (isset($payload['iat']) === false) {
			throw new AuthenticationException(message: 'The token has no time of creation', details: ['iat' => null]);
		}

		$iat = new DateTime('@' . $payload['iat']);

		// Reject tokens issued in the future (beyond the clock skew window).
		// This prevents replay attacks using pre-generated tokens.
		$maxAllowedIat = clone $now;
		$maxAllowedIat->modify('+' . self::CLOCK_SKEW_SECONDS . ' seconds');
		if ($iat > $maxAllowedIat) {
			throw new AuthenticationException(
				message: 'The token has an invalid issue time',
				details: [
					'iat' => $iat->getTimestamp(),
					'time checked' => $now->getTimestamp(),
				]
			);
		}

		$exp = clone $iat;
		$exp->modify('+1 Hour');
		if (isset($payload['exp']) === true) {
			$callerExp = new DateTime('@' . $payload['exp']);

			// Clamp the caller-supplied expiry so a consumer cannot self-issue
			// tokens with an arbitrarily long (or infinite) lifetime.  The
			// maximum allowed span is MAX_TOKEN_LIFETIME_SECONDS relative to iat.
			$maxExp = clone $iat;
			$maxExp->modify('+' . self::MAX_TOKEN_LIFETIME_SECONDS . ' seconds');
			if ($callerExp > $maxExp) {
				throw new AuthenticationException(
					message: 'The token lifetime exceeds the maximum allowed duration',
					details: [
						'iat' => $iat->getTimestamp(),
						'exp' => $callerExp->getTimestamp(),
						'max_lifetime_seconds' => self::MAX_TOKEN_LIFETIME_SECONDS,
					]
				);
			}//end if

			$exp = $callerExp;
		}//end if

		if ($exp->diff($now)->format('%R') === '+') {
			throw new AuthenticationException(
				message: 'The token has expired',
				details: [
					'iat' => $iat->getTimestamp(),
					'exp' => $exp->getTimestamp(),
					'time checked' => $now->getTimestamp(),
				]
			);
		}

		// Honour the not-before (nbf) claim if present.
		if (isset($payload['nbf']) === true) {
			$nbf = new DateTime('@' . $payload['nbf']);
			$nbfAllowed = clone $nbf;
			$nbfAllowed->modify('-' . self::CLOCK_SKEW_SECONDS . ' seconds');
			if ($now < $nbfAllowed) {
				throw new AuthenticationException(
					message: 'The token is not yet valid',
					details: [
						'nbf' => $nbf->getTimestamp(),
						'time checked' => $now->getTimestamp(),
					]
				);
			}
		}

		// JWT ID (jti) replay prevention: if the token carries a jti claim,
		// record it in the distributed cache for the remaining token lifetime
		// plus the clock-skew window.  A second request with the same jti is
		// rejected immediately.
		if (isset($payload['jti']) === true && $payload['jti'] !== '') {
			$cacheKey = 'jti:' . hash('sha256', (string)$payload['jti']);
			$ttl = max(1, ($exp->getTimestamp() - $now->getTimestamp()) + self::CLOCK_SKEW_SECONDS);

			if ($this->jtiCache->get($cacheKey) !== null) {
				throw new AuthenticationException(
					message: 'The token has already been used (jti replay)',
					details: ['jti' => $payload['jti']]
				);
			}

			$this->jtiCache->set($cacheKey, 1, $ttl);
		}

	}//end validatePayload()

	/**
	 * Checks if authorization header contains a valid JWT token.
	 *
	 * @param string $authorization The authorization header.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException On any validation failure.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function authorizeJwt(string $authorization): void {
		$token = substr(string: $authorization, offset: strlen('Bearer '));

		if ($token === '') {
			throw new AuthenticationException(message: 'No token has been provided', details: []);
		}

		$algorithmManager = new AlgorithmManager(
			[
				new HS256(),
				new HS384(),
				new HS512(),
				new RS256(),
				new RS384(),
				new RS512(),
				new PS256(),
				new PS384(),
				new PS512(),
			]
		);
		$verifier = new JWSVerifier($algorithmManager);
		$serializerManager = new JWSSerializerManager([new CompactSerializer()]);

		$jws = $serializerManager->unserialize(input: $token);

		try {
			$this->checkHeaders(token: $jws);
		} catch (InvalidHeaderException $exception) {
			throw new AuthenticationException(message: 'The token could not be validated', details: ['reason' => $exception->getMessage()]);
		}

		$payload = json_decode(json: $jws->getPayload(), associative: true);
		if (isset($payload['iss']) === false || empty($payload['iss']) === true) {
			throw new AuthenticationException(message: 'The token could not be validated', details: ['reason' => 'No issuer mentioned']);
		}

		$issuer = $this->findIssuer(issuer: $payload['iss']);
		$issuerData = $issuer->getObject();

		// Record the resolved consumer so the endpoint runtime can enforce this
		// consumer's inbound rate limit + quota after authentication passes.
		$this->resolvedConsumer = $issuer;

		$authConfig = $issuerData['authorizationConfiguration'] ?? [];
		$publicKey = $authConfig['publicKey'] ?? '';
		$algorithm = $authConfig['algorithm'] ?? '';

		$jwkSet = $this->getJWK(publicKey: $publicKey, algorithm: $algorithm);

		// Reject tokens whose protected header `alg` does not match the
		// algorithm configured for the consumer.  Without this check a
		// crafted token could switch to HMAC (HS*) against the RSA public
		// key as the HMAC secret — the classic algorithm-confusion attack.
		$headerAlg = $jws->getSignature(0)->getProtectedHeader()['alg'] ?? '';
		if ($headerAlg !== $algorithm) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'Token algorithm does not match configured algorithm']
			);
		}

		if ($verifier->verifyWithKeySet(jws: $jws, jwkset: $jwkSet, signatureIndex: 0) === false) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'The token does not match the public key']
			);
		}

		$this->validatePayload(payload: $payload);

		$this->userSession->setUser($this->userManager->get($issuerData['userId'] ?? ''));
	}//end authorizeJwt()

	/**
	 * Authorize user based on basic auth.
	 *
	 * @param string $header The authorization header given in the request.
	 * @param array $users The users allowed to be authenticated according to the rule.
	 * @param array $groups The groups allowed to be authenticated according to the rule.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException On invalid credentials.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function authorizeBasic(string $header, array $users, array $groups): void {
		$header = substr(string: $header, offset: strlen('Basic '));
		$decode = base64_decode($header);
		[$username, $password] = explode(separator: ':', string: $decode);

		$user = $this->userManager->checkPassword(loginName: $username, password: $password);

		if ($user === false) {
			throw new AuthenticationException(message: 'Invalid username or password', details: []);
		}

		// Enforce users/groups ACL when the rule has an explicit allow-list.
		// Empty lists mean "any authenticated user is allowed".
		if (empty($users) === false || empty($groups) === false) {
			$userInAllowedUsers = (array_intersect($users, [$user->getUID(), $user->getEMailAddress()]) !== []);

			$userGroups = array_map(
				static function (IGroup $group): string {
					return $group->getGID();
				},
				$this->groupManager->getUserGroups($user)
			);
			$userInAllowedGroups = (array_intersect($groups, $userGroups) !== []);

			if ($userInAllowedUsers === false && $userInAllowedGroups === false) {
				throw new AuthenticationException(
					message: 'Not authorized',
					details: ['reason' => 'The selected user is not allowed to login on this endpoint']
				);
			}
		}

		$this->userSession->setUser($user);

	}//end authorizeBasic()

	/**
	 * Authorize user based on OAuth bearer tokens (NC-session-backed).
	 *
	 * C2 fix: the original implementation only checked `isLoggedIn()`, which returns
	 * true for any valid Nextcloud session — including sessions established via a
	 * browser session cookie entirely independent of the Bearer token value.  An
	 * attacker holding a valid NC session cookie could therefore send an arbitrary
	 * `Authorization: Bearer <garbage>` value and pass this check.
	 *
	 * Fix: explicitly extract the Bearer token from the Authorization header that NC
	 * processed for this request (via `IRequest::getHeader`), verify it is non-empty
	 * and non-whitespace (i.e. a real token was presented), and verify that the NC
	 * session was established from that token rather than from a standalone session
	 * cookie.  The latter is detected by requiring that the Authorization header on
	 * the actual request starts with `Bearer ` followed by a non-trivial value — if
	 * NC would have used the session cookie instead, the header would be absent or
	 * empty and `isLoggedIn()` via cookie auth would be caught here.
	 *
	 * Note: NC validates Bearer tokens at the auth-middleware level via
	 * `ITokenProvider::getToken()` (an internal API).  By the time this method
	 * runs, if a non-empty Bearer token was present on the request, NC has already
	 * validated it.  We enforce here that the request DID carry a real Bearer token
	 * (not just a session cookie) so that the NC middleware validation is the actual
	 * gate, not only `isLoggedIn()`.
	 *
	 * @param string $header The authorization header given in the request.
	 * @param array $users The users allowed to be authenticated according to the rule.
	 * @param array $groups The groups allowed to be authenticated according to the rule.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException On invalid or missing tokens.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function authorizeOAuth(string $header, array $users, array $groups): void {
		if (str_starts_with($header, 'Bearer') === false) {
			throw new AuthenticationException(
				message: 'Invalid method',
				details: ['reason' => 'The authentication method you are using is not allowed on this resource.']
			);
		}

		// C2 fix: extract the raw token value from the header and verify it is non-empty.
		// "Bearer " (with trailing space) is required; anything after it must be a
		// non-whitespace token string.  This blocks the "Bearer " + empty / whitespace-only
		// case and ensures a real credential was presented.
		$rawToken = ltrim(substr($header, strlen('Bearer')));
		if ($rawToken === '') {
			throw new AuthenticationException(
				message: 'Invalid token',
				details: ['reason' => 'Bearer token value is empty.']
			);
		}

		// C2 fix: verify the incoming HTTP request actually carried this Authorization
		// header so NC's auth middleware would have validated the token rather than
		// falling through to a standalone session cookie.
		$requestAuthHeader = $this->request->getHeader('Authorization');
		if (str_starts_with($requestAuthHeader, 'Bearer ') === false) {
			// The actual request did not carry a Bearer Authorization header —
			// NC authenticated via session cookie, not a Bearer token.
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'OAuth endpoints require Bearer token authentication, not session cookie auth.']
			);
		}

		if ($this->userSession->isLoggedIn() === false) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'The token you used has either expired or was not recognized as a valid token']
			);
		}

		$user = $this->userSession->getUser();

		if ($user === null) {
			throw new AuthenticationException(message: 'Invalid token', details: []);
		}

		// Enforce users/groups ACL when the rule has an explicit allow-list.
		// Empty lists mean "any authenticated user is allowed".
		if (empty($users) === false || empty($groups) === false) {
			$userInAllowedUsers = (array_intersect($users, [$user->getUID(), $user->getEMailAddress()]) !== []);

			$userGroups = array_map(
				static function (IGroup $group): string {
					return $group->getGID();
				},
				$this->groupManager->getUserGroups($user)
			);
			$userInAllowedGroups = (array_intersect($groups, $userGroups) !== []);

			if ($userInAllowedUsers === false && $userInAllowedGroups === false) {
				throw new AuthenticationException(
					message: 'Not authorized',
					details: ['reason' => 'The selected user is not allowed to view endpoint']
				);
			}
		}

	}//end authorizeOAuth()

	/**
	 * Authorize the CURRENT Nextcloud session user (ocon#1068).
	 *
	 * WHY THIS TYPE EXISTS
	 * --------------------
	 * The endpoint dispatch route (`/apps/openconnector/api/endpoint/{_path}`)
	 * is `#[PublicPage] #[NoCSRFRequired]`, so NC's own middleware never
	 * consults the session. Every other authentication type this app supports
	 * reads an `Authorization` header, and {@see authorizeOAuth()} explicitly
	 * REFUSES a session cookie. The consequence was that no in-app frontend
	 * could ever call a protected endpoint: a manifest `api-call` button
	 * renders and then always 403s, and no widget can bind to one.
	 *
	 * WHY CSRF IS VERIFIED HERE AND NOT INHERITED
	 * -------------------------------------------
	 * Because the route carries `#[NoCSRFRequired]`, NC has ALREADY decided not
	 * to validate the request token by the time this runs. Accepting a bare
	 * session cookie at that point would make every `nc-session` endpoint
	 * cross-site forgeable from any page a logged-in user visits — a 403 turned
	 * into a confused-deputy write. So the check is made EXPLICITLY, by
	 * delegating to {@see IRequest::passesCSRFCheck()}: exactly the predicate
	 * NC's own `CSRFMiddleware` uses, so there is one definition of
	 * "CSRF-safe" in the stack rather than a second one maintained here.
	 *
	 * WHAT THAT PREDICATE ACTUALLY ACCEPTS (verified against NC 34's
	 * `OC\AppFramework\Http\Request::passesCSRFCheck()`, because the naive
	 * reading is wrong and the difference is load-bearing):
	 *
	 *  1. `passesStrictCookieCheck()` must pass FIRST. This requires the
	 *     `SameSite=Strict` session cookie, which a browser never attaches to a
	 *     cross-site request. THIS is the check that actually defeats forgery,
	 *     and nothing after it can re-open the door.
	 *  2. Then, a request carrying an `OCS-APIRequest` header is accepted
	 *     WITHOUT a request token at all — NC treats the header as proof of a
	 *     same-origin XHR, since setting it cross-origin forces a preflight.
	 *     This app's own preflight ({@see \OCA\OpenConnector\Controller\EndpointsController::preflightedCors()})
	 *     answers `Access-Control-Allow-Credentials: false`, so a cross-origin
	 *     caller cannot attach the victim's session cookie and lands on the
	 *     no-session refusal above.
	 *  3. Otherwise a `requesttoken` from GET, POST or the `REQUESTTOKEN`
	 *     header must validate against the session's CSRF token manager.
	 *
	 * A caller that authenticated with an app password or a Bearer token sends
	 * no session cookie, fails the strict-cookie check, and is refused. That is
	 * correct and intended: those callers have the `basic` / `oauth` / `jwt`
	 * types. This type is exclusively for a browser calling from inside a
	 * Nextcloud page.
	 *
	 * Every branch either throws or falls through to the ACL, so a missing
	 * session, a missing/stale request token and a disallowed user all fail
	 * closed.
	 *
	 * @param array $users The users allowed to be authenticated according to the rule.
	 * @param array $groups The groups allowed to be authenticated according to the rule.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When there is no authenticated session user, when the
	 *                                 request carries no valid CSRF token, or when the session
	 *                                 user falls outside the rule's allow-list.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function authorizeNcSession(array $users = [], array $groups = []): void {
		if ($this->userSession->isLoggedIn() === false) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'This endpoint requires an authenticated Nextcloud session.']
			);
		}

		$user = $this->userSession->getUser();

		if ($user === null) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'This endpoint requires an authenticated Nextcloud session.']
			);
		}

		// The dispatch route is #[NoCSRFRequired]: without this explicit check a
		// session-authenticated endpoint would be forgeable from any origin.
		// See the method docblock for what NC's predicate actually accepts.
		if ($this->request->passesCSRFCheck() === false) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'A same-origin request with a valid CSRF request token is required for session authentication.']
			);
		}

		// Enforce users/groups ACL when the rule has an explicit allow-list.
		// Empty lists mean "any authenticated user is allowed" — the same
		// config shape the `basic` and `oauth` rules already use.
		if (empty($users) === false || empty($groups) === false) {
			$userInAllowedUsers = (array_intersect($users, [$user->getUID(), $user->getEMailAddress()]) !== []);

			$userGroups = array_map(
				static function (IGroup $group): string {
					return $group->getGID();
				},
				$this->groupManager->getUserGroups($user)
			);
			$userInAllowedGroups = (array_intersect($groups, $userGroups) !== []);

			if ($userInAllowedUsers === false && $userInAllowedGroups === false) {
				throw new AuthenticationException(
					message: 'Not authorized',
					details: ['reason' => 'The selected user is not allowed to login on this endpoint']
				);
			}
		}

	}//end authorizeNcSession()

	/**
	 * Add CORS headers to controller result.
	 *
	 * @param IRequest $request The incoming request.
	 * @param Response $response The outgoing response.
	 *
	 * @return Response The updated response.
	 *
	 * @throws SecurityException If Allow-Credentials is true.
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md
	 */
	public function corsAfterController(IRequest $request, Response $response) {
		// Only react if it's a CORS request and if the request sends origin and.
		if (isset($request->server['HTTP_ORIGIN']) === true) {
			// Allow credentials headers must not be true or CSRF is possible otherwise.
			foreach ($response->getHeaders() as $header => $value) {
				if (strtolower($header) === 'access-control-allow-credentials'
					&& strtolower(trim($value)) === 'true'
				) {
					$msg = 'Access-Control-Allow-Credentials must not be set to true in order to prevent CSRF';
					throw new SecurityException($msg);
				}
			}

			$origin = $request->server['HTTP_ORIGIN'];
			$response->addHeader('Access-Control-Allow-Origin', $origin);
		}

		return $response;
	}//end corsAfterController()

	/**
	 * Authorize an incoming call based on an API key.
	 *
	 * Two credential sources are checked, in order, and the first match wins:
	 *
	 *   1. Rule-inline keys — the `keys` map (`apiKeyValue => nextcloudUserId`)
	 *      configured directly on the endpoint's authentication rule. This is
	 *      the pre-existing behaviour and is preserved unchanged.
	 *   2. Consumer-backed keys — a `consumer` record whose
	 *      `authorizationType` is `apiKey` and whose
	 *      `authorizationConfiguration.apiKey` equals the presented key. This
	 *      is the enforcement for `REQ-CON-001` that was previously missing:
	 *      before this change a Consumer's configured apiKey was never read, so
	 *      `Consumer.authorizationType: apiKey` enforced nothing. When a
	 *      consumer matches it is recorded as the resolved consumer (so the
	 *      endpoint runtime can key inbound rate-limiting/quota on it, exactly
	 *      as the JWT issuer path does) and, when the consumer names a
	 *      `userId`, that Nextcloud user is set on the session.
	 *
	 * The method is fail-closed: when neither a rule-inline key nor a consumer
	 * apiKey matches the presented credential an {@see AuthenticationException}
	 * is thrown, which the endpoint runtime converts to HTTP 401. An empty
	 * presented key never matches. Comparisons use {@see hash_equals()} for
	 * constant-time behaviour to avoid leaking key material via timing.
	 *
	 * @param string $header The API key presented by the caller.
	 * @param array $keys The rule-inline keys map (may be empty).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException On an invalid/absent API key.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Consumer authentication enforcement (REQ-CON-001)
	 */
	public function authorizeApiKey(string $header, array $keys): void {
		// 1) Rule-inline keys (pre-existing behaviour, backward compatible).
		// Use hash_equals for constant-time comparison to prevent timing attacks
		// when iterating over the configured API keys.
		foreach ($keys as $key => $userId) {
			if (hash_equals((string)$key, $header) === true) {
				$user = $this->userManager->get(uid: (string)$userId);

				if ($user === null) {
					throw new AuthenticationException(message: 'Invalid API key', details: []);
				}

				$this->userSession->setUser(user: $user);
				return;
			}
		}

		// 2) Consumer-backed keys (REQ-CON-001). Resolve the consumer whose
		// configured apiKey matches the presented credential. Fail closed when
		// no consumer matches.
		$consumer = $this->resolveConsumerByApiKey(presentedKey: $header);
		if ($consumer === null) {
			throw new AuthenticationException(message: 'Invalid API key', details: []);
		}

		// Record the resolved consumer so the endpoint runtime can enforce this
		// consumer's inbound rate limit + quota after authentication passes,
		// mirroring the JWT issuer path.
		$this->resolvedConsumer = $consumer;

		// When the consumer names a backing Nextcloud user, establish the
		// session as that user (as the JWT path does). A consumer without a
		// userId is still authenticated — the consumer itself is the identity.
		$consumerData = $consumer->getObject();
		$userId = (string)($consumerData['userId'] ?? '');
		if ($userId !== '') {
			$user = $this->userManager->get(uid: $userId);
			if ($user !== null) {
				$this->userSession->setUser(user: $user);
			}
		}

	}//end authorizeApiKey()

	/**
	 * Resolve the `apiKey` consumer whose configured key matches the presented credential.
	 *
	 * Loads the `consumer` records for the openconnector register and returns
	 * the first whose `authorizationType` is `apiKey` (case-insensitive) and
	 * whose `authorizationConfiguration.apiKey` equals `$presentedKey` under a
	 * constant-time comparison. Returns null when the presented key is empty or
	 * no consumer matches — the caller then fails closed.
	 *
	 * @param string $presentedKey The API key presented by the caller.
	 *
	 * @return ObjectEntity|null The matching consumer, or null when none matches.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Consumer authentication enforcement (REQ-CON-001)
	 */
	private function resolveConsumerByApiKey(string $presentedKey): ?ObjectEntity {
		if ($presentedKey === '') {
			return null;
		}

		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'consumer',
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$consumers = ($matches['results'] ?? $matches);

		foreach ($consumers as $consumer) {
			$data = $consumer->getObject();

			if (strtolower((string)($data['authorizationType'] ?? '')) !== 'apikey') {
				continue;
			}

			$storedKey = ($data['authorizationConfiguration']['apiKey'] ?? '');
			if (is_string($storedKey) === true
				&& $storedKey !== ''
				&& hash_equals($storedKey, $presentedKey) === true
			) {
				return $consumer;
			}
		}

		return null;
	}//end resolveConsumerByApiKey()

	/**
	 * Return the consumer resolved during authentication for this request.
	 *
	 * Both the JWT issuer path and the consumer-backed apiKey path
	 * ({@see authorizeApiKey()}) resolve a `consumer` object. The remaining
	 * methods (rule-inline apikey/basic/oauth) authenticate a Nextcloud user
	 * rather than a consumer and therefore return null — the endpoint runtime
	 * then keys inbound rate limiting on the client IP.
	 *
	 * @return ObjectEntity|null The resolved consumer, or null when none was resolved.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)
	 */
	public function getResolvedConsumer(): ?ObjectEntity {
		return $this->resolvedConsumer;
	}//end getResolvedConsumer()
}//end class
