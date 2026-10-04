<?php

/**
 * Integriq DSO Samenloop.
 *
 * Decides the samenloop strategy of one DSO verzoek from the mapping rows its
 * activiteiten matched (change dso-activity-mapping-table, design D3,
 * REQ-DSO-011). Split from {@see DsoActivityMapper} to keep each class small.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-samenloop-handling-req-dso-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * Samenloop rules over matched mapping rows.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-samenloop-handling-req-dso-011
 */
class DsoSamenloop {

	/**
	 * Samenloop: one case per activity under a main case.
	 *
	 * @var string
	 */
	public const DEELZAKEN = 'deelzaken';

	/**
	 * Samenloop: one combined case.
	 *
	 * @var string
	 */
	public const GECOMBINEERD = 'gecombineerd';

	/**
	 * A row's own samenloop strategy, `deelzaken` when it names none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The strategy.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.3
	 */
	public function rowStrategy(array $row): string {
		if (($row['samenloopStrategy'] ?? null) === self::GECOMBINEERD) {
			return self::GECOMBINEERD;
		}

		return self::DEELZAKEN;

	}//end rowStrategy()

	/**
	 * The samenloop strategy of the verzoek (design D3).
	 *
	 * One mapped activiteit: its row's strategy. Two or more: every pair is
	 * decided by a samenloop rule when one of its two rows names the other's
	 * imowId, and otherwise by the two rows' own strategies (gecombineerd only
	 * when both say so). The verzoek is gecombineerd when every pair is. Two
	 * rules for one pair that disagree give deelzaken.
	 *
	 * @param array<int, array<string, mixed>> $rows The matched row of each mapped activiteit, in order.
	 *
	 * @return string `gecombineerd` or `deelzaken`.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.3
	 */
	public function decide(array $rows): string {
		if (count($rows) === 1) {
			return $this->rowStrategy(row: $rows[0]);
		}

		$count = count($rows);
		for ($first = 0; $first < $count; $first++) {
			for ($second = ($first + 1); $second < $count; $second++) {
				if ($this->pairStrategy(one: $rows[$first], other: $rows[$second]) !== self::GECOMBINEERD) {
					return self::DEELZAKEN;
				}
			}
		}

		return self::GECOMBINEERD;

	}//end decide()

	/**
	 * The strategy for one pair of matched rows.
	 *
	 * @param array<string, mixed> $one   One row.
	 * @param array<string, mixed> $other The other row.
	 *
	 * @return string `gecombineerd` or `deelzaken`.
	 */
	private function pairStrategy(array $one, array $other): string {
		$rules = array_merge(
			$this->rulesFor(row: $one, otherImowId: (string)($other['imowId'] ?? '')),
			$this->rulesFor(row: $other, otherImowId: (string)($one['imowId'] ?? ''))
		);
		if ($rules !== []) {
			if (in_array(self::DEELZAKEN, $rules, true) === true) {
				return self::DEELZAKEN;
			}

			return self::GECOMBINEERD;
		}

		if ($this->rowStrategy(row: $one) === self::GECOMBINEERD && $this->rowStrategy(row: $other) === self::GECOMBINEERD) {
			return self::GECOMBINEERD;
		}

		return self::DEELZAKEN;

	}//end pairStrategy()

	/**
	 * The strategies a row's samenloop rules give for one other activity.
	 *
	 * @param array<string, mixed> $row         The row holding the rules.
	 * @param string               $otherImowId The other activity's imowId.
	 *
	 * @return array<int, string> The strategies, each `gecombineerd` or `deelzaken`.
	 */
	private function rulesFor(array $row, string $otherImowId): array {
		if (trim($otherImowId) === '') {
			return [];
		}

		$strategies = [];
		foreach ((array)($row['samenloopRules'] ?? []) as $rule) {
			if (is_array($rule) === false || trim((string)($rule['withImowId'] ?? '')) !== trim($otherImowId)) {
				continue;
			}

			$strategy = self::DEELZAKEN;
			if (($rule['strategy'] ?? null) === self::GECOMBINEERD) {
				$strategy = self::GECOMBINEERD;
			}

			$strategies[] = $strategy;
		}

		return $strategies;

	}//end rulesFor()
}//end class
