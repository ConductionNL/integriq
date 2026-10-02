<?php

/**
 * Applies a declared disappearance policy to a record the source dropped.
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

/**
 * Under `markEnded` the record keeps its values and gains an end date. Under
 * `keepAndFlag` the values are left alone and the record is marked absent,
 * carrying both the last run that saw it and the run that did not. Neither
 * deletes anything.
 *
 * @spec openspec/specs/source-owned-records/spec.md#requirement-an-ended-record-keeps-its-history-and-says-when-the-source-dropped-it-req-sor-003
 */
class DisappearanceApplier {
	/**
	 * The object field carrying the end date written under `markEnded`.
	 */
	public const OBJECT_ENDED_AT = 'sourceEndedAt';

	/**
	 * The object field marking a record absent at the source.
	 */
	public const OBJECT_ABSENT = 'absentAtSource';

	/**
	 * The object field carrying the run that first did not see it.
	 */
	public const OBJECT_ABSENT_SINCE = 'sourceAbsentSince';

	/**
	 * The object field carrying the last run that still saw it.
	 */
	public const OBJECT_LAST_SEEN = 'sourceLastSeen';

	/**
	 * The sourceConfig key naming the values a non-deleting policy writes
	 * onto the record, such as `{"lifecycle": "archived"}`.
	 */
	public const VALUES_KEY = 'disappearanceValues';

	/**
	 * Read the declared retirement values from a synchronization's sourceConfig.
	 *
	 * Absent means none. Anything but an object of scalar (or null) values is
	 * refused rather than ignored: a misspelled retirement would otherwise
	 * leave a withdrawn record looking current.
	 *
	 * @param array<string,mixed> $sourceConfig The synchronization's sourceConfig.
	 *
	 * @return array<string,scalar|null> The values to write, keyed by property.
	 *
	 * @throws \InvalidArgumentException When the declaration is not such an object.
	 *
	 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-withdrawn-course-is-retired-never-deleted-req-cmkt-003
	 */
	public function valuesFrom(array $sourceConfig): array {
		$declared = ($sourceConfig[self::VALUES_KEY] ?? []);
		if (is_array($declared) === false || ($declared !== [] && array_is_list($declared) === true)) {
			throw new \InvalidArgumentException('sourceConfig.' . self::VALUES_KEY . ' must be an object of property names and values.');
		}

		foreach ($declared as $property => $value) {
			if (is_string($property) === false || trim($property) === '' || (is_scalar($value) === false && $value !== null)) {
				throw new \InvalidArgumentException('sourceConfig.' . self::VALUES_KEY . '.' . $property . ' must be a scalar value on a named property.');
			}
		}

		return $declared;
	}//end valuesFrom()

	/**
	 * Apply a policy to the target object's data.
	 *
	 * @param string $policy One of the DisappearancePolicy constants.
	 * @param array<string,mixed> $objectData The target object's data.
	 * @param string $runAt ISO timestamp of the run that did not see it.
	 * @param array<string,scalar|null> $values Declared retirement values ({@see valuesFrom()}),
	 *                                          written under both non-deleting policies.
	 *
	 * @return array<string,mixed> The object's data after the policy ran.
	 */
	public function applyToObject(string $policy, array $objectData, string $runAt, array $values = []): array {
		if ($policy === DisappearancePolicy::MARK_ENDED || $policy === DisappearancePolicy::KEEP_AND_FLAG) {
			$objectData = array_replace($objectData, $values);
		}

		if ($policy === DisappearancePolicy::MARK_ENDED) {
			$objectData[self::OBJECT_ENDED_AT] = $runAt;
			$objectData[self::OBJECT_ABSENT] = true;
			$objectData[self::OBJECT_ABSENT_SINCE] = $runAt;

			return $objectData;
		}

		if ($policy === DisappearancePolicy::KEEP_AND_FLAG) {
			// The values are left exactly as they are. Only the absence is
			// written, with both timestamps, so somebody can see what happened
			// and when it was last true.
			$objectData[self::OBJECT_ABSENT] = true;
			$objectData[self::OBJECT_ABSENT_SINCE] = $runAt;

			return $objectData;
		}

		return $objectData;
	}//end applyToObject()

	/**
	 * Apply a policy to the contract that maintains the object.
	 *
	 * @param string $policy One of the DisappearancePolicy constants.
	 * @param array<string,mixed> $contract The contract data.
	 * @param string $runAt ISO timestamp of the run that did not see it.
	 *
	 * @return array<string,mixed> The contract after the policy ran.
	 */
	public function applyToContract(string $policy, array $contract, string $runAt): array {
		if ($policy === DisappearancePolicy::MARK_ENDED) {
			$contract['endedAt'] = $runAt;
		}

		if ($policy === DisappearancePolicy::MARK_ENDED || $policy === DisappearancePolicy::KEEP_AND_FLAG) {
			$contract['absentAtSource'] = true;
			$contract['sourceAbsentSince'] = $runAt;
		}

		return $contract;
	}//end applyToContract()

	/**
	 * The record came back: the flag is cleared and the last-seen advances.
	 *
	 * @param array<string,mixed> $contract The contract data.
	 * @param string $runAt ISO timestamp of the run that saw it again.
	 *
	 * @return array{contract:array<string,mixed>,cleared:bool} The contract, and whether a flag was cleared.
	 */
	public function markSeen(array $contract, string $runAt): array {
		$cleared = ((bool)($contract['absentAtSource'] ?? false) === true);

		$contract['absentAtSource'] = false;
		$contract['sourceAbsentSince'] = null;
		$contract['endedAt'] = null;
		$contract[self::OBJECT_LAST_SEEN] = $runAt;

		return ['contract' => $contract, 'cleared' => $cleared];
	}//end markSeen()

	/**
	 * Which run count a policy adds to.
	 *
	 * @param string $policy One of the DisappearancePolicy constants.
	 *
	 * @return string The count key on the run: `deleted`, `ended` or `flagged`.
	 */
	public function countKey(string $policy): string {
		return match ($policy) {
			DisappearancePolicy::MARK_ENDED => 'ended',
			DisappearancePolicy::KEEP_AND_FLAG => 'flagged',
			default => 'deleted',
		};
	}//end countKey()
}//end class
