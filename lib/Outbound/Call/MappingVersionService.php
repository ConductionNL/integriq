<?php

/**
 * Integriq MappingVersionService.
 *
 * Keeps a snapshot of a mapping as it stood when a call ran under it, so a
 * replay can offer the original version beside the current one and say which
 * it used. Without the snapshot a replay a month later silently runs the
 * mapping as it is now, which is a different call that happens to look
 * similar.
 *
 * Integriq cannot make the mapping editor version rather than mutate: a
 * mapping is saved through OpenRegister's own objects endpoint, not through
 * an integriq controller. What integriq can do is snapshot before it uses
 * one, which is what this does.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
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
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateTimeImmutable;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Snapshots and resolves mapping versions.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-replay-names-the-mapping-version-it-ran-under-req-ocd-005
 */
class MappingVersionService {

	/**
	 * The schema one mapping snapshot is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'mapping_version';

	/**
	 * The schema a mapping itself lives under.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPING = 'mapping';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads mappings and writes snapshots.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * Snapshot a mapping as it stands now, unless that version is already kept.
	 *
	 * @param array<string,mixed> $mapping The mapping.
	 *
	 * @return string The version snapshotted, empty when the mapping declares none.
	 */
	public function snapshot(array $mapping): string {
		$slug = $this->slugOf($mapping);
		$version = trim((string)($mapping['version'] ?? ''));
		if ($slug === '' || $version === '') {
			return '';
		}

		if ($this->find($slug, $version) !== null) {
			return $version;
		}

		$this->objectService->saveObject(
			object: [
				'mapping' => $slug,
				'version' => $version,
				'snapshot' => $mapping,
				'recordedAt' => (new DateTimeImmutable())->format('c'),
			],
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

		return $version;

	}//end snapshot()

	/**
	 * The versions a replay may choose between.
	 *
	 * @param string $slug The mapping.
	 * @param string $recordedVersion The version the call ran under.
	 *
	 * @return array{recorded:string,current:string,differ:bool} What is on offer.
	 */
	public function choices(string $slug, string $recordedVersion): array {
		$current = (string)($this->currentMapping($slug)['version'] ?? '');

		return [
			'recorded' => $recordedVersion,
			'current' => $current,
			'differ' => ($current !== '' && $recordedVersion !== '' && $current !== $recordedVersion),
		];

	}//end choices()

	/**
	 * Resolve the mapping a replay should run under.
	 *
	 * Nothing is chosen implicitly: a caller that names no version gets the
	 * version the call ran under, which is the one thing that cannot surprise
	 * anybody.
	 *
	 * @param string $slug The mapping.
	 * @param string $recordedVersion The version the call ran under.
	 * @param string|null $requested The version the administrator chose, or null.
	 *
	 * @return array{version:string,mapping:array<string,mixed>} The version used and its mapping.
	 */
	public function resolve(string $slug, string $recordedVersion, ?string $requested = null): array {
		$wanted = $recordedVersion;
		if ($requested !== null && trim($requested) !== '') {
			$wanted = trim($requested);
		}

		$snapshot = $this->find($slug, $wanted);
		if ($snapshot !== null) {
			$mapping = ($snapshot['snapshot'] ?? []);
			if (is_array($mapping) === false) {
				$mapping = [];
			}

			return [
				'version' => $wanted,
				'mapping' => $mapping,
			];
		}

		$current = $this->currentMapping($slug);

		return [
			'version' => (string)($current['version'] ?? $wanted),
			'mapping' => $current,
		];

	}//end resolve()

	/**
	 * The mapping as it stands now.
	 *
	 * @param string $slug The mapping.
	 *
	 * @return array<string,mixed> The mapping, empty when there is none.
	 */
	private function currentMapping(string $slug): array {
		foreach ($this->rows(self::SCHEMA_MAPPING, ['slug' => $slug]) as $row) {
			$mapping = $row->getObject();
			if ($this->slugOf($mapping) === $slug) {
				return $mapping;
			}
		}

		return [];

	}//end currentMapping()

	/**
	 * One stored snapshot.
	 *
	 * @param string $slug The mapping.
	 * @param string $version The version.
	 *
	 * @return array<string,mixed>|null The snapshot, or null.
	 */
	private function find(string $slug, string $version): ?array {
		foreach ($this->rows(self::SCHEMA, ['mapping' => $slug, 'version' => $version]) as $row) {
			$snapshot = $row->getObject();
			if ((string)($snapshot['mapping'] ?? '') === $slug
				&& (string)($snapshot['version'] ?? '') === $version
			) {
				return $snapshot;
			}
		}

		return null;

	}//end find()

	/**
	 * Read rows of one schema.
	 *
	 * @param string $schema The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,ObjectEntity> The rows.
	 */
	private function rows(string $schema, array $filters): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => array_merge(
					['register' => MessageRecorder::REGISTER, 'schema' => $schema],
					$filters
				),
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		$rows = [];
		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === true) {
				$rows[] = $row;
			}
		}

		return $rows;

	}//end rows()

	/**
	 * A mapping's slug, however it names itself.
	 *
	 * @param array<string,mixed> $mapping The mapping.
	 *
	 * @return string The slug.
	 */
	private function slugOf(array $mapping): string {
		$slug = trim((string)($mapping['slug'] ?? ''));
		if ($slug !== '') {
			return $slug;
		}

		return trim((string)($mapping['name'] ?? ''));

	}//end slugOf()

}//end class
