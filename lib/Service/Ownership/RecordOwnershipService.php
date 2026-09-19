<?php

/**
 * The one read that answers who owns a record.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Ownership;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A consuming app asks for an object reference and gets one answer. It never
 * reads a contract, a synchronisation or a source to work this out, and
 * integriq never writes this answer onto anyone else's record.
 *
 * The ownership state is derived at read time, and the derivation refuses to
 * guess: an object with no contract is `local`, and a contract that has never
 * recorded a complete run reports its last-seen as unknown rather than
 * borrowing the synchronisation's own `lastSync`.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-the-consuming-app-reads-ownership-through-one-contract-req-sor-006
 */
class RecordOwnershipService {
	/**
	 * The register integriq's own objects live in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * The key a synchronisation declares its ownership mode under.
	 */
	public const MODE_KEY = 'ownershipMode';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Who owns the object behind this reference.
	 *
	 * @param string $targetId The object's id at the target, which is its OpenRegister uuid.
	 *
	 * @return OwnershipState The ownership answer. Never null, never an exception for an unknown id.
	 */
	public function forObject(string $targetId): OwnershipState {
		$contract = $this->findContract($targetId);
		if ($contract === null) {
			return OwnershipState::local();
		}

		$synchronization = $this->findSynchronization((string)($contract['synchronizationId'] ?? ''));
		$mode = $this->readMode($synchronization);

		if ($mode === OwnershipState::MODE_LOCAL) {
			return OwnershipState::local();
		}

		$lastSeenAt = ($contract['sourceLastSeen'] ?? null);

		// Each of these is an "empty means absent" narrowing. Written as
		// statements rather than as `?:` or a ternary because the coding
		// standard forbids both, and because an absent source id is a
		// different fact from an empty one.
		$sourceId = (string)($synchronization['sourceId'] ?? '');
		if ($sourceId === '') {
			$sourceId = null;
		}

		$originId = (string)($contract['originId'] ?? '');
		if ($originId === '') {
			$originId = null;
		}

		$synchronizationId = (string)($contract['synchronizationId'] ?? '');
		if ($synchronizationId === '') {
			$synchronizationId = null;
		}

		$synchronizationName = (string)($synchronization['name'] ?? '');
		if ($synchronizationName === '') {
			$synchronizationName = null;
		}

		$lastSeenText = null;
		if ($lastSeenAt !== null) {
			$lastSeenText = (string)$lastSeenAt;
		}

		$endedAt = ($contract['endedAt'] ?? null);
		$endedAtText = null;
		if ($endedAt !== null) {
			$endedAtText = (string)$endedAt;
		}

		return new OwnershipState(
			$mode,
			$sourceId,
			$originId,
			$lastSeenText,
			($lastSeenAt === null),
			((bool)($contract['absentAtSource'] ?? false)),
			$endedAtText,
			$synchronizationId,
			$synchronizationName
		);
	}//end forObject()

	/**
	 * The ownership mode a synchronisation declares.
	 *
	 * An absent declaration means the synchronisation maintains the record, so
	 * the mode is `source`. A declaration the engine does not know is treated
	 * as `local`, which is the safe side: it never claims ownership integriq
	 * cannot substantiate.
	 *
	 * @param array<string,mixed>|null $synchronization The synchronisation data.
	 *
	 * @return string One of the OwnershipState MODE_* constants.
	 */
	private function readMode(?array $synchronization): string {
		if ($synchronization === null) {
			return OwnershipState::MODE_LOCAL;
		}

		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		$declared = null;
		if (is_array($sourceConfig) === true) {
			$declared = ($sourceConfig[self::MODE_KEY] ?? null);
		}

		if ($declared === null || $declared === '') {
			return OwnershipState::MODE_SOURCE;
		}

		if (in_array($declared, OwnershipState::MODES, true) === false) {
			$declaredText = gettype($declared);
			if (is_scalar($declared) === true) {
				$declaredText = (string)$declared;
			}

			$this->logger->warning(
				'ownership.unknown-mode',
				[
					'declared' => $declaredText,
					'synchronization' => ($synchronization['id'] ?? $synchronization['uuid'] ?? null),
				]
			);

			return OwnershipState::MODE_LOCAL;
		}

		return (string)$declared;
	}//end readMode()

	/**
	 * The contract that maintains one target object, if there is one.
	 *
	 * @param string $targetId The target object's id.
	 *
	 * @return array<string,mixed>|null The contract data.
	 */
	private function findContract(string $targetId): ?array {
		return $this->findOne('synchronization_contract', ['targetId' => $targetId], 'targetId', $targetId);
	}//end findContract()

	/**
	 * One synchronisation by id.
	 *
	 * @param string $synchronizationId The synchronisation id.
	 *
	 * @return array<string,mixed>|null The synchronisation data.
	 */
	private function findSynchronization(string $synchronizationId): ?array {
		if ($synchronizationId === '') {
			return null;
		}

		return $this->findOne('synchronization', ['uuid' => $synchronizationId], 'uuid', $synchronizationId);
	}//end findSynchronization()

	/**
	 * Read one object of a schema, checking the match rather than trusting the
	 * filter: an endpoint that ignores an unknown filter would otherwise hand
	 * back the whole schema and the first row would look like an answer.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The filters to ask with.
	 * @param string $matchKey The key to check on the way back.
	 * @param string $matchValue The value that key must carry.
	 *
	 * @return array<string,mixed>|null The matching object's data.
	 */
	private function findOne(string $schema, array $filters, string $matchKey, string $matchValue): ?array {
		try {
			$result = $this->objectService->findAll(
				config: ['filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters)]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ownership.read-failed',
				[
					'schema' => $schema,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}

		foreach (($result['results'] ?? $result) as $entity) {
			$data = $entity;
			if ($entity instanceof ObjectEntity === true) {
				$data = $entity->getObject();
			}
			if (is_array($data) === false) {
				continue;
			}

			if ((string)($data[$matchKey] ?? '') === $matchValue) {
				return $data;
			}
		}

		return null;
	}//end findOne()
}//end class
