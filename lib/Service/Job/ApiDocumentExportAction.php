<?php

/**
 * Integriq ApiDocumentExportAction.
 *
 * The job action that fetches the OpenAPI documents of one gateway's discovered APIs.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Job;

use InvalidArgumentException;
use OCA\Integriq\Service\ExternalApi\ApiDocumentExport;
use OCA\Integriq\Service\ExternalApi\ApiDocumentExportSettings;

/**
 * Runs {@see ApiDocumentExport} for the gateway a job row names.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */
class ApiDocumentExportAction {

	/**
	 * Constructor.
	 *
	 * @param ApiDocumentExport $export Fetches and stores the documents.
	 */
	public function __construct(
		private readonly ApiDocumentExport $export,
	) {
	}//end __construct()

	/**
	 * Run one export.
	 *
	 * @param array<string, mixed> $arguments The job's arguments: `gateway`, `exportEndpoint`, `query`,
	 *                                        `documentPosition` or `linkPosition`, `limit`, `force`.
	 *
	 * @return array{level: string, message: string, stored?: list<string>, unchanged?: list<string>, failed?: array<string, string>}
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
	 */
	public function run(array $arguments): array {
		try {
			$settings = new ApiDocumentExportSettings(
				gateway: (string)($arguments['gateway'] ?? ''),
				exportEndpoint: (string)($arguments['exportEndpoint'] ?? ''),
				options: $arguments
			);
		} catch (InvalidArgumentException $exception) {
			return ['level' => 'ERROR', 'message' => $exception->getMessage()];
		}

		$report = $this->export->run(settings: $settings);
		$level  = 'INFO';
		if ($report['failed'] !== []) {
			$level = 'WARNING';
		}

		return array_merge(
			[
				'level' => $level,
				'message' => sprintf(
					'%s: %d documents stored, %d unchanged, %d failed.',
					$settings->gateway,
					count($report['stored']),
					count($report['unchanged']),
					count($report['failed'])
				),
			],
			$report
		);
	}//end run()
}//end class
