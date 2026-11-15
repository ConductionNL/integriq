<?php

/**
 * Integriq Edurep health check.
 *
 * One small SRU call, its result kept five minutes: Werkt or Fout with the
 * response time and the time of the check.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Edurep health check.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class EdurepHealthCheck {
	/**
	 * Status and label for a failed (0) and an answered (1) probe.
	 *
	 * @var array<int,array{status: string, label: string}>
	 */
	private const OUTCOMES = [
		0 => ['status' => 'error', 'label' => 'Fout'],
		1 => ['status' => 'ok', 'label' => 'Werkt'],
	];

	/**
	 * Constructor.
	 *
	 * @param SruTransport $transport The live transport.
	 * @param EdurepStatusStore $status Keeps the result and the last success and failure.
	 */
	public function __construct(
		private readonly SruTransport $transport,
		private readonly EdurepStatusStore $status,
	) {
	}//end __construct()

	/**
	 * The health, from the cache when it is younger than five minutes.
	 *
	 * @return array<string,mixed> `status` (`ok` or `error`), `label` (Werkt or Fout), `responseTimeMs`,
	 *                             `checkedAt`, `lastSuccess` and `lastFailure`.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function check(): array {
		$health = $this->status->health();
		if ($health !== null) {
			return $health;
		}

		$started = microtime(true);
		$health = self::OUTCOMES[(int)$this->probe()] + [
			'responseTimeMs' => (int)round((microtime(true) - $started) * 1000),
			'checkedAt' => gmdate(DATE_ATOM),
			'lastSuccess' => $this->status->lastSuccess(),
			'lastFailure' => $this->status->lastFailure(),
		];
		$this->status->rememberHealth(health: $health);

		return $health;
	}//end check()

	/**
	 * Send the smallest search Edurep answers.
	 *
	 * @return bool True when Edurep answered with an SRU response.
	 */
	private function probe(): bool {
		$parameters = [
			'operation' => 'searchRetrieve',
			'version' => '1.2',
			'recordSchema' => 'lom',
			'query' => 'lom.general.language=nl',
			'maximumRecords' => '1',
		];
		try {
			$body = $this->transport->fetch(parameters: $parameters);
		} catch (SourceUnavailableException) {
			return false;
		}

		return (new SafeXml())->root(xml: $body, namespace: SruResponseReader::SRW_NAMESPACE) !== null;
	}//end probe()
}//end class
