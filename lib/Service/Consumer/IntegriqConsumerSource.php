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

use OCA\OpenRegister\Db\ObjectEntity;
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
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The consumer whose name is the JWT issuer.
	 *
	 * @param string $issuer The `iss` claim.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when none has that name.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function findByIssuer(string $issuer): ?ResolvedConsumer {
		$consumers = $this->consumers(filters: ['name' => $issuer]);
		if ($consumers === []) {
			return null;
		}

		return $this->resolve(consumer: $consumers[0]);
	}//end findByIssuer()

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
			$data = $consumer->getObject();
			if (strtolower((string)($data['authorizationType'] ?? '')) !== 'apikey') {
				continue;
			}

			$storedKey = ($data['authorizationConfiguration']['apiKey'] ?? '');
			if (is_string($storedKey) === true && $storedKey !== '' && hash_equals($storedKey, $apiKey) === true) {
				return $this->resolve(consumer: $consumer);
			}
		}

		return null;
	}//end findByApiKey()

	/**
	 * Read consumer objects, outside RBAC and multitenancy: the caller is not signed in yet.
	 *
	 * @param array $filters Extra filters.
	 *
	 * @return ObjectEntity[]
	 */
	private function consumers(array $filters): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => 'integriq', 'schema' => 'consumer'], $filters),
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter(($matches['results'] ?? $matches), static fn ($entry): bool => $entry instanceof ObjectEntity));
	}//end consumers()

	/**
	 * Map a consumer object onto OpenRegister's resolved-consumer shape.
	 *
	 * @param ObjectEntity $consumer The consumer object.
	 *
	 * @return ResolvedConsumer
	 */
	private function resolve(ObjectEntity $consumer): ResolvedConsumer {
		$data = $consumer->getObject();

		$uuid = $consumer->getUuid();
		if ($uuid === null || $uuid === '') {
			$uuid = ($data['uuid'] ?? null);
		}

		$userId = ($data['userId'] ?? null);
		if (is_string($userId) === false) {
			$userId = null;
		}

		$authorizationType = null;
		if (isset($data['authorizationType']) === true) {
			$authorizationType = (string)$data['authorizationType'];
		}

		$configuration = ($data['authorizationConfiguration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		return new ResolvedConsumer(
			source: self::SOURCE,
			uuid: $uuid,
			name: (string)($data['name'] ?? ''),
			userId: $userId,
			authorizationType: $authorizationType,
			configuration: $configuration,
			record: $consumer,
		);
	}//end resolve()
}//end class
