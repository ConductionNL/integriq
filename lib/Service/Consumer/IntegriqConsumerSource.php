<?php

/**
 * IntegriqConsumerSource: integriq's consumers, offered to OpenRegister's credential checks.
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
use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads integriq's `consumer` schema objects for OpenRegister's AuthorizationService.
 *
 * Integriq keeps its consumers as objects of its own schema (register
 * `integriq`, schema `consumer`); OpenRegister's checks look them up through
 * this source instead of OpenRegister's own consumer table (DECISIONS row 64,
 * Q5: pluggable consumer source, no data migration). The object itself rides
 * along in `record`, so the endpoint runtime keeps keying rate limits, quotas
 * and call logs on the same ObjectEntity it always did.
 *
 * Load this class only after checking that ConsumerSource exists: on an
 * OpenRegister older than #4361 the interface is missing and loading it is a
 * fatal error. OpenRegisterCredentialBridge does that check.
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
class IntegriqConsumerSource implements ConsumerSource {

	/**
	 * The source name OpenRegister records on a resolved consumer.
	 */
	public const SOURCE = 'integriq';

	/**
	 * The header of the token being checked, for a consumer whose key comes from a key set.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $tokenHeader = null;

	/**
	 * The claims of the token being checked.
	 *
	 * @var array<string,mixed>
	 */
	private array $tokenPayload = [];

	/**
	 * Finds the client credentials consumer a token of integriq's own issuer names.
	 *
	 * @var ClientCredentialsConsumerLookup
	 */
	private readonly ClientCredentialsConsumerLookup $clientCredentials;

	/**
	 * Matches and records the use of a consumer's listed credentials; null checks the single stored key only.
	 *
	 * @var ConsumerCredentialUse|null
	 */
	private ?ConsumerCredentialUse $credentialUse = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                  $objectService OpenRegister's object service.
	 * @param ConsumerCredentials|null       $credentials   The credentials list rules; null checks the single stored key only.
	 * @param ConsumerKeySourceResolver|null $keySources    Resolves the key of a `jwks` or `oidc` consumer; without it
	 *                                                      the lookup is by name only, as before.
	 * @param IssuerKeyService|null          $ownIssuer     Integriq's own issuer, whose tokens name their consumer as `sub`
	 *                                                      (access-oauth-and-token-validation, REQ-TOKV-003).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		?ConsumerCredentials $credentials = null,
		private readonly ?ConsumerKeySourceResolver $keySources = null,
		private readonly ?IssuerKeyService $ownIssuer = null,
	) {
		$this->clientCredentials = new ClientCredentialsConsumerLookup(objectService: $objectService);
		if ($credentials !== null) {
			$this->credentialUse = new ConsumerCredentialUse(objectService: $objectService, credentials: $credentials);
		}
	}//end __construct()

	/**
	 * Hand over the token being checked, so a `jwks` or `oidc` consumer's key can be resolved from what it names.
	 *
	 * @param array<string,mixed> $header  The token's header.
	 * @param array<string,mixed> $payload The token's claims.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public function presentToken(array $header, array $payload): void {
		$this->tokenHeader = $header;
		$this->tokenPayload = $payload;
	}//end presentToken()

	/**
	 * The consumer whose name is the JWT issuer.
	 *
	 * @param string $issuer The `iss` claim.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when none has that name.
	 *
	 * A consumer with key source `jwks` or `oidc` is found by its configured
	 * issuer instead, so it can be named for people; a name match is only
	 * taken for a consumer with a static key.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public function findByIssuer(string $issuer): ?ResolvedConsumer {
		$own = $this->ownTokenConsumer(issuer: $issuer);
		if ($own !== null) {
			return $own;
		}

		$consumers = $this->consumers(filters: ['name' => $issuer]);
		if ($this->keySources === null) {
			return $this->firstResolved(consumers: $consumers);
		}

		$named = self::firstWithStaticKey(consumers: $consumers);
		if ($named !== null) {
			return $this->resolve(consumer: $named);
		}

		return $this->byConfiguredIssuer(wanted: rtrim($issuer, '/'));
	}//end findByIssuer()

	/**
	 * The first consumer resolved, or null when there is none.
	 *
	 * @param array<int, ObjectEntity> $consumers The consumers.
	 *
	 * @return ResolvedConsumer|null
	 */
	private function firstResolved(array $consumers): ?ResolvedConsumer {
		if ($consumers === []) {
			return null;
		}

		return $this->resolve(consumer: $consumers[0]);
	}//end firstResolved()

	/**
	 * The first consumer whose key source is a static key, or null.
	 *
	 * @param array<int, ObjectEntity> $consumers The consumers.
	 *
	 * @return ObjectEntity|null
	 */
	private static function firstWithStaticKey(array $consumers): ?ObjectEntity {
		foreach ($consumers as $consumer) {
			if (ConsumerKeySourceResolver::keySourceOf(configuration: self::configurationOf(consumer: $consumer)) === ConsumerKeySourceResolver::STATIC) {
				return $consumer;
			}
		}

		return null;
	}//end firstWithStaticKey()

	/**
	 * The consumer whose configured issuer is the wanted one, resolved, or null.
	 *
	 * @param string $wanted The issuer without a trailing slash.
	 *
	 * @return ResolvedConsumer|null
	 */
	private function byConfiguredIssuer(string $wanted): ?ResolvedConsumer {
		foreach ($this->consumers(filters: []) as $consumer) {
			if (ConsumerKeySourceResolver::configuredIssuerOf(configuration: self::configurationOf(consumer: $consumer)) === $wanted) {
				return $this->resolve(consumer: $consumer);
			}
		}

		return null;
	}//end byConfiguredIssuer()

	/**
	 * The consumer a token integriq issued itself names, checked against integriq's own public key.
	 *
	 * Only a token whose `iss` is integriq's issuer URL is considered, its `sub`
	 * must be a consumer of authorizationType `client_credentials`, and the
	 * configuration OpenRegister verifies against pins RS256 and the published
	 * public key; nothing the token says can choose another key.
	 *
	 * @param string $issuer The `iss` claim.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when the token is not integriq's own.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	private function ownTokenConsumer(string $issuer): ?ResolvedConsumer {
		if ($this->ownIssuer === null
			|| rtrim($issuer, '/') !== $this->ownIssuer->issuerUrl()
			|| $this->ownIssuer->publicPem() === ''
		) {
			return null;
		}

		$object = $this->clientCredentials->find(subject: ($this->tokenPayload['sub'] ?? ''));
		if ($object === null) {
			return null;
		}

		$data = $object->getObject();

		return new ResolvedConsumer(
			source: self::SOURCE,
			uuid: self::uuidOf(consumer: $object, data: $data),
			name: (string)($data['name'] ?? ''),
			userId: self::stringOrNull(value: ($data['userId'] ?? null)),
			authorizationType: TokenIssuer::CLIENT_CREDENTIALS,
			configuration: ['algorithm' => IssuerKeyService::ALGORITHM, 'publicKey' => $this->ownIssuer->publicPem()],
			record: $object,
		);
	}//end ownTokenConsumer()

	/**
	 * The API-key consumer whose configured key equals the presented one.
	 *
	 * Only consumers of authorizationType `apiKey` take part, an empty key never
	 * matches, and the comparison is constant-time.
	 *
	 * @param string $apiKey The presented key.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when no key matches.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function findByApiKey(string $apiKey): ?ResolvedConsumer {
		if ($apiKey === '') {
			return null;
		}

		foreach ($this->consumers(filters: []) as $consumer) {
			if ($this->presentsApiKey(consumer: $consumer, apiKey: $apiKey) === true) {
				return $this->resolve(consumer: $consumer);
			}
		}

		return null;
	}//end findByApiKey()

	/**
	 * Whether a consumer holds the key: a live hashed credential first (its use
	 * recorded), else the key in its configuration.
	 *
	 * @param ObjectEntity      $consumer The consumer.
	 * @param string            $apiKey   The presented key.
	 *
	 * @return bool
	 */
	private function presentsApiKey(ObjectEntity $consumer, string $apiKey): bool {
		$data = $consumer->getObject();
		$uuid = (string)self::uuidOf(consumer: $consumer, data: $data);
		if ($this->credentialUse?->matchesApiKey(consumer: $consumer, uuid: $uuid, apiKey: $apiKey) === true) {
			return true;
		}

		return self::holdsApiKey(data: $data, apiKey: $apiKey);
	}//end presentsApiKey()

	/**
	 * The consumer a client certificate credential of which pins the certificate, or null.
	 *
	 * Records the use on the credential, at most once a minute, like an API key.
	 *
	 * @param ClientCertificate $certificate The web-server-verified certificate.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function findByClientCertificate(ClientCertificate $certificate): ?ObjectEntity {
		return $this->credentialUse?->certificateHolder(consumers: $this->consumers(filters: []), certificate: $certificate);
	}//end findByClientCertificate()

	/**
	 * Whether an API-key consumer stores exactly this key (compared in constant time).
	 *
	 * @param array<string, mixed> $data   The consumer data.
	 * @param string               $apiKey The presented key, not empty.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	private static function holdsApiKey(array $data, string $apiKey): bool {
		if (strtolower((string)($data['authorizationType'] ?? '')) !== 'apikey') {
			return false;
		}

		$storedKey = ($data['authorizationConfiguration']['apiKey'] ?? '');
		return is_string($storedKey) === true && $storedKey !== '' && hash_equals($storedKey, $apiKey) === true;
	}//end holdsApiKey()

	/**
	 * Read consumer objects, outside RBAC and multitenancy: the caller is not signed in yet.
	 *
	 * @param array $filters Extra filters.
	 *
	 * @return ObjectEntity[]
	 */
	private function consumers(array $filters): array {
		// Unscoped: OpenRegister's credential check resolves the calling consumer before any user is known.
		$matches = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => 'integriq', 'schema' => 'consumer'], $filters),
			],
			_rbac: false,
			_multitenancy: false
		);

		$found = array_values(array_filter(($matches['results'] ?? $matches), static fn ($entry): bool => $entry instanceof ObjectEntity));

		return array_map(fn (ObjectEntity $consumer): ObjectEntity => $this->unrendered(consumer: $consumer), $found);
	}//end consumers()

	/**
	 * The consumer as stored, with the credential a rendered read leaves out.
	 *
	 * `authorizationConfiguration` is writeOnly, and OpenRegister strips a
	 * writeOnly property from every rendered row, an unscoped read included, so
	 * the key or public key the check compares against is only on the stored row.
	 *
	 * @param ObjectEntity $consumer The consumer from the list read.
	 *
	 * @return ObjectEntity The stored consumer, or the listed one when it cannot be read.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	private function unrendered(ObjectEntity $consumer): ObjectEntity {
		$uuid = $consumer->getUuid();
		if ($uuid === null || $uuid === '') {
			return $consumer;
		}

		$stored = $this->objectService->find(
			id: $uuid,
			options: new ObjectFindOptions(register: 'integriq', schema: 'consumer', render: false),
			_rbac: false,
			_multitenancy: false
		);

		if ($stored instanceof ObjectEntity) {
			return $stored;
		}

		return $consumer;
	}//end unrendered()

	/**
	 * Map a consumer object onto OpenRegister's resolved-consumer shape.
	 *
	 * @param ObjectEntity $consumer The consumer object.
	 *
	 * @return ResolvedConsumer
	 */
	private function resolve(ObjectEntity $consumer): ResolvedConsumer {
		$data = $consumer->getObject();
		$uuid = self::uuidOf(consumer: $consumer, data: $data);

		return new ResolvedConsumer(
			source: self::SOURCE,
			uuid: $uuid,
			name: (string)($data['name'] ?? ''),
			userId: self::stringOrNull(value: ($data['userId'] ?? null)),
			authorizationType: self::typeOf(data: $data),
			configuration: $this->verificationConfiguration(uuid: (string)$uuid, configuration: self::configurationOf(consumer: $consumer)),
			record: $consumer,
		);
	}//end resolve()

	/**
	 * The consumer's authorization configuration, with the key resolved for a `jwks` or `oidc` consumer.
	 *
	 * @param string              $uuid          The consumer's uuid.
	 * @param array<string,mixed> $configuration The stored configuration.
	 *
	 * @return array<string,mixed> The configuration OpenRegister verifies against.
	 *
	 * @throws AuthenticationException When a `jwks` or `oidc` key cannot be resolved; it never falls back to the stored key.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	private function verificationConfiguration(string $uuid, array $configuration): array {
		if ($this->keySources === null || ConsumerKeySourceResolver::keySourceOf(configuration: $configuration) === ConsumerKeySourceResolver::STATIC) {
			return $configuration;
		}

		if ($this->tokenHeader === null) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'The consumer\'s key comes from a key set, and no token was presented to choose it']
			);
		}

		return $this->keySources->configurationFor(
			consumerUuid: $uuid,
			configuration: $configuration,
			header: $this->tokenHeader,
			payload: $this->tokenPayload
		);
	}//end verificationConfiguration()

	/**
	 * A consumer object's stored authorization configuration.
	 *
	 * @param ObjectEntity $consumer The consumer object.
	 *
	 * @return array<string,mixed> The configuration, empty when absent.
	 */
	private static function configurationOf(ObjectEntity $consumer): array {
		$configuration = ($consumer->getObject()['authorizationConfiguration'] ?? []);
		if (is_array($configuration) === false) {
			return [];
		}

		return $configuration;
	}//end configurationOf()

	/**
	 * The consumer's uuid: the entity's own, else the one in its data.
	 *
	 * @param ObjectEntity         $consumer The consumer entity.
	 * @param array<string, mixed> $data     Its data.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	private static function uuidOf(ObjectEntity $consumer, array $data): ?string {
		$uuid = $consumer->getUuid();
		if ($uuid === null || $uuid === '') {
			return ($data['uuid'] ?? null);
		}

		return $uuid;
	}//end uuidOf()

	/**
	 * A string value, or null for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringOrNull()

	/**
	 * The authorization type as text, or null when the consumer names none.
	 *
	 * @param array<string, mixed> $data The consumer data.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	private static function typeOf(array $data): ?string {
		if (isset($data['authorizationType']) === false) {
			return null;
		}

		return (string)$data['authorizationType'];
	}//end typeOf()
}//end class
