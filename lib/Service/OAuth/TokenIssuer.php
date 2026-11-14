<?php

/**
 * Integriq TokenIssuer
 *
 * The OAuth 2.0 client credentials grant (RFC 6749 section 4.4) for
 * consumers (access-oauth-and-token-validation, D3). A consumer of
 * authorizationType `client_credentials` is the client: its uuid is the
 * client id, and its secret is stored only as a password hash, set by an
 * admin and shown once. A granted token is a JWT signed with integriq's
 * issuer key: `iss` the instance, `sub` the consumer, `aud` the requested
 * resource, `scope` the granted scopes, five minutes long.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;

/**
 * Issues client credentials tokens, and sets a consumer's client secret.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
class TokenIssuer {

	/**
	 * The authorizationType of a consumer that may use the grant.
	 */
	public const CLIENT_CREDENTIALS = 'client_credentials';

	/**
	 * A token's lifetime in seconds.
	 */
	public const LIFETIME_SECONDS = 300;

	/**
	 * The consumer property that holds the client secret's hash.
	 */
	public const SECRET_HASH_FIELD = 'clientSecretHash';

	/**
	 * The integriq register.
	 */
	private const REGISTER = 'integriq';

	/**
	 * The consumer schema.
	 */
	private const SCHEMA = 'consumer';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService OpenRegister object service (the consumers).
	 * @param IssuerKeyService $issuerKey     Signs the token.
	 * @param ITimeFactory     $time          The clock.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IssuerKeyService $issuerKey,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Grant a token for the client credentials grant.
	 *
	 * @param string      $grantType    The grant type; only client_credentials.
	 * @param string      $clientId     The consumer's uuid.
	 * @param string      $clientSecret The consumer's secret.
	 * @param string|null $scope        Space-separated scopes asked for; null asks for all the consumer has.
	 * @param string|null $resource     The resource the token is for; its `aud`. Null is the issuer.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
	 *
	 * @throws OAuthError When the grant, the client or the scope is refused, or no key exists.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function issue(string $grantType, string $clientId, string $clientSecret, ?string $scope = null, ?string $resource = null): array {
		if ($grantType !== self::CLIENT_CREDENTIALS) {
			throw new OAuthError(error: 'unsupported_grant_type', description: 'Only client_credentials is supported.', status: 400);
		}

		$consumer = $this->client(clientId: $clientId);
		$hash     = (string) ($consumer[self::SECRET_HASH_FIELD] ?? '');
		if ($consumer === null || $clientSecret === '' || $hash === '' || password_verify($clientSecret, $hash) === false) {
			throw new OAuthError(error: 'invalid_client', description: 'Client authentication failed.', status: 401);
		}

		$granted = self::grantedScopes(held: self::scopesOf(consumer: $consumer), asked: $scope);
		if ($this->issuerKey->hasKey() === false) {
			throw new OAuthError(error: 'temporarily_unavailable', description: 'No issuer key has been created.', status: 503);
		}

		$now      = $this->time->getTime();
		$issuer   = $this->issuerKey->issuerUrl();
		$audience = trim((string) $resource);
		if ($audience === '') {
			$audience = $issuer;
		}

		try {
			$token = $this->issuerKey->sign(
				claims: [
					'iss'       => $issuer,
					'sub'       => $clientId,
					'aud'       => $audience,
					'iat'       => $now,
					'nbf'       => $now,
					'exp'       => ($now + self::LIFETIME_SECONDS),
					'jti'       => bin2hex(random_bytes(16)),
					'client_id' => $clientId,
					'scope'     => implode(' ', $granted),
				]
			);
		} catch (RuntimeException $e) {
			throw new OAuthError(error: 'temporarily_unavailable', description: $e->getMessage(), status: 503);
		}

		return [
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => self::LIFETIME_SECONDS,
			'scope'        => implode(' ', $granted),
		];
	}//end issue()

	/**
	 * Set a new client secret on a client credentials consumer and answer it, once.
	 *
	 * @param string $consumerId The consumer.
	 *
	 * @return string|null The secret, or null when the consumer does not exist or is not a client credentials consumer.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function setClientSecret(string $consumerId): ?string {
		$consumer = $this->client(clientId: $consumerId);
		if ($consumer === null) {
			return null;
		}

		$secret = bin2hex(random_bytes(32));
		$consumer[self::SECRET_HASH_FIELD] = password_hash($secret, PASSWORD_DEFAULT);
		$this->objectService->saveObject(
			object: $consumer,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $consumerId,
			_rbac: false,
			_multitenancy: false
		);

		return $secret;
	}//end setClientSecret()

	/**
	 * The scopes granted: all the consumer holds, or those asked for when it holds every one.
	 *
	 * @param array<int, string> $held  The consumer's scopes.
	 * @param string|null        $asked The space-separated scopes asked for.
	 *
	 * @return array<int, string>
	 *
	 * @throws OAuthError When a scope is asked for that the consumer does not hold.
	 */
	private static function grantedScopes(array $held, ?string $asked): array {
		$wanted = array_values(array_filter(explode(' ', trim((string) $asked))));
		if ($wanted === []) {
			return $held;
		}

		$missing = array_values(array_diff($wanted, $held));
		if ($missing !== []) {
			throw new OAuthError(error: 'invalid_scope', description: 'Not granted to this client: ' . implode(' ', $missing), status: 400);
		}

		return $wanted;
	}//end grantedScopes()

	/**
	 * A consumer's scopes as strings.
	 *
	 * @param array<string, mixed> $consumer The consumer.
	 *
	 * @return array<int, string>
	 */
	private static function scopesOf(array $consumer): array {
		return array_values(array_filter((array) ($consumer['scopes'] ?? []), static fn (mixed $scope): bool => is_string($scope) && $scope !== ''));
	}//end scopesOf()

	/**
	 * The client credentials consumer with this id, or null.
	 *
	 * @param string $clientId The consumer's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function client(string $clientId): ?array {
		if ($clientId === '') {
			return null;
		}

		$object = $this->objectService->find(id: $clientId, register: self::REGISTER, schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		$data = $object->getObject();
		if (strtolower((string) ($data['authorizationType'] ?? '')) !== self::CLIENT_CREDENTIALS) {
			return null;
		}

		return $data;
	}//end client()
}//end class
