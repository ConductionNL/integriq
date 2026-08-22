<?php

/**
 * The rules half of the synchronization → flow migration.
 *
 * WHY THIS IS ITS OWN CLASS. `SynchronizationFlowGenerator` had reached its
 * phpmd class-complexity budget once already — the `payloadFrom` support tipped
 * it from 49 to 54 against a threshold of 50, and moving the semantic refusals
 * out bought the room. Teaching it to judge and emit RULES tipped it again, to
 * 58, with coupling at 13 against a threshold of 13.
 *
 * Shaving branches until the number happens to fit would buy the same room and
 * lose it on the next feature. This is a real seam instead: deciding whether a
 * rule is expressible, and emitting the step for it, are the same question
 * asked twice, and both need the RULE — which is exactly what the semantic
 * refusals deliberately do not.
 *
 * THE MEASUREMENT THAT MADE THIS WORTH BUILDING. Across all 240 synchronizations
 * and all 61 rules on the dev instance: every one of the 74 synchronizations
 * refused for `actions` references exactly ONE rule, and all 74 of those rules
 * are `fetch_file` / `after`. Instance-wide the types are `fetch_file/after` 59,
 * `authentication/before` 1, `(empty)/before` 1. The blocker was never a general
 * rule engine.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Flow\FetchFileNode;
use OCP\IL10N;

/**
 * Judges a synchronization's `actions`, and emits the steps for the ones that
 * have an equivalent.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class SynchronizationActionRules {

	/**
	 * The one rule type the decomposed flow can express.
	 *
	 * @var string
	 */
	private const RULE_TYPE_FETCH_FILE = 'fetch_file';

	/**
	 * The one timing a fetch-file rule can be expressed at.
	 *
	 * @var string
	 */
	private const RULE_TIMING_AFTER = 'after';

	/**
	 * Where the generated pipeline puts the written object's id.
	 *
	 * @var string
	 */
	private const SYNCED_ID = 'syncedId';

	/**
	 * Constructor.
	 *
	 * @param SynchronizationService          $synchronizations Resolves a rule by id.
	 * @param IL10N                           $l10n             Translations, so a refusal reads as a sentence.
	 * @param SynchronizationSemanticRefusals $semantic         The meaning-level refusals, fronted here.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizations,
		private readonly IL10N $l10n,
		private readonly SynchronizationSemanticRefusals $semantic,
	) {

	}//end __construct()

	/**
	 * Refusals about the RULES a synchronization runs.
	 *
	 * Until `openconnector.fetch-file` existed, any non-empty `actions` was a
	 * blanket refusal, and it was the single biggest cause of unmigratability
	 * — 74 refusals of 79.
	 *
	 * Everything else is still refused, BY NAME. A rule type with no equivalent
	 * step would otherwise be dropped silently and the generated flow would do
	 * less than the synchronization it replaced while reporting success.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $synchronization): array {
		// The semantic refusals are FRONTED here rather than injected into the
		// generator alongside this class. Two collaborators answering "can this
		// migrate" put the generator's phpmd coupling at 13 against a threshold
		// of 13; one door keeps the generator inside its budget and gives a
		// caller one place to ask. The semantic half is still its own class,
		// still depending on nothing but translations.
		$reasons = $this->semantic->refusalsFor(synchronization: $synchronization);

		foreach ($this->ruleIdsOf(synchronization: $synchronization) as $ruleId) {
			$rule = $this->synchronizations->findRule(id: $ruleId);
			if ($rule === null) {
				$reasons[] = $this->l10n->t(
					'actions: rule "%1$s" could not be resolved, so what it does cannot be established.',
					[$ruleId]
				);
				continue;
			}

			$reason = $this->refusalFor(rule: $rule, ruleId: $ruleId);
			if ($reason !== '') {
				$reasons[] = $reason;
			}
		}

		return $reasons;

	}//end refusalsFor()

	/**
	 * The refusal one resolved rule earns, or an empty string when it is fine.
	 *
	 * @param array  $rule   The resolved rule payload.
	 * @param string $ruleId The reference it was resolved from.
	 *
	 * @return string The refusal sentence, or ''.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function refusalFor(array $rule, string $ruleId): string {
		$type = trim((string)($rule['type'] ?? ''));
		$timing = trim((string)($rule['timing'] ?? ''));

		if ($type !== self::RULE_TYPE_FETCH_FILE) {
			// A blank field and a missing one read the same in a message that
			// interpolates the raw value, and an operator cannot act on
			// `is of type ""`.
			$named = $type;
			if ($named === '') {
				$named = '(none)';
			}

			return $this->l10n->t(
				'actions: rule "%1$s" is of type "%2$s" and no generated step evaluates it.',
				[$ruleId, $named]
			);
		}

		// A `before` fetch_file would have to run BEFORE the write, and the
		// object it attaches files to does not exist yet at that point. That is
		// not a placement detail — there is nothing to attach to.
		if ($timing !== self::RULE_TIMING_AFTER) {
			$named = $timing;
			if ($named === '') {
				$named = '(none)';
			}

			return $this->l10n->t(
				'actions: fetch-file rule "%1$s" has timing "%2$s"; only "after" has an equivalent, because the '
				. 'object its files attach to does not exist before the write.',
				[$ruleId, $named]
			);
		}

		return '';

	}//end refusalFor()

	/**
	 * One `fetch-file` step per fetch-file rule the synchronization declares.
	 *
	 * ORDER IS THE RULE'S OWN. The legacy engine sorts by the rule's `order`
	 * field before running them, so two rules writing to the same path resolve
	 * the same way here as they did there. Emitting them in `actions` order
	 * would look right and quietly differ.
	 *
	 * Every rule reaching here has already passed `refusalsFor()`. This method
	 * does not re-check that: a second, weaker copy of a refusal is how the two
	 * come to disagree.
	 *
	 * @param array  $synchronization The synchronization's serialised record.
	 * @param string $reference       The synchronization reference, for the log link.
	 *
	 * @return array<int, array<string, mixed>> The steps, possibly none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function stepsFor(array $synchronization, string $reference): array {
		$rules = [];
		foreach ($this->ruleIdsOf(synchronization: $synchronization) as $ruleId) {
			$rule = $this->synchronizations->findRule(id: $ruleId);
			if ($rule === null) {
				continue;
			}

			$rules[] = ['id' => $ruleId, 'order' => (int)($rule['order'] ?? 0)];
		}

		usort($rules, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		$nodes = [];
		foreach ($rules as $index => $rule) {
			$nodes[] = [
				'id' => 'fetch-file-' . ($index + 1),
				'type' => FetchFileNode::NODE_ID,
				'config' => [
					'rule' => $rule['id'],
					'objectIdPath' => self::SYNCED_ID,
					'synchronization' => $reference,
				],
			];
		}

		return $nodes;

	}//end stepsFor()

	/**
	 * The rule ids a synchronization declares, in declaration order.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> The rule references.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function ruleIdsOf(array $synchronization): array {
		$ids = [];
		foreach ((array)($synchronization['actions'] ?? []) as $raw) {
			if (is_scalar($raw) === false) {
				continue;
			}

			$id = trim((string)$raw);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;

	}//end ruleIdsOf()
}//end class
