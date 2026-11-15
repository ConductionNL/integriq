<?php

/**
 * Finds stored values equal to the export placeholder and blanks them.
 *
 * An export masks every secret as SensitiveFieldRegistry::PLACEHOLDER. Before
 * imports dropped that value, a re-imported source or rule stored it as its
 * secret, and the placeholder is a public string. This repair reads every
 * source and rule raw, reports each path that holds the placeholder, and on
 * apply blanks it, so the secret reads as not configured until an admin
 * enters it again.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Report, and on apply blank, stored placeholder secrets in sources and rules.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
class PlaceholderSecretRepair implements PlaceholderSecretRepairInterface {

	/**
	 * The schemas whose objects can hold an imported credential.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['source', 'rule'];

	/**
	 * Constructor.
	 *
	 * @param InlineSecretMigrationPlannerInterface $reader        Lists objects and reads them raw.
	 * @param OrObjectService                       $objectService Saves a repaired object.
	 * @param LoggerInterface                       $logger        Repair diagnostics, never a value.
	 */
	public function __construct(
		private readonly InlineSecretMigrationPlannerInterface $reader,
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Scan every source and rule; blank the placeholder values only when $apply is true.
	 *
	 * @param boolean $apply Write the blanked objects back.
	 * @param integer $limit Maximum number of objects per schema.
	 *
	 * @return array{objects: array<int, array<string, mixed>>, found: int, blanked: int, failed: int}
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function run(bool $apply, int $limit): array {
		$report = ['objects' => [], 'found' => 0, 'blanked' => 0, 'failed' => 0];
		foreach (self::SCHEMAS as $schema) {
			foreach ($this->reader->listUuids(schema: $schema, limit: $limit) as $uuid => $name) {
				$found = $this->blankPlaceholders(data: $this->reader->readRawSource(uuid: (string)$uuid, schema: $schema));
				if ($found['paths'] === []) {
					continue;
				}

				$row = ['schema' => $schema, 'uuid' => (string)$uuid, 'name' => $name, 'paths' => $found['paths'], 'blanked' => false];
				$report['found']++;
				if ($apply === true) {
					$row['blanked'] = $this->save(row: $row, data: $found['data']);
					$outcome        = 'failed';
					if ($row['blanked'] === true) {
						$outcome = 'blanked';
					}

					$report[$outcome]++;
				}

				$report['objects'][] = $row;
			}
		}

		return $report;
	}//end run()

	/**
	 * Blank every value equal to the placeholder, at any depth.
	 *
	 * @param array<int|string, mixed> $data The raw object data.
	 *
	 * @return array{data: array<int|string, mixed>, paths: array<int, string>} The blanked data and the dot-paths blanked.
	 */
	private function blankPlaceholders(array $data): array {
		$paths = [];
		foreach ($data as $key => $value) {
			if ($value === SensitiveFieldRegistry::PLACEHOLDER) {
				$data[$key] = '';
				$paths[]    = (string)$key;
				continue;
			}

			if (is_array($value) === true) {
				$inner      = $this->blankPlaceholders(data: $value);
				$data[$key] = $inner['data'];
				foreach ($inner['paths'] as $path) {
					$paths[] = $key . '.' . $path;
				}
			}
		}

		return ['data' => $data, 'paths' => $paths];
	}//end blankPlaceholders()

	/**
	 * Save one blanked object in system context.
	 *
	 * @param array<string, mixed>     $row  The report row (schema, uuid, paths).
	 * @param array<int|string, mixed> $data The blanked object data.
	 *
	 * @return boolean Whether the save succeeded.
	 */
	private function save(array $row, array $data): bool {
		try {
			// Unscoped: a repair step rewriting stored secrets, run as the system.
			$this->objectService->saveObject(
				object: $data,
				register: InlineSecretMigrationPlannerInterface::REGISTER,
				schema: $row['schema'],
				uuid: $row['uuid'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[integriq] placeholder-secret repair: save failed',
				['schema' => $row['schema'], 'uuid' => $row['uuid'], 'errorClass' => get_class($e)]
			);
			return false;
		}

		$this->logger->warning(
			'[integriq] placeholder-secret repair: blanked placeholder secrets; re-enter them',
			['schema' => $row['schema'], 'uuid' => $row['uuid'], 'paths' => $row['paths']]
		);
		return true;
	}//end save()
}//end class
