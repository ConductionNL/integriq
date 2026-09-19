<?php

/**
 * On-demand resync for list-shaped property sources.
 *
 * @category Service
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * An organisation tree or a classification plan is a list, not a lookup. It is
 * resynced on demand, it says when it last ran, and a failed resync leaves the
 * previous list serving.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007
 */
class ListResyncService {
	/**
	 * App id for app-config reads and writes.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key prefix holding the stored list per provider.
	 */
	public const LIST_KEY_PREFIX = 'property_source.list.';

	/**
	 * App-config key prefix holding the last resync report per provider.
	 */
	public const REPORT_KEY_PREFIX = 'property_source.resync.';

	/**
	 * Constructor.
	 *
	 * @param PropertySourceRegistry $registry Registry of bindings.
	 * @param IAppConfig $appConfig App configuration store.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly PropertySourceRegistry $registry,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The list currently served for a provider.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<int,array<string,mixed>> The stored list.
	 */
	public function currentList(string $providerId): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::LIST_KEY_PREFIX . $providerId, '[]');
		$decoded = json_decode($raw, true);

		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end currentList()

	/**
	 * The last resync report for a provider.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<string,mixed> Report with `lastResyncAt`, `changed`, `succeeded` and `message`.
	 */
	public function lastReport(string $providerId): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::REPORT_KEY_PREFIX . $providerId, '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [
				'lastResyncAt' => null,
				'changed' => 0,
				'succeeded' => null,
				'message' => '',
			];
		}

		return $decoded;
	}//end lastReport()

	/**
	 * Resync one list-shaped provider now.
	 *
	 * @param string $providerId Provider id.
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return array<string,mixed> The resync report that was stored.
	 *
	 * @throws \OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException When nothing answers to the id.
	 */
	public function resync(string $providerId, array $config = []): array {
		$provider = $this->registry->get($providerId);
		$previous = $this->currentList(providerId: $providerId);

		try {
			$fetched = $provider->suggest('', $config);
		} catch (SourceUnreachableException $e) {
			$this->logger->warning(
				'property-source.resync.failed',
				[
					'provider' => $providerId,
					'error' => $e->getMessage(),
				]
			);

			return $this->storeReport(
				providerId: $providerId,
				report: [
					'lastResyncAt' => $this->lastReport(providerId: $providerId)['lastResyncAt'],
					'changed' => 0,
					'succeeded' => false,
					'message' => $e->getMessage(),
					'served' => count($previous),
				]
			);
		}//end try

		$changed = $this->countChanges(previous: $previous, fetched: $fetched);

		$encoded = json_encode($fetched);
		if ($encoded === false) {
			$encoded = '[]';
		}

		$this->appConfig->setValueString(self::APP_ID, self::LIST_KEY_PREFIX . $providerId, $encoded);

		return $this->storeReport(
			providerId: $providerId,
			report: [
				'lastResyncAt' => time(),
				'changed' => $changed,
				'succeeded' => true,
				'message' => '',
				'served' => count($fetched),
			]
		);
	}//end resync()

	/**
	 * How many entries were added, removed or relabelled.
	 *
	 * @param array<int,array<string,mixed>> $previous The list that was served.
	 * @param array<int,array<string,mixed>> $fetched The list just read.
	 *
	 * @return int Number of changed entries.
	 */
	private function countChanges(array $previous, array $fetched): int {
		$before = [];
		foreach ($previous as $row) {
			$before[(string)($row['identifier'] ?? '')] = (string)($row['label'] ?? '');
		}

		$after = [];
		foreach ($fetched as $row) {
			$after[(string)($row['identifier'] ?? '')] = (string)($row['label'] ?? '');
		}

		$changed = 0;
		foreach ($after as $id => $label) {
			if (array_key_exists($id, $before) === false || $before[$id] !== $label) {
				$changed++;
			}
		}

		foreach ($before as $id => $label) {
			if (array_key_exists($id, $after) === false) {
				$changed++;
			}
		}

		return $changed;
	}//end countChanges()

	/**
	 * Store and return a resync report.
	 *
	 * @param string $providerId Provider id.
	 * @param array<string,mixed> $report The report.
	 *
	 * @return array<string,mixed> The same report.
	 */
	private function storeReport(string $providerId, array $report): array {
		$encoded = json_encode($report);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->appConfig->setValueString(
			self::APP_ID,
			self::REPORT_KEY_PREFIX . $providerId,
			$encoded
		);

		return $report;
	}//end storeReport()
}//end class
