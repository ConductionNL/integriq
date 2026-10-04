<?php

/**
 * Integriq DSO Activity Mapper.
 *
 * The activiteiten-to-zaaktype mapping and the samenloop decision, moved here
 * from the retired DSOAdapterService (change dso-attachments-on-the-request,
 * task 3.1) because REQ-DSO-010 and REQ-DSO-011 still need them. They are
 * pure functions over a mapping table.
 *
 * Intake calls {@see self::mapRequest()} ({@see \OCA\Integriq\Service\DsoIngestService::ingest()}),
 * so every `dso_verzoek` records its zaaktypen and samenloop strategy. The
 * table is the built-in {@see self::getDefaultMappings()}: REQ-DSO-010 asks for
 * a table stored as OpenRegister objects that an administrator edits, and no
 * schema for it exists yet. One activiteitcode maps to one zaaktype; the
 * one-to-many mapping of REQ-DSO-010 needs that table first.
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
 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * Maps DSO activiteiten to zaaktypen and decides the samenloop strategy.
 *
 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */
class DsoActivityMapper {

	/**
	 * Map DSO activiteiten to zaaktypen using the provided mapping table.
	 *
	 * For each activiteit, looks up its 'code' in the mappingTable.
	 * Returns an array of activiteiten enriched with their zaaktype assignment,
	 * and a separate list of unmatched activiteiten.
	 *
	 * @param array $activiteiten Array of activiteit objects (each with a 'code' key).
	 * @param array $mappingTable Mapping keyed by activiteitCode, each value containing
	 *                            'zaaktypeIdentificatie' and 'samenloopStrategie'.
	 *
	 * @return array Associative array with 'mapped' and 'unmapped' sub-arrays.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function mapActiviteitenToZaaktypen(array $activiteiten, array $mappingTable): array {
		$mapped = [];
		$unmapped = [];

		foreach ($activiteiten as $activity) {
			$code = ($activity['code'] ?? null);

			if ($code === null || isset($mappingTable[$code]) === false) {
				$activity['zaaktypeIdentificatie'] = null;
				$activity['samenloopStrategie'] = null;
				$activity['mapped'] = false;
				$unmapped[] = $activity;
				continue;
			}

			$mapping = $mappingTable[$code];

			$activity['zaaktypeIdentificatie'] = ($mapping['zaaktypeIdentificatie'] ?? null);
			$activity['samenloopStrategie'] = ($mapping['samenloopStrategie'] ?? 'deelzaken');
			$activity['mapped'] = true;
			$mapped[] = $activity;
		}//end foreach

		return [
			'mapped' => $mapped,
			'unmapped' => $unmapped,
		];

	}//end mapActiviteitenToZaaktypen()

	/**
	 * Return the default hardcoded mapping table of DSO activiteitcodes to zaaktypen.
	 *
	 * Contains 25+ default mappings covering the most common Omgevingswet activiteiten.
	 * Each entry has dsoActiviteitCode, zaaktypeIdentificatie, samenloopStrategie,
	 * and isActief flags.
	 *
	 * @return array Array of default mapping objects.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) A literal data table of 25 entries, no logic.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function getDefaultMappings(): array {
		return [
			[
				'dsoActiviteitCode' => 'bouwen-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-BOUWEN-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'kappen-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-KAPPEN-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'uitrit-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-UITRIT-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'milieu-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-MILIEU-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'slopen-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-SLOPEN-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'reclame-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-RECLAME-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'opslaan-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-OPSLAG-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'lozen-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-LOZEN-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'monument-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-MONUMENT-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'inrit-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-INRIT-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'weg-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-WEG-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'water-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-WATER-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'grond-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-GROND-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'natuur-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-NATUUR-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'geluid-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-GELUID-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'lucht-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-LUCHT-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'bodem-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-BODEM-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'brand-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-BRAND-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'evenement-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-EVENEMENT-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'gebruik-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-GEBRUIK-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'inrichting-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-INRICHTING-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'aanleg-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-AANLEG-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'vellen-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-VELLEN-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'reclamebord-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-RECLAMEBORD-2024',
				'samenloopStrategie' => 'gecombineerd',
				'isActief' => true,
			],
			[
				'dsoActiviteitCode' => 'energie-01',
				'zaaktypeIdentificatie' => 'ZAAKTYPE-ENERGIE-2024',
				'samenloopStrategie' => 'deelzaken',
				'isActief' => true,
			],
		];

	}//end getDefaultMappings()

	/**
	 * Map one parsed verzoek's activiteiten into the fields intake stores on
	 * the `dso_verzoek` object.
	 *
	 * Each activiteit keeps its code and omschrijving. A mapped one gains its
	 * `caseType` and `samenloopStrategy`. `mappedCaseTypes` lists each zaaktype
	 * once. `samenloopStrategy` is set only when at least one activiteit is
	 * mapped. `activityUnmapped` flags the request for triage (REQ-DSO-013).
	 *
	 * @param array $activiteiten The parsed activiteiten (each with `code` and `omschrijving`).
	 *
	 * @return array<string, mixed> The `mappedActivities`, `mappedCaseTypes`, `activityUnmapped`
	 *                              and, when anything is mapped, `samenloopStrategy` fields.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function mapRequest(array $activiteiten): array {
		// The mapper splits mapped from unmapped; the position puts them back in request order.
		$activities = [];
		foreach (array_values($activiteiten) as $position => $activity) {
			if (is_array($activity) === true) {
				$activities[] = (['position' => $position] + $activity);
			}
		}

		$result = $this->mapActiviteitenToZaaktypen(
			activiteiten: $activities,
			mappingTable: $this->defaultMappingTable()
		);

		$entries = [];
		$caseTypes = [];
		$unmapped = false;
		foreach (array_merge($result['mapped'], $result['unmapped']) as $activity) {
			$entry = [
				'code' => (string)($activity['code'] ?? ''),
				'description' => (string)($activity['omschrijving'] ?? ''),
				'mapped' => ($activity['mapped'] === true && $activity['zaaktypeIdentificatie'] !== null),
			];
			if ($entry['mapped'] === true) {
				$entry['caseType'] = (string)$activity['zaaktypeIdentificatie'];
				$entry['samenloopStrategy'] = (string)$activity['samenloopStrategie'];
				$caseTypes[$entry['caseType']] = true;
			}

			$unmapped = ($unmapped === true || $entry['mapped'] === false);
			$entries[$activity['position']] = $entry;
		}

		ksort($entries);

		$fields = [
			'mappedActivities' => array_values($entries),
			'mappedCaseTypes' => array_keys($caseTypes),
			'activityUnmapped' => $unmapped,
		];
		if (count($result['mapped']) > 0) {
			$fields['samenloopStrategy'] = $this->determineSamenloopStrategy(mappedActiviteiten: $result['mapped']);
		}

		return $fields;
	}//end mapRequest()

	/**
	 * The active default mappings, keyed by activiteitcode, in the shape
	 * {@see self::mapActiviteitenToZaaktypen()} reads.
	 *
	 * @return array<string, array{zaaktypeIdentificatie: string, samenloopStrategie: string}> The table.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function defaultMappingTable(): array {
		$table = [];
		foreach ($this->getDefaultMappings() as $mapping) {
			if (($mapping['isActief'] ?? false) !== true) {
				continue;
			}

			$table[$mapping['dsoActiviteitCode']] = [
				'zaaktypeIdentificatie' => $mapping['zaaktypeIdentificatie'],
				'samenloopStrategie' => $mapping['samenloopStrategie'],
			];
		}

		return $table;
	}//end defaultMappingTable()

	/**
	 * Determine the samenloop strategy for a set of mapped activiteiten.
	 *
	 * Returns 'gecombineerd' only when ALL mapped activiteiten carry that strategy.
	 * Returns 'deelzaken' in all other cases (including an empty set).
	 *
	 * @param array $mappedActiviteiten Array of mapped activiteit objects, each with a
	 *                                  'samenloopStrategie' key.
	 *
	 * @return string Either 'gecombineerd' or 'deelzaken'.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-samenloop-handling-req-dso-011
	 */
	public function determineSamenloopStrategy(array $mappedActiviteiten): string {
		if (count($mappedActiviteiten) === 0) {
			return 'deelzaken';
		}

		foreach ($mappedActiviteiten as $activity) {
			$strategy = ($activity['samenloopStrategie'] ?? 'deelzaken');
			if ($strategy !== 'gecombineerd') {
				return 'deelzaken';
			}
		}

		return 'gecombineerd';
	}//end determineSamenloopStrategy()
}//end class
