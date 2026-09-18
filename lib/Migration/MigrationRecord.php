<?php

/**
 * One record a migration source yields, and the identity it held before.
 *
 * @category ValueObject
 * @package  OCA\Integriq\Migration
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

namespace OCA\Integriq\Migration;

/**
 * The provenance block is the one `registry-backed-field-source` REQ-RFS-003
 * already defines, not a second shape for the same idea.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-every-yielded-record-carries-its-foreign-identity-req-msa-005
 */
final class MigrationRecord {
	/**
	 * Constructor.
	 *
	 * @param string $kind The record kind, as `describe()` names it.
	 * @param array<string,mixed> $data The record's fields.
	 * @param string $sourceId The migration source it came from.
	 * @param string|null $foreignId Its identifier in that system, null when the kind has no stable key.
	 * @param int|null $readAt Unix timestamp of the read.
	 */
	public function __construct(
		private readonly string $kind,
		private readonly array $data,
		private readonly string $sourceId,
		private readonly ?string $foreignId,
		private readonly ?int $readAt = null,
	) {
	}//end __construct()

	/**
	 * The record kind.
	 *
	 * @return string Record kind.
	 */
	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * The record's fields.
	 *
	 * @return array<string,mixed> The data.
	 */
	public function getData(): array {
		return $this->data;
	}//end getData()

	/**
	 * Its identifier in the system it came from.
	 *
	 * @return string|null Foreign identifier, null when the kind has no stable key.
	 */
	public function getForeignId(): ?string {
		return $this->foreignId;
	}//end getForeignId()

	/**
	 * Whether this record can be matched against an earlier run.
	 *
	 * @return bool True when it carries a foreign identifier.
	 */
	public function isMatchable(): bool {
		return ($this->foreignId !== null && $this->foreignId !== '');
	}//end isMatchable()

	/**
	 * The record as the import engine reads it.
	 *
	 * Integriq says what this record is and where it came from. What the
	 * engine does with a match is the engine's decision, not this one's.
	 *
	 * @return array<string,mixed> Serialisable record.
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind,
			'data' => $this->data,
			'provenance' => [
				'origin' => 'source',
				'provider' => $this->sourceId,
				'sourceIdentifier' => $this->foreignId,
				'readAt' => $this->readAt,
				'cacheAgeSeconds' => null,
				'unreachable' => false,
				'live' => true,
			],
			'match' => [
				'matchable' => $this->isMatchable(),
				'on' => ['provider' => $this->sourceId, 'sourceIdentifier' => $this->foreignId],
			],
		];
	}//end toArray()
}//end class
