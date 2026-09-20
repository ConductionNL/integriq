<?php

/**
 * Integriq SenderIdentityService.
 *
 * An identity is not an account. The account holds the credentials and the
 * OAuth grant, and Nextcloud Mail owns it (decision D12). An identity holds
 * what the recipient sees: the display name, the address, the reply-to, the
 * signature, how much history is quoted, how long a send is held and which
 * keys sign it. Many identities may share one account, and moving an identity
 * to another account changes no policy.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Resolves which identity a message leaves under.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class SenderIdentityService {

	/**
	 * The schema one sender identity is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'sender_identity';

	/**
	 * Signing key material was obtained.
	 *
	 * @var string
	 */
	public const SIGNING_KEY_AVAILABLE = 'available';

	/**
	 * This identity has no signing key configured at all.
	 *
	 * @var string
	 */
	public const SIGNING_KEY_ABSENT = 'absent';

	/**
	 * This identity names a credential the broker could not resolve.
	 *
	 * Distinct from ABSENT on purpose. The two used to share one message, which is
	 * how a write-only field that nobody could read looked exactly like an
	 * identity nobody had configured.
	 *
	 * @var string
	 */
	public const SIGNING_KEY_UNRESOLVABLE = 'unresolvable';

	/**
	 * FQCN of the OpenRegister credential broker, resolved lazily so this app
	 * carries no compile-time dependency on it.
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The app id every credential minted by this app is allowed for.
	 *
	 * `openconnector` rather than `integriq`: every credential minted so far
	 * carries it, and renaming it fails every brokered resolve CLOSED. Correcting
	 * it is its own migration.
	 *
	 * @var string
	 */
	private const BROKER_APP_ID = 'openconnector';

	/**
	 * Quote nothing of the case history.
	 *
	 * @var string
	 */
	public const QUOTING_NONE = 'none';

	/**
	 * Quote only the message being replied to.
	 *
	 * @var string
	 */
	public const QUOTING_LAST = 'last-message';

	/**
	 * Quote the whole case history.
	 *
	 * @var string
	 */
	public const QUOTING_FULL = 'full-history';

	/**
	 * Every quoting level, and the default a new identity gets.
	 *
	 * @var array<int,string>
	 */
	public const QUOTING_LEVELS = [self::QUOTING_NONE, self::QUOTING_LAST, self::QUOTING_FULL];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads and writes identities.
	 * @param ContainerInterface $container Resolves the OpenRegister credential broker lazily.
	 * @param LoggerInterface $logger Logs why a signing key could not be obtained, never the key.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the identity a message leaves under.
	 *
	 * @param string|null $identityId The identity the message named, or null.
	 *
	 * @return array{identity:array<string,mixed>,id:string,fallback:bool} The identity, and
	 *         whether the instance default was used because the message named none.
	 *
	 * @throws RuntimeException When the message names no identity and this instance has no default.
	 */
	public function resolve(?string $identityId): array {
		if ($identityId !== null && trim($identityId) !== '') {
			try {
				$entity = $this->objectService->find(
					id: trim($identityId),
					register: MessageRecorder::REGISTER,
					schema: self::SCHEMA,
				);
			} catch (DoesNotExistException) {
				throw new RuntimeException('No sender identity "' . $identityId . '".');
			}

			if (($entity instanceof ObjectEntity) === true) {
				return [
					'identity' => $this->withDefaults(identity: $entity->getObject()),
					'id' => (string)$entity->getUuid(),
					'fallback' => false,
				];
			}
		}

		$default = $this->defaultIdentity();
		if ($default === null) {
			throw new RuntimeException(
				'This instance has no default sender identity, so a message naming none has no address to leave under.'
			);
		}

		return [
			'identity' => $this->withDefaults(identity: $default->getObject()),
			'id' => (string)$default->getUuid(),
			'fallback' => true,
		];

	}//end resolve()

	/**
	 * The signing key for one identity, and why there is none when there is none.
	 *
	 * Signing reads OUTSIDE RBAC, and only here. `resolve()`, `all()` and
	 * `defaultIdentity()` stay RBAC-scoped because SenderIdentityController
	 * renders them to a person, and `sender_identity` is locked down
	 * (register.d/99-sender-identity-lockdown.json). Widening those would hand a
	 * caller exactly what that lockdown refuses.
	 *
	 * The system-context read is needed for a different reason: `smimePrivateKey`
	 * is `writeOnly`, and OpenRegister strips write-only values when
	 * `_rbac === true`. An identity whose key has not yet been migrated would
	 * therefore read as EMPTY to the signing path, and the message would go out
	 * unsigned under a reason that reads like an unconfigured identity. That is
	 * the transitional path; a migrated identity resolves through the broker and
	 * never touches the inline field.
	 *
	 * @param array<string,mixed> $identity The identity object as read.
	 * @param string $identityId The identity's uuid, for the system-context read.
	 *
	 * @return array{key: string|null, state: string} The material, and the state
	 *         explaining its absence. Never carries a secret in the state.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	public function signingMaterial(array $identity, string $identityId): array {
		$reference = trim((string)($identity['smimePrivateKeyRef'] ?? ''));
		if ($reference !== '') {
			return $this->materialFromBroker(reference: $reference, identityId: $identityId);
		}

		return $this->materialFromInlineValue(identityId: $identityId);

	}//end signingMaterial()

	/**
	 * Resolve key material from the credential broker.
	 *
	 * @param string $reference The credential id held on the identity.
	 * @param string $identityId The identity's uuid, for the organisation lookup.
	 *
	 * @return array{key: string|null, state: string} The material or why not.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	private function materialFromBroker(string $reference, string $identityId): array {
		if ($this->isBrokerClassAvailable() === false) {
			$this->logger->warning('[SenderIdentity] the credential broker is unavailable, so ' . $identityId . ' cannot sign');

			return ['key' => null, 'state' => self::SIGNING_KEY_UNRESOLVABLE];
		}

		$entity = $this->systemContextEntity(identityId: $identityId);
		if ($entity === null) {
			return ['key' => null, 'state' => self::SIGNING_KEY_UNRESOLVABLE];
		}

		try {
			$broker = $this->resolveBroker();
			$secret = $broker->resolveInjectable(
				$reference,
				self::BROKER_APP_ID,
				null,
				trim((string)($entity->getOrganisation() ?? ''))
			);
		} catch (Throwable $e) {
			// The cause is logged by class only — a broker refusal message is
			// deliberately opaque and must never be widened with a secret.
			$this->logger->warning(
				'[SenderIdentity] the broker refused the signing credential for ' . $identityId
				. ' (' . $e::class . ')'
			);

			return ['key' => null, 'state' => self::SIGNING_KEY_UNRESOLVABLE];
		}

		$material = trim((string)$secret);
		if ($material === '') {
			$this->logger->warning('[SenderIdentity] the broker returned no material for ' . $identityId);

			return ['key' => null, 'state' => self::SIGNING_KEY_UNRESOLVABLE];
		}

		return ['key' => $material, 'state' => self::SIGNING_KEY_AVAILABLE];

	}//end materialFromBroker()

	/**
	 * Whether the broker class is loadable (protected seam for tests).
	 *
	 * @return bool Whether the OpenRegister credential broker class exists.
	 *
	 * @spec exclude Cross-app availability probe — no domain behaviour (overridden in tests).
	 */
	protected function isBrokerClassAvailable(): bool {
		return class_exists(self::BROKER_CLASS);

	}//end isBrokerClassAvailable()

	/**
	 * Resolve the broker from the container (protected seam for tests).
	 *
	 * @return object The CredentialBrokerService instance.
	 *
	 * @spec exclude Container-resolution seam — lazy cross-app lookup, no domain behaviour (overridden in tests).
	 */
	protected function resolveBroker(): object {
		return $this->container->get(self::BROKER_CLASS);

	}//end resolveBroker()

	/**
	 * Read the not-yet-migrated inline value, outside RBAC so `writeOnly` does
	 * not strip it from the one caller entitled to it.
	 *
	 * @param string $identityId The identity's uuid.
	 *
	 * @return array{key: string|null, state: string} The material or why not.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	private function materialFromInlineValue(string $identityId): array {
		$entity = $this->systemContextEntity(identityId: $identityId);
		if ($entity === null) {
			return ['key' => null, 'state' => self::SIGNING_KEY_ABSENT];
		}

		$material = trim((string)($entity->getObject()['smimePrivateKey'] ?? ''));
		if ($material === '') {
			return ['key' => null, 'state' => self::SIGNING_KEY_ABSENT];
		}

		return ['key' => $material, 'state' => self::SIGNING_KEY_AVAILABLE];

	}//end materialFromInlineValue()

	/**
	 * One identity, read outside RBAC. Used only by the signing path.
	 *
	 * @param string $identityId The identity's uuid.
	 *
	 * @return ObjectEntity|null The entity, or null when it cannot be read.
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered
	 */
	private function systemContextEntity(string $identityId): ?ObjectEntity {
		try {
			$entity = $this->objectService->find(
				id: $identityId,
				register: MessageRecorder::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (($entity instanceof ObjectEntity) === false) {
			return null;
		}

		return $entity;

	}//end systemContextEntity()

	/**
	 * The instance default identity, when there is one.
	 *
	 * @return ObjectEntity|null The default, or null.
	 */
	public function defaultIdentity(): ?ObjectEntity {
		foreach ($this->all() as $identity) {
			if (($identity->getObject()['isDefault'] ?? false) === true) {
				return $identity;
			}
		}

		return null;

	}//end defaultIdentity()

	/**
	 * Every identity on this instance.
	 *
	 * @return array<int,ObjectEntity> The identities.
	 */
	public function all(): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => self::SCHEMA,
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		$identities = [];
		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === true) {
				$identities[] = $row;
			}
		}

		return $identities;

	}//end all()

	/**
	 * What a message's envelope looks like under one identity.
	 *
	 * @param array<string,mixed> $identity The identity.
	 *
	 * @return array<string,string> The From, Reply-To and signature the recipient sees.
	 */
	public function envelopeFor(array $identity): array {
		$address = (string)($identity['address'] ?? '');
		$displayName = (string)($identity['displayName'] ?? '');

		$from = $address;
		if ($displayName !== '') {
			$from = $displayName . ' <' . $address . '>';
		}

		return [
			'from' => $from,
			'replyTo' => (string)($identity['replyTo'] ?? $address),
			'signature' => (string)($identity['signature'] ?? ''),
			'account' => (string)($identity['mailAccount'] ?? ''),
		];

	}//end envelopeFor()

	/**
	 * Fill in the defaults a stored identity may not carry yet.
	 *
	 * @param array<string,mixed> $identity The stored identity.
	 *
	 * @return array<string,mixed> The identity with its defaults.
	 */
	private function withDefaults(array $identity): array {
		$quoting = (string)($identity['quotingLevel'] ?? self::QUOTING_LAST);
		if (in_array($quoting, self::QUOTING_LEVELS, true) === false) {
			$quoting = self::QUOTING_LAST;
		}

		$identity['quotingLevel'] = $quoting;
		$identity['holdWindowSeconds'] = (int)($identity['holdWindowSeconds'] ?? 0);

		return $identity;

	}//end withDefaults()

}//end class
