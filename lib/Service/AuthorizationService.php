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
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Response;
use OCP\IGroup;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\Client;
use OCA\OpenConnector\Exception\AuthenticationException;

/**
 * Service class for handling authorization on incoming calls.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class AuthorizationService
{
    const HMAC_ALGORITHMS  = ['HS256', 'HS384', 'HS512'];
    const PKCS1_ALGORITHMS = ['RS256', 'RS384', 'RS512'];
    const PSS_ALGORITHMS   = ['PS256', 'PS384', 'PS512'];

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
     * Constructor.
     *
     * @param IUserManager                            $userManager     The user manager.
     * @param IUserSession                            $userSession     The user session.
     * @param \OCA\OpenRegister\Service\ObjectService $orObjectService OR ObjectService used to resolve consumers.
     * @param IGroupManager                           $groupManager    The group manager for users/groups ACL checks.
     * @param ICacheFactory                           $cacheFactory    Cache factory used for jti replay detection.
     * @param IRequest                                $request         The current HTTP request (C2 Bearer guard).
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
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-1
     */
    private function findIssuer(string $issuer): ObjectEntity
    {
        $matches   = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register' => 'openconnector',
                    'schema'   => 'consumer',
                    'name'     => $issuer,
                ],
            ]
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
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-1
     */
    private function checkHeaders(JWS $token): void
    {
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
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-1
     */
    private function getJWK(string $publicKey, string $algorithm): JWKSet
    {

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
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-1
     */
    public function validatePayload(array $payload): void
    {
        $now = new DateTime();

        if (isset($payload['iat']) === false) {
            throw new AuthenticationException(message: 'The token has no time of creation', details: ['iat' => null]);
        }

        $iat = new DateTime('@'.$payload['iat']);

        // Reject tokens issued in the future (beyond the clock skew window).
        // This prevents replay attacks using pre-generated tokens.
        $maxAllowedIat = clone $now;
        $maxAllowedIat->modify('+'.self::CLOCK_SKEW_SECONDS.' seconds');
        if ($iat > $maxAllowedIat) {
            throw new AuthenticationException(
                message: 'The token has an invalid issue time',
                details: [
                    'iat'          => $iat->getTimestamp(),
                    'time checked' => $now->getTimestamp(),
                ]
            );
        }

        $exp = clone $iat;
        $exp->modify('+1 Hour');
        if (isset($payload['exp']) === true) {
            $callerExp = new DateTime('@'.$payload['exp']);

            // Clamp the caller-supplied expiry so a consumer cannot self-issue
            // tokens with an arbitrarily long (or infinite) lifetime.  The
            // maximum allowed span is MAX_TOKEN_LIFETIME_SECONDS relative to iat.
            $maxExp = clone $iat;
            $maxExp->modify('+'.self::MAX_TOKEN_LIFETIME_SECONDS.' seconds');
            if ($callerExp > $maxExp) {
                throw new AuthenticationException(
                    message: 'The token lifetime exceeds the maximum allowed duration',
                    details: [
                        'iat'                  => $iat->getTimestamp(),
                        'exp'                  => $callerExp->getTimestamp(),
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
                    'iat'          => $iat->getTimestamp(),
                    'exp'          => $exp->getTimestamp(),
                    'time checked' => $now->getTimestamp(),
                ]
            );
        }

        // Honour the not-before (nbf) claim if present.
        if (isset($payload['nbf']) === true) {
            $nbf        = new DateTime('@'.$payload['nbf']);
            $nbfAllowed = clone $nbf;
            $nbfAllowed->modify('-'.self::CLOCK_SKEW_SECONDS.' seconds');
            if ($now < $nbfAllowed) {
                throw new AuthenticationException(
                    message: 'The token is not yet valid',
                    details: [
                        'nbf'          => $nbf->getTimestamp(),
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
            $cacheKey = 'jti:'.hash('sha256', (string) $payload['jti']);
            $ttl      = max(1, ($exp->getTimestamp() - $now->getTimestamp()) + self::CLOCK_SKEW_SECONDS);

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
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-1
     */
    public function authorizeJwt(string $authorization): void
    {
        $token = substr(string: $authorization, offset: strlen('Bearer '));

        if ($token === '') {
            throw new AuthenticationException(message: 'No token has been provided', details: []);
        }

        $algorithmManager  = new AlgorithmManager(
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
        $verifier          = new JWSVerifier($algorithmManager);
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

        $issuer     = $this->findIssuer(issuer: $payload['iss']);
        $issuerData = $issuer->getObject();

        $authConfig = $issuerData['authorizationConfiguration'] ?? [];
        $publicKey  = $authConfig['publicKey'] ?? '';
        $algorithm  = $authConfig['algorithm'] ?? '';

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
     * @param array  $users  The users allowed to be authenticated according to the rule.
     * @param array  $groups The groups allowed to be authenticated according to the rule.
     *
     * @return void
     *
     * @throws AuthenticationException On invalid credentials.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-2
     */
    public function authorizeBasic(string $header, array $users, array $groups): void
    {
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

            $userGroups          = array_map(
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
     * @param array  $users  The users allowed to be authenticated according to the rule.
     * @param array  $groups The groups allowed to be authenticated according to the rule.
     *
     * @return void
     *
     * @throws AuthenticationException On invalid or missing tokens.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-3
     */
    public function authorizeOAuth(string $header, array $users, array $groups): void
    {
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

            $userGroups          = array_map(
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
     * Add CORS headers to controller result.
     *
     * @param IRequest $request  The incoming request.
     * @param Response $response The outgoing response.
     *
     * @return Response The updated response.
     *
     * @throws SecurityException If Allow-Credentials is true.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-5
     */
    public function corsAfterController(IRequest $request, Response $response)
    {
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
     * Authorize user based on API key.
     *
     * @param string $header The authorization header used.
     * @param array  $keys   The array of keys configured on the rule.
     *
     * @return void
     *
     * @throws AuthenticationException On invalid API keys.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authorization-jwt/tasks.md#task-4
     */
    public function authorizeApiKey(string $header, array $keys): void
    {
        // Use hash_equals for constant-time comparison to prevent timing attacks
        // when iterating over the configured API keys.
        $matchedUserId = null;
        foreach ($keys as $key => $userId) {
            if (hash_equals((string) $key, $header) === true) {
                $matchedUserId = $userId;
                break;
            }
        }

        if ($matchedUserId === null) {
            throw new AuthenticationException(message: 'Invalid API key', details: []);
        }

        $user = $this->userManager->get(uid: $matchedUserId);

        if ($user === null) {
            throw new AuthenticationException(message: 'Invalid API key', details: []);
        }

        $this->userSession->setUser(user: $user);
    }//end authorizeApiKey()
}//end class
