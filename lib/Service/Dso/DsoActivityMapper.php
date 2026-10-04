<?php

/**
 * Integriq DSO Activity Mapper.
 *
 * Maps the activiteiten of one DSO verzoek to case types and decides the
 * samenloop strategy (REQ-DSO-010, REQ-DSO-011). The table is the
 * `dso_activity_mapping` rows an administrator keeps in OpenRegister, read
 * once per verzoek through {@see DsoActivityTable} (change
 * dso-activity-mapping-table). There is no built-in table: no public static
 * list of DSO activity identifiers exists to ship one (design.md, Research).
 *
 * Intake calls {@see self::mapRequest()} from
 * {@see \OCA\Integriq\Service\DsoIngestService::ingest()}, so every
 * `dso_verzoek` records its case types and samenloop strategy.
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
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * Maps DSO activiteiten to case types and decides the samenloop strategy.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */
class DsoActivityMapper {

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
	 * The identifiers tried per activiteit, most specific first (design D2).
	 *
	 * Each entry is [where the identifier sits, the identifier, the index it is looked up in].
	 *
	 * @var array<int, array{0: string|null, 1: string, 2: string}>
	 */
	private const MATCH_ORDER = [
		['underlying', 'imowId', 'imowId'],
		['underlying', 'activityId', 'activityId'],
		[null, 'imowId', 'imowId'],
		[null, 'activityId', 'activityId'],
	];

	/**
	 * Constructor.
	 *
	 * @param DsoActivityTable $table Reads the administrator's mapping rows.
	 */
	public function __construct(
		private readonly DsoActivityTable $table,
	) {

	}//end __construct()

	/**
	 * Map one parsed verzoek's activiteiten into the fields intake stores on
	 * the `dso_verzoek` object.
	 *
	 * The active rows are read once. Each activiteit keeps its STAM
	 * identifiers. A mapped one gains its case types, each with its
	 * department, the samenloop strategy of its row, the row's uuid and the
	 * identifier it matched on. `mappedCaseTypes` lists each case type
	 * reference once. `samenloopStrategy` is set only when at least one
	 * activiteit is mapped. `activityUnmapped` flags the verzoek for triage.
	 *
	 * @param array $activiteiten The parsed activiteiten ({@see \OCA\Integriq\Service\DSOParserService::parseRequest()}).
	 *
	 * @return array<string, mixed> The `mappedActivities`, `mappedCaseTypes`, `activityUnmapped`
	 *                              and, when anything is mapped, `samenloopStrategy` fields.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.1
	 */
	public function mapRequest(array $activiteiten): array {
		$index = $this->index(rows: $this->table->activeRows());

		$entries = [];
		$matchedRows = [];
		$caseTypes = [];
		$unmapped = false;
		foreach ($activiteiten as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$entry = $this->identifiers(activity: $activity);
			$match = $this->match(activity: $activity, index: $index);
			if ($match === null) {
				$entry['mapped'] = false;
				$unmapped = true;
				$entries[] = $entry;
				continue;
			}

			[$row, $matchedOn] = $match;
			$entry['mapped'] = true;
			$entry['matchedOn'] = $matchedOn;
			$entry['mappingRow'] = (string)$row['id'];
			$entry['caseTypes'] = $this->caseTypes(row: $row);
			$entry['samenloopStrategy'] = $this->rowStrategy(row: $row);
			foreach ($entry['caseTypes'] as $caseType) {
				$caseTypes[$caseType['reference']] = true;
			}

			$matchedRows[] = $row;
			$entries[] = $entry;
		}//end foreach

		$fields = [
			'mappedActivities' => $entries,
			'mappedCaseTypes' => array_map('strval', array_keys($caseTypes)),
			'activityUnmapped' => $unmapped,
		];
		if (count($matchedRows) > 0) {
			$fields['samenloopStrategy'] = $this->samenloopStrategy(rows: $matchedRows);
		}

		return $fields;

	}//end mapRequest()

