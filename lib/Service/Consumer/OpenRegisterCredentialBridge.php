<?php

/**
 * OpenRegisterCredentialBridge: integriq's inbound credential checks, run by OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\AuthenticationException as OpenRegisterAuthenticationException;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Runs every inbound credential check in OpenRegister's AuthorizationService.
 *
 * Gate 23 (DECISIONS rows 56, 62, 64): integriq no longer verifies tokens,
 * passwords or keys itself. JWT (HS, RS and PS), Basic, OAuth, API key and
 * Nextcloud-session checks, the jti replay refusal and the issue-time check
 * all run in OpenRegister. What stays here is what is integriq's own:
 *
 * - its consumers, offered through IntegriqConsumerSource (no data migration);
 * - the 3600-second cap on a token's lifetime (exp - iat), checked AFTER
 *   OpenRegister accepted the token, because OpenRegister has no cap and
 *   whether it should is open with Ruben (Q4). Dropping it would loosen what
 *   integriq accepts;
 * - integriq's own AuthenticationException, which every caller catches;
 * - the consumer object the runtime keys rate limits and call logs on.
 *
 * On an OpenRegister without the public entry points (before #4361) every
 * check is REFUSED with a message saying so. There is no fallback to a local
 * or looser check.
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
class OpenRegisterCredentialBridge {

	/**
	 * The longest lifetime (exp - iat) integriq accepts on a token, in seconds.
	 */
	public const MAX_TOKEN_LIFETIME_SECONDS = 3600;

	/**
	 * The entry points integriq needs; an OpenRegister lacking one is refused.
	 */
	public const REQUIRED_ENTRY_POINTS = [
		'authorizeJwt',
		'authorizeApiKey',
		'authorizeBasic',
		'authorizeOAuth',
		'authorizeNcSession',
		'validatePayload',
		'getResolvedConsumer',
	];

	/**
	 * The shortest HMAC secret a consumer may verify with, in bytes (RFC 7518 §3.2).
	 *
	 * The web-token verifier this app used before gate 23 refused shorter
	 * secrets ("Invalid key length."); OpenRegister's hash_hmac() takes any
	 * secret, an empty one included. Until every OpenRegister in the field
	 * carries that refusal itself, the bridge keeps it here.
	 */
	public const HMAC_MIN_SECRET_BYTES = [
		'HS256' => 32,
		'HS384' => 48,
		'HS512' => 64,
	];

	/**
	 * The consumer the last check on this request admitted, or null.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $resolvedConsumer = null;

	/**
	 * Built on first use, after the interface check.
	 *
	 * @var ConsumerSource|null
	 */
	private ?ConsumerSource $consumerSource = null;

	/**
	 * Constructor.
	 *
	 * @param AuthorizationService $authorization OpenRegister's credential checks.
	 * @param ObjectService        $objectService OpenRegister's object service, read by the consumer source.
	 * @param IUserSession         $userSession   Nextcloud user session, cleared when the lifetime cap refuses.
	 */
	public function __construct(
		private readonly AuthorizationService $authorization,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Check a JWT bearer token against integriq's consumers.
	 *
	 * @param string $authorization The Authorization header value (`Bearer <jwt>`).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the token is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeJwt(string $authorization): void {
		$this->resolvedConsumer = null;
		$source = $this->source();
		$this->refuseWeakHmacSecret(authorization: $authorization, source: $source);
		try {
			$this->authorization->authorizeJwt($authorization, $source);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}

		$token = substr(string: $authorization, offset: strlen('Bearer '));
		try {
			$this->capLifetime(payload: $this->payloadOf(token: $token));
		} catch (AuthenticationException $exception) {
			// OpenRegister already made the consumer's user act for this
			// request; a refused token must not leave it acting.
			$this->userSession->setVolatileActiveUser(null);
			throw $exception;
		}

		$this->resolvedConsumer = $this->record();
	}//end authorizeJwt()

	/**
	 * Check an API key: the rule's own keys first, then integriq's API-key consumers.
	 *
	 * @param string $header The presented key.
	 * @param array  $keys   The rule's `key => uid` map.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the key is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeApiKey(string $header, array $keys): void {
		$this->resolvedConsumer = null;
		$source = $this->source();
		try {
			$this->authorization->authorizeApiKey($header, $keys, $source);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}

		$this->resolvedConsumer = $this->record();
	}//end authorizeApiKey()

	/**
	 * Check HTTP Basic credentials against Nextcloud's users.
	 *
	 * @param string $header The Authorization header value (`Basic <base64>`).
	 * @param array  $users  Allowed users (uid or e-mail); empty with empty groups means any.
	 * @param array  $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the credentials are refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeBasic(string $header, array $users, array $groups): void {
		$this->resolvedConsumer = null;
		$this->assertEntryPoints();
		try {
			$this->authorization->authorizeBasic($header, $users, $groups);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}
	}//end authorizeBasic()

	/**
	 * Check an OAuth bearer call that Nextcloud authenticated.
	 *
	 * @param string $header The Authorization header value.
	 * @param array  $users  Allowed users.
	 * @param array  $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the call is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeOAuth(string $header, array $users, array $groups): void {
		$this->resolvedConsumer = null;
		$this->assertEntryPoints();
		try {
			$this->authorization->authorizeOAuth($header, $users, $groups);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}
	}//end authorizeOAuth()

	/**
	 * Check the signed-in Nextcloud session user (CSRF required).
	 *
	 * @param array $users  Allowed users.
	 * @param array $groups Allowed groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the session is refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function authorizeNcSession(array $users = [], array $groups = []): void {
		$this->resolvedConsumer = null;
		$this->assertEntryPoints();
		try {
			$this->authorization->authorizeNcSession($users, $groups);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}
	}//end authorizeNcSession()

	/**
	 * Check a token's time claims and jti, then integriq's lifetime cap.
	 *
	 * Used by the LTI launch and AGS paths, which verify the signature
	 * themselves against a platform's JWKS.
	 *
	 * @param array $payload The token claims.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the claims are refused.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-keeps-its-token-lifetime-cap-req-007
	 */
	public function validatePayload(array $payload): void {
		$this->assertEntryPoints();
		try {
			$this->authorization->validatePayload($payload);
		} catch (OpenRegisterAuthenticationException $exception) {
			throw $this->translate(exception: $exception);
		}

		$this->capLifetime(payload: $payload);
	}//end validatePayload()

	/**
	 * Echo the caller's origin, refusing a response that allows credentials.
	 *
	 * @param IRequest $request  The request.
	 * @param Response $response The response.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md#requirement-cors-response-header-injection-with-credentials-guard-req-005
	 */
	public function corsAfterController(IRequest $request, Response $response): Response {
		return $this->authorization->corsAfterController($request, $response);
	}//end corsAfterController()

	/**
	 * The consumer object the last check admitted, or null.
	 *
	 * Null after Basic, OAuth, Nextcloud-session and rule-inline API keys: those
	 * authenticate a Nextcloud user, not a consumer.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function getResolvedConsumer(): ?ObjectEntity {
		return $this->resolvedConsumer;
	}//end getResolvedConsumer()

	/**
	 * Refuse a token whose exp lies more than MAX_TOKEN_LIFETIME_SECONDS after its iat.
	 *
	 * @param array $payload The token claims (already accepted by OpenRegister).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the lifetime is too long.
	 */
	private function capLifetime(array $payload): void {
		if (isset($payload['exp'], $payload['iat']) === false) {
			return;
		}

		$iat = (int)$payload['iat'];
		$exp = (int)$payload['exp'];
		if (($exp - $iat) > self::MAX_TOKEN_LIFETIME_SECONDS) {
			throw new AuthenticationException(
				message: 'The token lifetime exceeds the maximum allowed duration',
				details: [
					'iat' => $iat,
					'exp' => $exp,
					'max_lifetime_seconds' => self::MAX_TOKEN_LIFETIME_SECONDS,
				]
			);
		}
	}//end capLifetime()

	/**
	 * The claims of a compact JWS that OpenRegister already verified.
	 *
	 * @param string $token The compact JWS.
	 *
	 * @return array
	 */
	private function payloadOf(string $token): array {
		$parts = explode('.', $token);
		$payload = json_decode((string)base64_decode(strtr(($parts[1] ?? ''), '-_', '+/')), true);

		if (is_array($payload) === false) {
			return [];
		}

		return $payload;
	}//end payloadOf()

	/**
	 * The integriq consumer object behind OpenRegister's resolved consumer, or null.
	 *
	 * @return ObjectEntity|null
	 */
	private function record(): ?ObjectEntity {
		$resolved = $this->authorization->getResolvedConsumer();
		if ($resolved === null || $resolved->source !== IntegriqConsumerSource::SOURCE) {
			return null;
		}

		$record = $resolved->record;
		if ($record instanceof ObjectEntity) {
			return $record;
		}

		return null;
	}//end record()

	/**
	 * integriq's consumer source, built once the running OpenRegister is known to accept one.
	 *
	 * @return ConsumerSource
	 *
	 * @throws AuthenticationException On an OpenRegister without the entry points.
	 */
	private function source(): ConsumerSource {
		$this->assertEntryPoints();
		if ($this->consumerSource === null) {
			$this->consumerSource = new IntegriqConsumerSource(objectService: $this->objectService);
		}

		return $this->consumerSource;
	}//end source()

	/**
	 * Refuse when the running OpenRegister predates the public entry points.
	 *
	 * An older OpenRegister has the same class with these methods protected (or
	 * absent) and no ConsumerSource interface. Calling it would be a fatal
	 * error; falling back to a check of our own is what gate 23 retired. So the
	 * call is refused, and the message says why.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException On an OpenRegister without the entry points.
	 */
	private function assertEntryPoints(): void {
		if (self::entryPointsAvailable(authorization: $this->authorization) === false) {
			throw new AuthenticationException(
				message: 'Inbound authentication is unavailable',
				details: [
					'reason' => 'This OpenRegister does not offer the public credential checks integriq needs; update OpenRegister. The call is refused.',
				]
			);
		}
	}//end assertEntryPoints()


	/**
	 * Whether the running OpenRegister offers the public credential checks.
	 *
	 * Shared with the setup check, so an admin sees the same verdict a refused
	 * call would give.
	 *
	 * @param object $authorization OpenRegister's AuthorizationService (any version).
	 *
	 * @return bool True when every required entry point is callable and the ConsumerSource interface exists.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public static function entryPointsAvailable(object $authorization): bool {
		$available = interface_exists(ConsumerSource::class);
		foreach (self::REQUIRED_ENTRY_POINTS as $method) {
			$available = ($available === true && is_callable([$authorization, $method]) === true);
		}

		return $available;
	}//end entryPointsAvailable()


	/**
	 * Refuse a token whose issuer verifies with an HMAC secret below the algorithm's hash output.
	 *
	 * Runs before OpenRegister sees the token: the issuer is read from the
	 * unverified payload only to look up its stored configuration, exactly as
	 * OpenRegister does; nothing in the token is trusted here. An unknown
	 * issuer is left to OpenRegister, which refuses it.
	 *
	 * @param string         $authorization The Authorization header value.
	 * @param ConsumerSource $source        integriq's consumers.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the issuer's HMAC secret is too short.
	 */
	private function refuseWeakHmacSecret(string $authorization, ConsumerSource $source): void {
		$payload = $this->payloadOf(token: substr(string: $authorization, offset: strlen('Bearer ')));
		$issuer = ($payload['iss'] ?? null);
		if (is_string($issuer) === false || $issuer === '') {
			return;
		}

		$consumer = $source->findByIssuer(issuer: $issuer);
		if ($consumer === null) {
			return;
		}

		$algorithm = (string)($consumer->configuration['algorithm'] ?? '');
		if (isset(self::HMAC_MIN_SECRET_BYTES[$algorithm]) === false) {
			return;
		}

		$secret = (string)($consumer->configuration['publicKey'] ?? '');
		if (strlen($secret) < self::HMAC_MIN_SECRET_BYTES[$algorithm]) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: [
					'reason' => 'The issuer\'s HMAC secret is shorter than the algorithm\'s hash output',
					'algorithm' => $algorithm,
					'minimum_bytes' => self::HMAC_MIN_SECRET_BYTES[$algorithm],
				]
			);
		}
	}//end refuseWeakHmacSecret()

	/**
	 * Carry OpenRegister's refusal over as integriq's, which every caller catches.
	 *
	 * @param OpenRegisterAuthenticationException $exception OpenRegister's refusal.
	 *
	 * @return AuthenticationException
	 */
	private function translate(OpenRegisterAuthenticationException $exception): AuthenticationException {
		return new AuthenticationException(message: $exception->getMessage(), details: $exception->getDetails());
	}//end translate()
}//end class
