<?php

/**
 * Integriq SLO year allocator.
 *
 * Turns the SLO niveaus a curriculum node is tagged with into learniq's
 * `Competency.applicableYears` labels, per the lane contract
 * `CONTRACT-competency-fields.md` (2026-09-27): canonical, lower case, one
 * space, arabic numeral (`groep 5`, `leerjaar 2`).
 *
 * Only niveaus that name a year count. SLO's phases (`fase 1`), school types
 * (`po`, `ob vo`, `bb havo`) and reference levels (`1F`, `A2`) do not name a
 * year, and no SLO source maps a phase to groepen, so none is invented: such
 * a node gets an empty list, which learniq reads as "every year in the
 * framework".
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

/**
 * Maps SLO niveau references to canonical learniq year labels.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
 */
final class SloYearAllocator {
	/**
	 * Allocate the years a node's niveaus name.
	 *
	 * A niveau resolves by its SLO uuid in $yearNiveaus first (SLO uuids are
	 * immutable), and by its title second.
	 *
	 * @param array<int,array{uuid?:string|null,title?:string|null}> $niveaus The node's SLO niveaus.
	 * @param array<string,array<int,string>> $yearNiveaus SLO niveau uuid => year labels
	 *                                                     (the seeded source's `yearNiveaus`).
	 *
	 * @return array<int,string> Unique labels, groepen first, each in numeric order.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 */
	public function allocate(array $niveaus, array $yearNiveaus): array {
		$labels = [];

		foreach ($niveaus as $niveau) {
			$uuid = (string)($niveau['uuid'] ?? '');
			if ($uuid !== '' && isset($yearNiveaus[$uuid]) === true) {
				foreach ($yearNiveaus[$uuid] as $label) {
					$labels[] = (string)$label;
				}

				continue;
			}

			foreach ($this->labelsFromTitle(title: (string)($niveau['title'] ?? '')) as $label) {
				$labels[] = $label;
			}
		}

		$labels = array_values(array_unique($labels));
		usort($labels, static fn (string $left, string $right): int => self::compareLabels(left: $left, right: $right));

		return $labels;
	}//end allocate()

	/**
	 * Year labels a niveau title names, or none.
	 *
	 * @param string $title An SLO niveau title such as "groep 5", "groep 3-4" or "havo 2".
	 *
	 * @return array<int,string> Canonical labels.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
	 */
	public function labelsFromTitle(string $title): array {
		$normalised = strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));

		if (preg_match('/^groep ([1-8])$/', $normalised, $match) === 1) {
			return ['groep ' . $match[1]];
		}

		if (preg_match('/^groep ([1-8]) ?- ?([1-8])$/', $normalised, $match) === 1) {
			$from = (int)$match[1];
			$until = (int)$match[2];
			if ($from > $until) {
				return [];
			}

			return array_map(static fn (int $year): string => 'groep ' . $year, range($from, $until));
		}

		if (preg_match('/^(?:vmbo (?:bb|kb|gl|tl)|havo|vwo),? ([1-6])$/', $normalised, $match) === 1) {
			return ['leerjaar ' . $match[1]];
		}

		return [];
	}//end labelsFromTitle()

	/**
	 * Order labels: `groep` before `leerjaar`, then by number.
	 *
	 * @param string $left One label.
	 * @param string $right Another label.
	 *
	 * @return int Comparison result for usort().
	 */
	private static function compareLabels(string $left, string $right): int {
		[$leftWord, $leftNumber] = self::splitLabel(label: $left);
		[$rightWord, $rightNumber] = self::splitLabel(label: $right);

		if ($leftWord !== $rightWord) {
			return strcmp($leftWord, $rightWord);
		}

		return ($leftNumber <=> $rightNumber);
	}//end compareLabels()

	/**
	 * Split a label into its word and its number.
	 *
	 * @param string $label A label such as "groep 5".
	 *
	 * @return array{0:string,1:int} Word and number (0 when there is none).
	 */
	private static function splitLabel(string $label): array {
		if (preg_match('/^(.*?)\s*(\d+)$/', $label, $match) === 1) {
			return [$match[1], (int)$match[2]];
		}

		return [$label, 0];
	}//end splitLabel()
}//end class
