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
use RuntimeException;

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
	 */
	public function __construct(private readonly ORObjectService $objectService) {

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
					'identity' => $this->withDefaults($entity->getObject()),
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
			'identity' => $this->withDefaults($default->getObject()),
			'id' => (string)$default->getUuid(),
			'fallback' => true,
		];

	}//end resolve()

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
