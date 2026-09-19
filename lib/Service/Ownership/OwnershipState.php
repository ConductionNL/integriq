<?php

/**
 * Who owns a record, and what the source last said about it.
 *
 * @category ValueObject
 * @package  OCA\Integriq\Service\Ownership
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

namespace OCA\Integriq\Service\Ownership;

/**
 * One answer, carrying the mode, the source, the origin identifier, the
 * last-seen timestamp and the absence state. A consuming app reads this and
 * never a contract, a synchronisation or a source.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-the-consuming-app-reads-ownership-through-one-contract-req-sor-006
 */
final class OwnershipState {
	/**
	 * The source owns every value.
	 */
	public const MODE_SOURCE = 'source';

	/**
	 * The source owns its values, and this instance adds its own beside them.
	 */
	public const MODE_SOURCE_WITH_LOCAL_ADDITIONS = 'source with local additions';

	/**
	 * Nobody outside maintains this record.
	 */
	public const MODE_LOCAL = 'local';

	/**
	 * Every mode a synchronisation may declare.
	 *
	 * @var array<int,string>
	 */
	public const MODES = [self::MODE_SOURCE, self::MODE_SOURCE_WITH_LOCAL_ADDITIONS, self::MODE_LOCAL];

	/**
	 * Constructor.
	 *
	 * @param string $mode One of the MODE_* constants.
	 * @param string|null $source The source slug or id, null when local.
	 * @param string|null $originId The identifier at that source, null when local.
	 * @param string|null $lastSeenAt ISO timestamp of the last complete run that still saw it.
	 * @param bool $lastSeenUnknown Whether the last-seen answer is unknown rather than absent.
	 * @param bool $absentAtSource Whether the source has stopped carrying it.
	 * @param string|null $endedAt ISO timestamp the record was ended on, under markEnded.
	 * @param string|null $synchronizationId The synchronisation that maintains it.
	 * @param string|null $synchronizationName Its name, for a message a person reads.
	 */
	public function __construct(
		private readonly string $mode,
		private readonly ?string $source = null,
		private readonly ?string $originId = null,
		private readonly ?string $lastSeenAt = null,
		private readonly bool $lastSeenUnknown = false,
		private readonly bool $absentAtSource = false,
		private readonly ?string $endedAt = null,
		private readonly ?string $synchronizationId = null,
		private readonly ?string $synchronizationName = null,
	) {
	}//end __construct()

	/**
	 * A record nobody outside maintains.
	 *
	 * @return self A local ownership state.
	 */
	public static function local(): self {
		return new self(self::MODE_LOCAL);
	}//end local()

	/**
	 * The ownership mode.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function getMode(): string {
		return $this->mode;
	}//end getMode()

	/**
	 * Whether an external source owns this record.
	 *
	 * @return bool True for `source` and `source with local additions`.
	 */
	public function isSourceOwned(): bool {
		return ($this->mode === self::MODE_SOURCE || $this->mode === self::MODE_SOURCE_WITH_LOCAL_ADDITIONS);
	}//end isSourceOwned()

	/**
	 * The synchronisation that maintains this record.
	 *
	 * @return string|null Synchronisation id.
	 */
	public function getSynchronizationId(): ?string {
		return $this->synchronizationId;
	}//end getSynchronizationId()

	/**
	 * The name of the synchronisation that maintains this record.
	 *
	 * @return string|null Synchronisation name.
	 */
	public function getSynchronizationName(): ?string {
		return $this->synchronizationName;
	}//end getSynchronizationName()

	/**
	 * The identifier at the source.
	 *
	 * @return string|null Origin identifier.
	 */
	public function getOriginId(): ?string {
		return $this->originId;
	}//end getOriginId()

	/**
	 * Whether the source has stopped carrying this record.
	 *
	 * @return bool True when it is absent at the source.
	 */
	public function isAbsentAtSource(): bool {
		return $this->absentAtSource;
	}//end isAbsentAtSource()

	/**
	 * The answer as a consuming app reads it.
	 *
	 * @return array<string,mixed> Serialisable ownership state.
	 */
	public function toArray(): array {
		$lastSeen = 'known';
		if ($this->lastSeenUnknown === true) {
			$lastSeen = 'unknown';
		}

		return [
			'mode' => $this->mode,
			'source' => $this->source,
			'originId' => $this->originId,
			'lastSeenAt' => $this->lastSeenAt,
			'lastSeen' => $lastSeen,
			'absentAtSource' => $this->absentAtSource,
			'endedAt' => $this->endedAt,
			'synchronization' => [
				'id' => $this->synchronizationId,
				'name' => $this->synchronizationName,
			],
		];
	}//end toArray()
}//end class
