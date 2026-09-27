<?php

/**
 * Integriq Rostering Import Source Adapter (dormant).
 *
 * Source-pattern facade over {@see \OCA\Integriq\Adapters\Roster\RosterImportClient}.
 * Ships dormant: every call routes through the mock subclass and is
 * logged at DEBUG so downstream consumers (learniq's rostering-import
 * `DataExchangeJob`) can develop and test against a stable surface
 * without contacting Zermelo, Untis, Xedule or TimeEdit.
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
 * @spec openspec/specs/rostering-import/spec.md#requirement-source-adapter-maps-a-roster-batch-onto-the-rostering-import-job-payload-req-002
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
 * @spec openspec/specs/rostering-import/spec.md#requirement-source-adapter-maps-a-roster-batch-onto-the-rostering-import-job-payload-req-002
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
	 * @param IAppConfig $config App-config service (feature-flag check).
	 * @param LoggerInterface $logger Structured logger.
	 * @param RosterImportClient $rosterClient Resolved client (mock or http).
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly RosterImportClient $rosterClient,
	) {
	}//end __construct()

	/**
	 * Whether the live rostering transport is enabled by the operator.
	 *
	 * @return bool True when `roster.import.feature_flag` is `1` / `true`.
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-source-adapter-maps-a-roster-batch-onto-the-rostering-import-job-payload-req-002
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * Fetch and map a lesson batch for one system onto learniq's
	 * rostering-import job payload field names.
	 *
	 * @param string $systemId One of `roster-zermelo`,
	 *                         `roster-untis-oneroster`,
	 *                         `roster-xedule`, `roster-timeedit`.
	 *
	 * @return array<int,array<string,mixed>> Rostering-import-shaped
	 *                                        records.
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-source-adapter-maps-a-roster-batch-onto-the-rostering-import-job-payload-req-002
	 */
	public function importLessons(string $systemId): array {
		$batch = $this->rosterClient->fetchLessons($systemId);

		$this->logger->debug(
			'roster-import.importLessons',
			[
				'source' => $systemId,
				'category' => self::SOURCE_CATEGORY,
				'recordCount' => count($batch),
				'active' => $this->isActive(),
				'flavour' => $this->rosterClient->flavour(),
			]
		);

		return array_map(
			fn (array $lesson): array => $this->toRosteringImportPayload(systemId: $systemId, lesson: $lesson),
			$batch
		);
	}//end importLessons()

	/**
	 * Map one lesson record onto the rostering-import job payload
	 * field names.
	 *
	 * This is the single seam to update if learniq's rostering-import
	 * payload shape changes before archive — see design.md
	 * "Cross-Project Dependencies".
	 *
	 * @param string $systemId Rostering system Source row id.
	 * @param array<string,mixed> $lesson One lesson record.
	 *
	 * @return array<string,mixed> Rostering-import-shaped record.
	 */
	private function toRosteringImportPayload(string $systemId, array $lesson): array {
		return [
			'systemId' => $systemId,
			'subject' => (string)($lesson['subject'] ?? ''),
			'startTime' => (string)($lesson['startsAt'] ?? ''),
			'endTime' => (string)($lesson['endsAt'] ?? ''),
			'roomLabel' => (string)($lesson['room'] ?? ''),
			'teacherReference' => (string)($lesson['teacherReference'] ?? ''),
			'groupReference' => (string)($lesson['groupReference'] ?? ''),
		];
	}//end toRosteringImportPayload()
}//end class
