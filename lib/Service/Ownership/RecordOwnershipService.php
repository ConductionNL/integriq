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
 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
 * @spec openspec/specs/source-owned-records/spec.md#requirement-the-consuming-app-reads-ownership-through-one-contract-req-sor-006
 */
class RecordOwnershipService implements RecordOwnershipServiceInterface {
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
	 *
	 * @spec openspec/specs/source-owned-records/spec.md
	 */
	public function forObject(string $targetId): OwnershipState {
		$contract = $this->findContract(targetId: $targetId);
		if ($contract === null) {
			return OwnershipState::local();
		}

		$synchronization = $this->findSynchronization(synchronizationId: (string)($contract['synchronizationId'] ?? ''));
		$mode = $this->readMode(synchronization: $synchronization);
		if ($mode === OwnershipState::MODE_LOCAL) {
			return OwnershipState::local();
		}

		return self::stateOf(mode: $mode, read: new OwnershipRead(contract: $contract, synchronization: $synchronization));
	}//end forObject()

	/**
	 * The ownership answer for a non-local mode.
	 *
	 * Each field is an "empty means absent" narrowing, delegated to a helper
	 * rather than written out as an `if` per field: inline they multiply the
	 * NPath complexity even though none of them is a decision worth reading.
	 * An absent source id stays a different fact from an empty one.
	 *
	 * @param string        $mode The declared mode.
	 * @param OwnershipRead $read The contract and its synchronisation.
	 *
	 * @return OwnershipState
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
	 */
	private static function stateOf(string $mode, OwnershipRead $read): OwnershipState {
		$contract = $read->contract;
		$lastSeenAt = ($contract['sourceLastSeen'] ?? null);

		return new OwnershipState(
			$mode,
			self::absentWhenEmpty(value: ($read->synchronization['sourceId'] ?? '')),
			self::absentWhenEmpty(value: ($contract['originId'] ?? '')),
			self::textOrNull(value: $lastSeenAt),
			($lastSeenAt === null),
			((bool)($contract['absentAtSource'] ?? false)),
			self::textOrNull(value: ($contract['endedAt'] ?? null)),
			self::absentWhenEmpty(value: ($contract['synchronizationId'] ?? '')),
			self::absentWhenEmpty(value: ($read->synchronization['name'] ?? ''))
		);
	}//end stateOf()

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

		$declared = self::declaredMode(synchronization: $synchronization);
		if ($declared === null) {
			return OwnershipState::MODE_SOURCE;
		}

		if (in_array($declared, OwnershipState::MODES, true) === false) {
			return $this->unknownMode(declared: $declared, synchronization: $synchronization);
		}

		return (string)$declared;
	}//end readMode()

	/**
	 * The mode the synchronisation's source config declares, null when none or empty.
	 *
	 * @param array<string, mixed> $synchronization The synchronisation.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
	 */
	private static function declaredMode(array $synchronization): mixed {
		$sourceConfig = ($synchronization['sourceConfig'] ?? []);
		if (is_array($sourceConfig) === false) {
			return null;
		}

		$declared = ($sourceConfig[self::MODE_KEY] ?? null);
		if ($declared === '') {
			return null;
		}

		return $declared;
	}//end declaredMode()

	/**
	 * Log a mode nobody declared and read it as local, the safe answer.
	 *
	 * @param mixed                $declared        The declared value.
	 * @param array<string, mixed> $synchronization The synchronisation.
	 *
	 * @return string OwnershipState::MODE_LOCAL.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
	 */
	private function unknownMode(mixed $declared, array $synchronization): string {
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
	}//end unknownMode()

	/**
	 * The contract that maintains one target object, if there is one.
	 *
	 * @param string $targetId The target object's id.
	 *
	 * @return array<string,mixed>|null The contract data.
	 */
	private function findContract(string $targetId): ?array {
		return $this->findOne(lookup: new OwnershipLookup(schema: 'synchronization_contract', matchKey: 'targetId', matchValue: $targetId));
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

		return $this->findOne(lookup: new OwnershipLookup(schema: 'synchronization', matchKey: 'uuid', matchValue: $synchronizationId));
	}//end findSynchronization()

	/**
	 * Read one object of a schema, checking the match rather than trusting the
	 * filter: an endpoint that ignores an unknown filter would otherwise hand
	 * back the whole schema and the first row would look like an answer.
	 *
	 * @param OwnershipLookup $lookup The schema, the field filtered on and checked, and its value.
	 *
	 * @return array<string,mixed>|null The matching object's data.
	 */
	private function findOne(OwnershipLookup $lookup): ?array {
		try {
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => $lookup->schema, $lookup->matchKey => $lookup->matchValue]]
			);
		} catch (Throwable $e) {
			$this->logger->warning('ownership.read-failed', ['schema' => $lookup->schema, 'error' => $e->getMessage()]);

			return null;
		}

		return self::firstMatch(result: $result, lookup: $lookup);
	}//end findOne()

	/**
	 * The first object in a read whose field holds the looked-up value.
	 *
	 * @param array<mixed>    $result The read, with or without a `results` envelope.
	 * @param OwnershipLookup $lookup The lookup.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
	 */
	private static function firstMatch(array $result, OwnershipLookup $lookup): ?array {
		foreach (($result['results'] ?? $result) as $entity) {
			$data = self::dataOf(entity: $entity);
			if (is_array($data) === true && (string)($data[$lookup->matchKey] ?? '') === $lookup->matchValue) {
				return $data;
			}
		}

		return null;
	}//end firstMatch()

	/**
	 * An entity's object data; a plain row is returned as it is.
	 *
	 * @param mixed $entity An ObjectEntity or a row.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
	 */
	private static function dataOf(mixed $entity): mixed {
		if ($entity instanceof ObjectEntity === true) {
			return $entity->getObject();
		}

		return $entity;
	}//end dataOf()
	/**
	 * The value as a string, or null when it is empty.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The text, or null when there is none.
	 */
	private static function absentWhenEmpty(mixed $value): ?string {
		$text = (string)$value;
		if ($text === '') {
			return null;
		}

		return $text;
	}//end absentWhenEmpty()

	/**
	 * The value as a string, keeping null as null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The text, or null when the value was null.
	 */
	private static function textOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end textOrNull()

	/**
	 * The answer for a record nobody outside maintains.
	 *
	 * @return OwnershipState A local ownership state.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#scenario-an-unknown-object-answers-local-rather-than-failing
	 */
	public function localState(): OwnershipState {
		return OwnershipState::local();
	}//end localState()
}//end class
