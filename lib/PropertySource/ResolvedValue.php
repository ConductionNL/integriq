<?php

/**
 * A resolved property value and the provenance it carries.
 *
 * @category ValueObject
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

/**
 * Provenance is a field on the value, never a note or a log line.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */
final class ResolvedValue {
	/**
	 * Read straight from the source.
	 */
	public const ORIGIN_SOURCE = 'source';

	/**
	 * Served from a cache entry that reports its age.
	 */
	public const ORIGIN_CACHE = 'cache';

	/**
	 * Typed by a person, so nobody looked it up.
	 */
	public const ORIGIN_MANUAL = 'manual';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $value The value itself, or null when nothing could be served.
	 * @param string|null $providerId Provider id, null for a typed value.
	 * @param string|null $sourceIdentifier Identifier at the source, null for a typed value.
	 * @param int|null $readAt Unix timestamp of the read, null for a typed value.
	 * @param string $origin One of the ORIGIN_* constants.
	 * @param int|null $cacheAgeSeconds Age of the cache entry, null when not served from cache.
	 * @param bool $unreachable Whether the source could not be reached on this read.
	 */
	public function __construct(
		private readonly ?array $value,
		private readonly ?string $providerId,
		private readonly ?string $sourceIdentifier,
		private readonly ?int $readAt,
		private readonly string $origin,
		private readonly ?int $cacheAgeSeconds = null,
		private readonly bool $unreachable = false,
	) {
	}//end __construct()

	/**
	 * A value read straight from the source.
	 *
	 * @param array<string,mixed> $value The value.
	 * @param string $providerId Provider id.
	 * @param string $identifier Identifier at the source.
	 * @param int $readAt Unix timestamp of the read.
	 *
	 * @return self Resolved value with source provenance.
	 */
	public static function fromSource(array $value, string $providerId, string $identifier, int $readAt): self {
		return new self($value, $providerId, $identifier, $readAt, self::ORIGIN_SOURCE);
	}//end fromSource()

	/**
	 * A value served from cache, reporting its age.
	 *
	 * @param array<string,mixed> $value The value.
	 * @param string $providerId Provider id.
	 * @param string $identifier Identifier at the source.
	 * @param int $readAt Unix timestamp of the original read.
	 * @param int $ageSeconds Age of the cache entry in seconds.
	 * @param bool $unreachable Whether the source was tried and did not answer.
	 *
	 * @return self Resolved value with cache provenance.
	 */
	public static function fromCache(
		array $value,
		string $providerId,
		string $identifier,
		int $readAt,
		int $ageSeconds,
		bool $unreachable = false,
	): self {
		return new self($value, $providerId, $identifier, $readAt, self::ORIGIN_CACHE, $ageSeconds, $unreachable);
	}//end fromCache()

	/**
	 * A value a person typed. It carries `manual` and no provider id.
	 *
	 * @param array<string,mixed> $value The typed value.
	 *
	 * @return self Resolved value with manual provenance.
	 */
	public static function manual(array $value): self {
		return new self($value, null, null, null, self::ORIGIN_MANUAL);
	}//end manual()

	/**
	 * Nothing could be served and the source could not be reached.
	 *
	 * @param string $providerId Provider id.
	 * @param string $identifier Identifier that was asked for.
	 *
	 * @return self Resolved value carrying no value and an unreachable state.
	 */
	public static function unreachable(string $providerId, string $identifier): self {
		return new self(null, $providerId, $identifier, null, self::ORIGIN_SOURCE, null, true);
	}//end unreachable()

	/**
	 * The value itself.
	 *
	 * @return array<string,mixed>|null The value, or null when none could be served.
	 */
	public function getValue(): ?array {
		return $this->value;
	}//end getValue()

	/**
	 * Provider id, or null for a typed value.
	 *
	 * @return string|null Provider id.
	 */
	public function getProviderId(): ?string {
		return $this->providerId;
	}//end getProviderId()

	/**
	 * Identifier at the source.
	 *
	 * @return string|null Source identifier.
	 */
	public function getSourceIdentifier(): ?string {
		return $this->sourceIdentifier;
	}//end getSourceIdentifier()

	/**
	 * Unix timestamp of the read.
	 *
	 * @return int|null Read timestamp.
	 */
	public function getReadAt(): ?int {
		return $this->readAt;
	}//end getReadAt()

	/**
	 * Where this value came from.
	 *
	 * @return string One of the ORIGIN_* constants.
	 */
	public function getOrigin(): string {
		return $this->origin;
	}//end getOrigin()

	/**
	 * Age of the cache entry that served this value.
	 *
	 * @return int|null Age in seconds, null when not served from cache.
	 */
	public function getCacheAgeSeconds(): ?int {
		return $this->cacheAgeSeconds;
	}//end getCacheAgeSeconds()

	/**
	 * Whether the source could not be reached on this read.
	 *
	 * @return bool True when the source did not answer.
	 */
	public function isUnreachable(): bool {
		return $this->unreachable;
	}//end isUnreachable()

	/**
	 * Whether this value may be described as live.
	 *
	 * A cached value inside its budget is not live, and neither is a value
	 * served while the source was unreachable.
	 *
	 * @return bool True only for a value read from the source just now.
	 */
	public function isLive(): bool {
		return ($this->origin === self::ORIGIN_SOURCE && $this->unreachable === false && $this->value !== null);
	}//end isLive()

	/**
	 * The provenance block as openregister stores it beside the value.
	 *
	 * @return array<string,mixed> Serialisable provenance.
	 */
	public function toArray(): array {
		return [
			'value' => $this->value,
			'provenance' => [
				'origin' => $this->origin,
				'provider' => $this->providerId,
				'sourceIdentifier' => $this->sourceIdentifier,
				'readAt' => $this->readAt,
				'cacheAgeSeconds' => $this->cacheAgeSeconds,
				'unreachable' => $this->unreachable,
				'live' => $this->isLive(),
			],
		];
	}//end toArray()
}//end class
