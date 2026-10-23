<?php

/**
 * Integriq RecordProvenanceService: where an OpenRegister record came from,
 * as integriq's synchronizations know it.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/synchronization/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;

/**
 * Reads the synchronization contract whose target is the record, and the log of that contract.
 *
 * @spec openspec/specs/synchronization/spec.md
 */
class RecordProvenanceService {

	/**
	 * The register integriq keeps its synchronizations in.
	 */
	private const REGISTER = 'integriq';

	/**
	 * How many log entries the answer carries.
	 */
	private const RECENT = 5;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Reads contracts, synchronizations and sources.
	 * @param IL10N           $l10n          Translates the labels.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Where one record came from.
	 *
	 * @param string $recordId The OpenRegister object uuid.
	 *
	 * @return array<string, mixed> `synced: false` for a record no synchronization wrote, else the provenance.
	 *
	 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md#requirement-a-record-says-where-it-was-synchronized-from
	 */
	public function provenance(string $recordId): array {
		$contract = ($this->read(schema: 'synchronization_contract', filters: ['targetId' => $recordId], limit: 1)[0] ?? null);
		if ($contract === null) {
			return ['synced' => false, 'none' => true];
		}

		$data = ($contract->getObject() ?? []);
		$synchronization = $this->one(schema: 'synchronization', id: (string)($data['synchronization'] ?? ($data['synchronizationId'] ?? '')));
		$syncData = ($synchronization?->getObject() ?? []);
		$source = $this->one(schema: 'source', id: (string)($syncData['sourceId'] ?? ''));
		$sourceData = ($source?->getObject() ?? []);

		$sourceName = (string)($sourceData['name'] ?? $this->l10n->t('Unknown source'));
		$logs = $this->read(
			schema: 'synchronization_contract_log',
			filters: ['synchronization_contract' => (string)$contract->getUuid()],
			limit: 100
		);

		$recent = [];
		foreach (array_slice($logs, 0, self::RECENT) as $log) {
			$entry = ($log->getObject() ?? []);
			$recent[] = [
				'id' => $log->getUuid(),
				'created' => ($entry['created'] ?? null),
				'result' => ($entry['targetResult'] ?? null),
				'message' => ($entry['message'] ?? null),
			];
		}

		return [
			'synced' => true,
			'none' => false,
			'source' => $this->l10n->t('%s, via Integriq', [$sourceName]),
			'sourceName' => $sourceName,
			'sourceRecord' => ($data['originId'] ?? null),
			'lastSynced' => ($data['targetLastSynced'] ?? ($data['sourceLastSynced'] ?? null)),
			'synchronization' => [
				'id' => $synchronization?->getUuid(),
				'name' => ($syncData['name'] ?? null),
			],
			'owner' => $this->l10n->t('The source. Fields that come from the source cannot be changed here.'),
			'recent' => $recent,
			'logTotal' => count($logs),
			'logSummary' => $this->l10n->t('%1$d of %2$d · full log in Integriq', [count($recent), count($logs)]),
		];
	}//end provenance()

	/**
	 * One object of a schema by uuid.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function one(string $schema, string $id): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			return $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}
	}//end one()

	/**
	 * Objects of a schema matching filters, newest first.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 * @param integer              $limit   The most rows.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function read(string $schema, array $filters, int $limit): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => $limit,
				'sort' => ['created' => 'DESC'],
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter(($result['results'] ?? $result), static fn ($item): bool => $item instanceof ObjectEntity));
	}//end read()
}//end class
