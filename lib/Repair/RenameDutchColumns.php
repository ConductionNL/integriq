<?php

/**
 * Integriq RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * THE SCHEMA DECIDES THE DIRECTION, PER TABLE. A Dutch name is not always a
 * mistake: a wire name stays Dutch (`kenmerk` in rod_message, verzuim_message,
 * oso_message and uwlr_eduv_message is the partner's correlation field). This
 * step used to apply the map to EVERY table of the register, so it renamed
 * those four `kenmerk` columns to `reference` while their schemas still
 * declare `kenmerk`. Every write then dropped the value and every lookup by
 * kenmerk failed with "column t.kenmerk does not exist" (integriq#2520 live
 * run-7). Now a table moves towards the name its schema declares:
 *   - the schema declares the English name only: Dutch column -> English;
 *   - the schema declares the Dutch name only: English column -> Dutch, which
 *     restores the columns the old behaviour renamed;
 *   - both, neither, or a schema that cannot be read: the table is left alone.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when its source exists and its target does not;
 *   - where MagicMapper has already added an empty target column, the data is
 *     copied across and the source column is LEFT IN PLACE, so this is
 *     reversible and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
class RenameDutchColumns implements IRepairStep {
	/**
	 * Slug prefix of the registers in scope.
	 *
	 * Deliberately still `openconnector` after the app-id rename: this is the
	 * OpenRegister REGISTER SLUG, not the app id. The slug is frozen on the old
	 * value because OpenRegister matches registers by it — renaming it would
	 * make this step find no shard tables and report success over an empty set.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'integriq';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
	 * `requested_amount`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'actie_map' => 'action_map',
		'betrokkenen' => 'involved_parties',
		'hoofd_object_field' => 'main_object_field',
		'indicatie_contact_gelukt' => 'indication_contact_succeeded',
		'indieningsdatum' => 'submission_date',
		'kanaal' => 'channel',
		'kenmerk' => 'reference',
		'kenmerken' => 'characteristics',
		'nummer' => 'number',
		'plaatsgevonden_op' => 'occurred_on',
		'raw_verzoek' => 'raw_request',
		'taal' => 'language',
		'zaak_id' => 'case_id',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move openconnector data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every openconnector shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchColumns: no openconnector shard tables on this install; nothing to do.');
			return;
		}

		$tally = ['renamed' => 0, 'restored' => 0, 'copied' => 0, 'refused' => 0, 'unread' => 0];

		foreach ($tables as $table) {
			$declared = $this->declaredColumns(table: $table);
			if ($declared === null) {
				// Without the schema there is no way to tell a wire name from a
				// leftover, so the table is left as it is.
				$tally['unread']++;
				continue;
			}

			$this->migrateTable(table: $table, declared: $declared, tally: $tally);
		}

		$output->info(
			'RenameDutchColumns: ' . $tally['renamed'] . ' renamed, ' . $tally['restored'] . ' restored to the declared Dutch name, '
			. $tally['copied'] . ' back-filled, ' . $tally['refused'] . ' refused, ' . $tally['unread'] . ' skipped (schema unreadable), across '
			. count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Move one table's columns towards the names its schema declares.
	 *
	 * @param string                $table    The shard table.
	 * @param array<int, string>    $declared The column names the table's schema declares.
	 * @param array<string, int>    $tally    Counters, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#requirement-a-column-follows-the-name-its-schema-declares-req-rv-001
	 */
	private function migrateTable(string $table, array $declared, array &$tally): void {
		$columns = $this->columnsOf(table: $table);
		$qTable = $this->quote(identifier: $table);

		foreach (self::COLUMN_MAP as $dutch => $english) {
			$move = $this->direction(declared: $declared, dutch: $dutch, english: $english);
			if ($move === null || in_array($move['from'], $columns, true) === false) {
				continue;
			}

			$from = $move['from'];
			$to = $move['to'];
			if ($from === $dutch && $this->hasCollision(columns: $columns, target: $to) === true) {
				$this->logger->warning(
					'RenameDutchColumns: two sources target one destination; migrating neither.',
					['table' => $table, 'source' => $from, 'destination' => $to]
				);
				$tally['refused']++;
				continue;
			}

			if (in_array($to, $columns, true) === false) {
				$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN '
					. $this->quote(identifier: $from) . ' TO ' . $this->quote(identifier: $to);
				if ($this->exec(sql: $sql) === true) {
					$tally[$move['kind']]++;
				}

				continue;
			}

			$qTo = $this->quote(identifier: $to);
			$qFrom = $this->quote(identifier: $from);
			$sql = 'UPDATE ' . $qTable . ' SET ' . $qTo . ' = ' . $qFrom
				. ' WHERE ' . $qTo . ' IS NULL AND ' . $qFrom . ' IS NOT NULL';
			if ($this->exec(sql: $sql) === true) {
				$tally['copied']++;
			}
		}//end foreach

	}//end migrateTable()

	/**
	 * Which way one Dutch/English pair moves in a table, or null to leave it.
	 *
	 * @param array<int, string> $declared The column names the table's schema declares.
	 * @param string             $dutch    The Dutch column name.
	 * @param string             $english  The English column name.
	 *
	 * @return array{from: string, to: string, kind: string}|null
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#requirement-a-column-follows-the-name-its-schema-declares-req-rv-001
	 */
	private function direction(array $declared, string $dutch, string $english): ?array {
		$declaresDutch = in_array($dutch, $declared, true);
		$declaresEnglish = in_array($english, $declared, true);
		if ($declaresEnglish === true && $declaresDutch === false) {
			return ['from' => $dutch, 'to' => $english, 'kind' => 'renamed'];
		}

		if ($declaresDutch === true && $declaresEnglish === false) {
			return ['from' => $english, 'to' => $dutch, 'kind' => 'restored'];
		}

		return null;

	}//end direction()

	/**
	 * The column names the schema of a shard table declares, or null when unreadable.
	 *
	 * The schema id is the last number of the table name
	 * (`openregister_table_{register}_{schema}`); the property names are
	 * snake_cased the way MagicMapper::sanitizeColumnName() names a column.
	 *
	 * @param string $table The shard table.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#requirement-a-column-follows-the-name-its-schema-declares-req-rv-001
	 */
	private function declaredColumns(string $table): ?array {
		if (preg_match('/openregister_table_\d+_(\d+)$/', $table, $match) !== 1) {
			return null;
		}

		try {
			$raw = $this->db->executeQuery(
				'SELECT properties FROM `*PREFIX*openregister_schemas` WHERE id = ?',
				[(int)$match[1]]
			)->fetchOne();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read the schema of a table; skipping it.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$properties = json_decode((string)$raw, true);
		if (is_array($properties) === false) {
			return null;
		}

		$names = [];
		foreach (array_keys($properties) as $name) {
			$names[] = self::columnName(property: (string)$name);
		}

		return $names;

	}//end declaredColumns()

	/**
	 * The column name MagicMapper gives a property: camelCase to snake_case.
	 *
	 * @param string $property The property name.
	 *
	 * @return string
	 */
	private static function columnName(string $property): string {
		$name = strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $property));
		$name = (string)preg_replace('/[^a-z0-9_]/', '_', $name);

		return rtrim((string)preg_replace('/_+/', '_', $name), '_');

	}//end columnName()

	/**
	 * Whether another mapped source already targets the same destination here.
	 *
	 * @param array<int, string> $columns Column names present in the table.
	 * @param string $target The destination column name.
	 *
	 * @return bool True when two sources compete for one destination.
	 */
	private function hasCollision(array $columns, string $target): bool {
		$sources = 0;
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources++;
			}
		}

		return $sources > 1;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection:
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * Matching anchors on the `openregister_table_` MARKER rather than a computed
	 * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
	 * which a raw information_schema string never resolves.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIds();
		if ($ids === []) {
			return [];
		}

		$names = $this->openRegisterTableNames();
		if ($names === []) {
			return [];
		}

		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		$tables = [];
		foreach ($names as $name) {
			foreach ($markers as $marker) {
				$offset = strpos($name, $marker);
				if ($offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}

	/**
	 * Ids of every register whose slug starts with the prefix.
	 *
	 * Split out of shardTables() only to keep that method under phpmd's
	 * cyclomatic-complexity limit; the behaviour is unchanged.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIds(): array {
		try {
			return $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}

	/**
	 * Every table name containing the openregister shard marker.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end shardTables()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one statement, logging and swallowing failure.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
