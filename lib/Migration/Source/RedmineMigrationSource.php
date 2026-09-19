<?php

/**
 * Redmine, read through the source that already reaches it.
 *
 * @category Adapter
 * @package  OCA\Integriq\Migration\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration\Source;

use OCA\Integriq\Migration\MigrationRecord;
use OCA\Integriq\Migration\MigrationSourceAdapterInterface;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\RegistrySourceGateway;

/**
 * The first incumbent adapter. Redmine was chosen because its REST API is
 * public, stable and versioned, because Easy Redmine speaks the same one, and
 * because it is the incumbent behind four of the six passers on this cluster.
 * Everything it does here is a read: Redmine is not written to, and neither is
 * the target.
 *
 * A second incumbent is a class beside this one and a line in the registry.
 * Nothing in the engine and nothing in a consuming app changes.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-named-incumbent-has-an-adapter-and-a-supported-path-is-rehearsable-req-msa-003
 */
class RedmineMigrationSource implements MigrationSourceAdapterInterface {
	/**
	 * The source id a migration names.
	 */
	public const ID = 'redmine';

	/**
	 * Slug of the source this adapter reads through.
	 */
	public const SOURCE_SLUG = 'redmine';

	/**
	 * How many records one page asks for.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * The record kinds this adapter yields, and what each is keyed on.
	 *
	 * @var array<string,array{endpoint:string,collection:string,identifier:?string,label:string}>
	 */
	private const KINDS = [
		'issue' => [
			'endpoint' => '/issues.json',
			'collection' => 'issues',
			'identifier' => 'id',
			'label' => 'Issue',
		],
		'project' => [
			'endpoint' => '/projects.json',
			'collection' => 'projects',
			'identifier' => 'id',
			'label' => 'Project',
		],
		'journal' => [
			'endpoint' => '/journals.json',
			'collection' => 'journals',
			// Redmine's journal entries are not addressable on their own, so
			// this kind is declared as having no stable key rather than
			// pretending one exists.
			'identifier' => null,
			'label' => 'Journal entry',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param RegistrySourceGateway $gateway The one way to reach a configured source.
	 */
	public function __construct(private readonly RegistrySourceGateway $gateway) {
	}//end __construct()

	/**
	 * The source id.
	 *
	 * @return string Source id.
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * What this adapter can yield, before any read is attempted.
	 *
	 * @return array{id:string,label:string,kinds:array<int,array{kind:string,label:string,identifier:?string,stableIdentifier:bool}>}
	 */
	public function describe(): array {
		$kinds = [];
		foreach (self::KINDS as $kind => $definition) {
			$kinds[] = [
				'kind' => $kind,
				'label' => $definition['label'],
				'identifier' => $definition['identifier'],
				'stableIdentifier' => ($definition['identifier'] !== null),
			];
		}

		return ['id' => self::ID, 'label' => 'Redmine', 'kinds' => $kinds];
	}//end describe()

	/**
	 * How many records of one kind Redmine holds.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 *
	 * @return array{count:int,complete:bool} The count and its completeness.
	 */
	public function count(string $kind, array $config = []): array {
		$definition = (self::KINDS[$kind] ?? null);
		if ($definition === null) {
			return ['count' => 0, 'complete' => false];
		}

		try {
			$body = $this->gateway->read(
				self::ID,
				(string)($config['source'] ?? self::SOURCE_SLUG),
				$definition['endpoint'],
				['limit' => 1]
			);
		} catch (SourceUnreachableException $e) {
			// An unreachable source is not a count of zero. It is an unknown
			// count, and it is reported as incomplete.
			return ['count' => 0, 'complete' => false];
		}

		return ['count' => (int)($body['total_count'] ?? 0), 'complete' => true];
	}//end count()

	/**
	 * Every record of one kind, page by page.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 *
	 * @return iterable<MigrationRecord> The records.
	 */
	public function read(string $kind, array $config = []): iterable {
		$definition = (self::KINDS[$kind] ?? null);
		if ($definition === null) {
			return [];
		}

		$records = [];
		$offset = 0;
		$readAt = time();

		while (true) {
			try {
				$body = $this->gateway->read(
					self::ID,
					(string)($config['source'] ?? self::SOURCE_SLUG),
					$definition['endpoint'],
					['limit' => self::PAGE_SIZE, 'offset' => $offset]
				);
			} catch (SourceUnreachableException $e) {
				break;
			}

			$page = ($body[$definition['collection']] ?? []);
			if (is_array($page) === false || $page === []) {
				break;
			}

			foreach ($page as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$records[] = $this->toRecord(kind: $kind, definition: $definition, row: $row, readAt: $readAt);
			}

			$offset += count($page);
			if ($offset >= (int)($body['total_count'] ?? $offset)) {
				break;
			}
		}//end while

		return $records;
	}//end read()

	/**
	 * A bounded sample of one kind.
	 *
	 * @param string $kind The record kind.
	 * @param array<string,mixed> $config The migration's configuration.
	 * @param int $limit How many records at most.
	 *
	 * @return array<int,MigrationRecord> The sample.
	 */
	public function sample(string $kind, array $config = [], int $limit = 5): array {
		$definition = (self::KINDS[$kind] ?? null);
		if ($definition === null) {
			return [];
		}

		try {
			$body = $this->gateway->read(
				self::ID,
				(string)($config['source'] ?? self::SOURCE_SLUG),
				$definition['endpoint'],
				['limit' => max(1, $limit)]
			);
		} catch (SourceUnreachableException $e) {
			return [];
		}

		$readAt = time();
		$sample = [];
		foreach ((array)($body[$definition['collection']] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$sample[] = $this->toRecord(kind: $kind, definition: $definition, row: $row, readAt: $readAt);
			if (count($sample) >= $limit) {
				break;
			}
		}

		return $sample;
	}//end sample()

	/**
	 * Turn one Redmine row into a record carrying its foreign identity.
	 *
	 * @param string $kind The record kind.
	 * @param array{endpoint:string,collection:string,identifier:?string,label:string} $definition The kind's definition.
	 * @param array<string,mixed> $row The row.
	 * @param int $readAt Unix timestamp of the read.
	 *
	 * @return MigrationRecord The record.
	 */
	private function toRecord(string $kind, array $definition, array $row, int $readAt): MigrationRecord {
		$foreignId = null;
		if ($definition['identifier'] !== null && ($row[$definition['identifier']] ?? null) !== null) {
			$foreignId = (string)$row[$definition['identifier']];
		}

		return new MigrationRecord(kind: $kind, data: $row, sourceId: self::ID, foreignId: $foreignId, readAt: $readAt);
	}//end toRecord()
}//end class
