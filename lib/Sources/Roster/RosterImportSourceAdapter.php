<?php

/**
 * Integriq Rostering Import Source Adapter (dormant).
 *
 * Source-pattern facade over {@see \OCA\Integriq\Adapters\Roster\RosterImportClient}.
 * Ships dormant: every call routes through the mock subclass and is
 * logged at DEBUG. The fetched vendor records are mapped onto planninq's
 * timetable session shape (planninq contract v1) through the source's
 * preset and target configuration, because planninq owns the timetable
 * (decision D10, rostering-adapter-targets-planninq). It used to map onto
 * learniq's rostering-import `DataExchangeJob` payload.
 *
 * Lives under `lib/Sources/Roster/` so it can be discovered by the
 * integriq Source registry. Four Source rows share this one class —
 * `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`,
 * `roster-timeedit` — distinguished only by the `systemId` passed
 * into each call, per `lib/sources.seed.json`.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the four rostering-import systems
 * (Zermelo, Untis via OneRoster, Xedule, TimeEdit).
 *
 * Until `roster.import.feature_flag` is flipped to `1`, every call
 * routes to the canned mock batch and logs a single debug entry so
 * operators can verify the wiring without contacting a scheduling
 * system.
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class RosterImportSourceAdapter {
	/**
	 * App id used for IAppConfig look-ups.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key for the dormant-flag toggle.
	 */
	public const FLAG_KEY = 'roster.import.feature_flag';

	/**
	 * Source category — matches the `category` used in the seeded
	 * `lib/sources.seed.json` rows for this family.
	 */
	public const SOURCE_CATEGORY = 'onderwijs';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                  $config        App-config service (feature-flag check).
	 * @param LoggerInterface             $logger        Structured logger.
	 * @param RosterImportClient          $rosterClient  Resolved client (mock or http).
	 * @param RosterMappingPresetRegistry $presets       Vendor-to-planninq presets.
	 * @param RosterSessionMapper         $mapper        Applies a preset to one record.
	 * @param RosterTargetConfiguration   $targetConfig  Code-to-id maps per source.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly RosterImportClient $rosterClient,
		private readonly RosterMappingPresetRegistry $presets,
		private readonly RosterSessionMapper $mapper,
		private readonly RosterTargetConfiguration $targetConfig,
	) {
	}//end __construct()

	/**
	 * Whether the live rostering transport is enabled by the operator.
	 *
	 * @return bool True when `roster.import.feature_flag` is `1` / `true`.
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * The client flavour that answers (`mock` or `https`).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function flavour(): string {
		return $this->rosterClient->flavour();
	}//end flavour()

	/**
	 * Fetch one source's lessons and map them onto planninq timetable sessions.
	 *
	 * @param string              $systemId One of `roster-zermelo`,
	 *                                      `roster-untis-oneroster`,
	 *                                      `roster-xedule`, `roster-timeedit`.
	 * @param array<string,mixed> $options  The delivery's `groupMap` and `teacherMap`, if any.
	 *
	 * @return array<int,array<string,string>> Planninq session rows (contract v1).
	 *
	 * @throws \InvalidArgumentException When the source has no preset.
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
	 */
	public function importLessons(string $systemId, array $options = []): array {
		$preset = $this->presets->get(systemId: $systemId);
		$target = $this->targetConfig->forSystem(systemId: $systemId, overrides: $options);
		$batch = $this->rosterClient->fetchLessons($systemId);

		$this->logger->debug(
			'roster-import.importLessons',
			[
				'source' => $systemId,
				'category' => self::SOURCE_CATEGORY,
				'target' => $target['target'],
				'recordCount' => count($batch),
				'active' => $this->isActive(),
				'flavour' => $this->rosterClient->flavour(),
			]
		);

		$sessions = [];
		foreach ($batch as $record) {
			if (is_array($record) === true) {
				$sessions[] = $this->mapper->map(preset: $preset, record: $record, maps: $target);
			}
		}

		return $sessions;
	}//end importLessons()
}//end class
