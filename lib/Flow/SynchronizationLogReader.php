<?php

/**
 * Integriq SynchronizationLogReader.
 *
 * Reads the per-object results and the run counts off a synchronisation log.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

/**
 * Read side of {@see SynchronizationRunNode}; built by that node.
 */
final class SynchronizationLogReader {
	/**
	 * The run counts copied onto every summary, in output order.
	 *
	 * @var list<string>
	 */
	private const SUMMARY_COUNTS = ['found', 'created', 'updated', 'deleted', 'skipped', 'invalid'];

	/**
	 * Read the per-object results off a synchronisation log.
	 *
	 * @param array $log The synchronisation log.
	 *
	 * @return array<int, array<string, mixed>> One entry per contract, in order.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function objectsFrom(array $log): array {
		$result = (array)($log['result'] ?? []);
		$embedded = ($result['_embed']['contracts'] ?? null);
		$contracts = (array)($result['contracts'] ?? []);

		$objects = [];
		foreach (array_values($contracts) as $position => $contractId) {
			$payload = self::embeddedPayload(embedded: $embedded, position: $position);
			$objects[] = self::objectFor(contractId: $contractId, payload: $payload);
		}

		return $objects;
	}//end objectsFrom()

	/**
	 * The embedded contract payload at a position, or an empty array.
	 *
	 * @param mixed $embedded The log's `_embed.contracts`, when present.
	 * @param int $position The contract's position.
	 *
	 * @return array
	 */
	private static function embeddedPayload(mixed $embedded, int $position): array {
		$payload = null;
		if (is_array($embedded) === true && array_key_exists($position, $embedded) === true) {
			$payload = $embedded[$position];
		}

		if (is_array($payload) === false) {
			return [];
		}

		return $payload;
	}//end embeddedPayload()

	/**
	 * One per-object result: the target id, else the origin id, else the contract id.
	 *
	 * @param mixed $contractId The contract id from the log.
	 * @param array $payload The contract's embedded payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function objectFor(mixed $contractId, array $payload): array {
		$objectId = ($payload['targetId'] ?? null);
		if ($objectId === null || $objectId === '') {
			$objectId = ($payload['originId'] ?? $contractId);
		}

		return [
			'objectId' => $objectId,
			'originId' => ($payload['originId'] ?? null),
			'targetId' => ($payload['targetId'] ?? null),
			'outcome' => (string)($payload['targetLastAction'] ?? 'unchanged'),
			'contract' => $contractId,
			'object' => $payload,
		];
	}//end objectFor()

	/**
	 * Read the run counts off a synchronisation log.
	 *
	 * @param array $log The synchronisation log.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function summaryFrom(array $log, string $reference): array {
		$result = (array)($log['result'] ?? []);
		$counts = (array)($result['objects'] ?? []);

		$summary = [
			'synchronization' => $reference,
			'runId' => ($log['uuid'] ?? ($log['id'] ?? null)),
			'message' => ($log['message'] ?? null),
		];
		foreach (self::SUMMARY_COUNTS as $count) {
			$summary[$count] = (int)($counts[$count] ?? 0);
		}

		return $summary;

	}//end summaryFrom()
}//end class
