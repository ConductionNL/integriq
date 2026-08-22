<?php

/**
 * The semantic half of the synchronization → flow migration refusal surface.
 *
 * WHY THIS IS ITS OWN CLASS. `SynchronizationFlowGenerator` does two separable
 * jobs: it decides whether a synchronization CAN be expressed as a flow, and it
 * assembles the document. The refusal surface is the larger half by complexity,
 * and the generator had reached its phpmd class-complexity budget — adding the
 * `payloadFrom` support tipped it from 49 to 54 against a threshold of 50.
 *
 * Splitting the two shed the budget permanently rather than shaving branches
 * until the number happened to fit. These two methods were the natural seam:
 * unlike the transport and field refusals they depend on NOTHING but
 * translations, so they move without dragging shared helpers behind them.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCP\IL10N;

/**
 * Refusals about what a synchronization MEANS, as opposed to how it is wired.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class SynchronizationSemanticRefusals {

	/**
	 * The only sync mode the decomposed flow expresses.
	 *
	 * @var string
	 */
	private const SUPPORTED_SYNC_MODE = 'full';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations, so a refusal reads as a sentence.
	 */
	public function __construct(private readonly IL10N $l10n) {

	}//end __construct()

	/**
	 * Refusals about what the synchronization means rather than how it is wired.
	 *
	 * Each of these changes WHICH objects are synchronised, or what happens
	 * around the write. A generated flow that omitted one would run green and
	 * do less than the synchronization it replaced.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $synchronization): array {
		$sourceConfig = (array)($synchronization['sourceConfig'] ?? []);
		$reasons = [];

		$syncMode = trim((string)($synchronization['syncMode'] ?? self::SUPPORTED_SYNC_MODE));
		if ($syncMode !== '' && $syncMode !== self::SUPPORTED_SYNC_MODE) {
			$reasons[] = $this->l10n->t(
				'syncMode "%1$s": no step carries the cursor watermark, and the stale sweep refuses an incremental pass.',
				[$syncMode]
			);
		}

		if (trim((string)($synchronization['sourceHashMapping'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'sourceHashMapping: the contract step hashes a dot-path on the item, not the output of a mapping, '
				. 'so change detection would compare a different value than the stored contract.'
			);
		}

		if (trim((string)($synchronization['targetSourceMapping'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'targetSourceMapping: the reverse (target→source) leg of a bidirectional sync has no decomposed steps.'
			);
		}

		foreach (['conditions', 'actions', 'followUps'] as $key) {
			if ((array)($synchronization[$key] ?? []) === []) {
				continue;
			}

			$reasons[] = $this->l10n->t(
				'%1$s: this synchronization declares them and no generated step evaluates them.',
				[$key]
			);
		}

		return array_merge($reasons, $this->sourceConfigRefusals(sourceConfig: $sourceConfig));

	}//end refusalsFor()

	/**
	 * Refusals hidden inside `sourceConfig`.
	 *
	 * @param array $sourceConfig The synchronization's source configuration.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function sourceConfigRefusals(array $sourceConfig): array {
		$reasons = [];

		if ((array)($sourceConfig['subObjects'] ?? []) !== []) {
			$reasons[] = $this->l10n->t(
				'sourceConfig.subObjects: nested objects are written and contracted by the legacy engine only.'
			);
		}

		if ((bool)($sourceConfig['requiresApproval'] ?? false) === true) {
			$reasons[] = $this->l10n->t(
				'sourceConfig.requiresApproval: the write would bypass the approval gate the synchronization requires.'
			);
		}

		foreach (['originIdsToReplace', 'idsToReplaceWithTargetIdsBeforeRules'] as $key) {
			if ((array)($sourceConfig[$key] ?? []) === []) {
				continue;
			}

			$reasons[] = $this->l10n->t(
				'sourceConfig.%1$s: origin-id rewriting happens inside the legacy write path and has no step.',
				[$key]
			);
		}

		return $reasons;

	}//end sourceConfigRefusals()
}//end class