	/**
	 * Whether an activiteit, in the parser's shape, matches one of the active rows.
	 *
	 * Used by the unmapped list to drop activities a row was added for after
	 * the verzoek arrived.
	 *
	 * @param array<string, mixed>                    $activity The activiteit.
	 * @param array<string, array<string, mixed>>|null $index   A prepared {@see self::index()}, or null to read the table.
	 *
	 * @return bool True when a row matches.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-in-the-app-req-dso-012
	 */
	public function isMapped(array $activity, ?array $index = null): bool {
		if ($index === null) {
			$index = $this->index(rows: $this->table->activeRows());
		}

		return $this->match(activity: $activity, index: $index) !== null;

	}//end isMapped()

	/**
	 * Index the active rows by imowId and by activityId. The first row wins.
	 *
	 * @param array<int, array<string, mixed>> $rows The active rows.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The rows under `imowId` and `activityId`.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.1
	 */
	public function index(array $rows): array {
		$index = ['imowId' => [], 'activityId' => []];
		foreach ($rows as $row) {
			foreach (['imowId', 'activityId'] as $key) {
				$value = trim((string)($row[$key] ?? ''));
				if ($value !== '' && isset($index[$key][$value]) === false) {
					$index[$key][$value] = $row;
				}
			}
		}

		return $index;

	}//end index()

	/**
	 * The first row that matches, in the order of design D2, with what it matched on.
	 *
	 * @param array<string, mixed>                               $activity The activiteit.
	 * @param array<string, array<string, array<string, mixed>>> $index    The rows by key.
	 *
	 * @return array{0: array<string, mixed>, 1: string}|null The row and the identifier path, or null.
	 */
	private function match(array $activity, array $index): ?array {
		foreach (self::MATCH_ORDER as [$where, $key, $indexKey]) {
			$source = $activity;
			if ($where !== null) {
				$source = $activity[$where] ?? null;
			}

			if (is_array($source) === false) {
				continue;
			}

			$value = trim((string)($source[$key] ?? ''));
			if ($value !== '' && isset($index[$indexKey][$value]) === true) {
				$path = $key;
				if ($where !== null) {
					$path = $where . '.' . $key;
				}

				return [$index[$indexKey][$value], $path];
			}
		}

		return null;

	}//end match()

	/**
	 * The STAM identifiers of an activiteit, without the empty ones.
	 *
	 * @param array<string, mixed> $activity The activiteit.
	 *
	 * @return array<string, mixed> `imowId`, `activityId`, `activityName`, `volgnr` and `underlying`, when set.
	 */
	private function identifiers(array $activity): array {
		$entry = $this->identifierFields(source: $activity, keys: ['imowId', 'activityId', 'activityName', 'volgnr']);
		if (is_array($activity['underlying'] ?? null) === true) {
			$underlying = $this->identifierFields(
				source: $activity['underlying'],
				keys: ['imowId', 'activityId', 'activityName']
			);
			if ($underlying !== []) {
				$entry['underlying'] = $underlying;
			}
		}

		return $entry;

	}//end identifiers()

	/**
	 * The named scalar fields of a source, as strings, without the empty ones.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param array<int, string>   $keys   The fields.
	 *
	 * @return array<string, string> The fields that are set.
	 */
	private function identifierFields(array $source, array $keys): array {
		$fields = [];
		foreach ($keys as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				$fields[$key] = trim((string)$value);
			}
		}

		return $fields;

	}//end identifierFields()

	/**
	 * The case types of a row, each with a reference and, when set, a title and department.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, array{reference: string, title?: string, department?: string}> The case types.
	 */
	private function caseTypes(array $row): array {
		$caseTypes = [];
		foreach ((array)($row['caseTypes'] ?? []) as $caseType) {
			if (is_array($caseType) === false || trim((string)($caseType['reference'] ?? '')) === '') {
				continue;
			}

			$caseTypes[] = (['reference' => trim((string)$caseType['reference'])] + $this->identifierFields(source: $caseType, keys: ['title', 'department']));
		}

		return $caseTypes;

	}//end caseTypes()

	/**
	 * A row's own samenloop strategy, `deelzaken` when it names none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The strategy.
	 */
	private function rowStrategy(array $row): string {
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
	private function samenloopStrategy(array $rows): string {
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

	}//end samenloopStrategy()

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
