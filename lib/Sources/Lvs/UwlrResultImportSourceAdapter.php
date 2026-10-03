<?php

/**
 * Integriq LVS UWLR Result Import Source Adapter (dormant).
 *
 * Source-pattern facade over {@see \OCA\Integriq\Adapters\Lvs\UwlrResultImportClient}.
 * Ships dormant: every call routes through the mock subclass and is
 * logged at DEBUG so downstream consumers (learniq's
 * `lvs-import-contract` `DataExchangeJob`) can develop and test
 * against a stable surface without contacting Cito, IEP, Boom or
 * Dia.
 *
 * Lives under `lib/Sources/Lvs/` so it can be discovered by the
 * integriq Source registry. Four Source rows share this one class —
 * `lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia` — distinguished
 * only by the `supplierId` passed into each call, per
 * `lib/sources.seed.json`.
 *
 * Pupil-identifying values (`leerlingReference`, any BSN-shaped
 * value) are NEVER passed to the structured logger — only a
 * `recordCount` and the configured `isActive()`/`flavour()` state.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Lvs
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
 * @spec openspec/specs/lvs-result-import/spec.md#requirement-source-adapter-maps-a-uwlr-shaped-batch-onto-the-lvs-import-contract-payload-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Lvs;

use OCA\Integriq\Adapters\Lvs\UwlrResultImportClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the four UWLR-shaped LVS result-import
 * suppliers (Cito via DULT, IEP, Boom, Dia).
 *
 * Until `lvs.import.feature_flag` is flipped to `1`, every call
 * routes to the canned mock batch and logs a single debug entry so
 * operators can verify the wiring without contacting a supplier.
 *
 * @spec openspec/specs/lvs-result-import/spec.md#requirement-source-adapter-maps-a-uwlr-shaped-batch-onto-the-lvs-import-contract-payload-req-002
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class UwlrResultImportSourceAdapter {
	/**
	 * App id used for IAppConfig look-ups.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key for the dormant-flag toggle.
	 */
	public const FLAG_KEY = 'lvs.import.feature_flag';

	/**
	 * Source category — matches the `category` used in the seeded
	 * `lib/sources.seed.json` rows for this family.
	 */
	public const SOURCE_CATEGORY = 'onderwijs';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App-config service (feature-flag check).
	 * @param LoggerInterface $logger Structured logger.
	 * @param UwlrResultImportClient $uwlrClient Resolved client (mock or http).
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly UwlrResultImportClient $uwlrClient,
	) {
	}//end __construct()

	/**
	 * Whether the live UWLR transport is enabled by the operator.
	 *
	 * @return bool True when `lvs.import.feature_flag` is `1` / `true`.
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-source-adapter-maps-a-uwlr-shaped-batch-onto-the-lvs-import-contract-payload-req-002
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * Fetch and map a UWLR-shaped result batch for one supplier onto
	 * learniq's `lvs-import-contract` payload field names.
	 *
	 * @param string $supplierId One of `lvs-cito-dult`, `lvs-iep`,
	 *                           `lvs-boom`, `lvs-dia`.
	 *
	 * @return array<int,array<string,mixed>> `lvs-import-contract`-shaped
	 *                                        records.
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-source-adapter-maps-a-uwlr-shaped-batch-onto-the-lvs-import-contract-payload-req-002
	 */
	public function importResults(string $supplierId): array {
		$batch = $this->uwlrClient->fetchResults($supplierId);

		$this->logger->debug(
			'lvs-uwlr-import.importResults',
			[
				'source' => $supplierId,
				'category' => self::SOURCE_CATEGORY,
				'recordCount' => count($batch),
				'active' => $this->isActive(),
				'flavour' => $this->uwlrClient->flavour(),
			]
		);

		return array_map(
			fn (array $record): array => $this->toLvsImportPayload(supplierId: $supplierId, record: $record),
			$batch
		);
	}//end importResults()

	/**
	 * Map one UWLR-shaped record onto the `lvs-import-contract`
	 * payload field names.
	 *
	 * This is the single seam to update if learniq's `lvs-import-contract`
	 * payload shape changes before archive — see design.md "Cross-Project
	 * Dependencies".
	 *
	 * @param string $supplierId Supplier Source row id.
	 * @param array<string,mixed> $record One UWLR-shaped result record.
	 *
	 * @return array<string,mixed> `lvs-import-contract`-shaped record.
	 */
	private function toLvsImportPayload(string $supplierId, array $record): array {
		return [
			'supplierId' => $supplierId,
			'pupilReference' => (string)($record['leerlingReference'] ?? ''),
			'assessmentCode' => (string)($record['toetscode'] ?? ''),
			'referenceLevel' => (string)($record['referentieniveau'] ?? ''),
			'proficiencyScore' => $record['vaardigheidsscore'] ?? null,
			'administeredOn' => (string)($record['afnamedatum'] ?? ''),
			'groupLabel' => (string)($record['groep'] ?? ''),
		];
	}//end toLvsImportPayload()
}//end class
