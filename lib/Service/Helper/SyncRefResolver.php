<?php

/**
 * Integriq synchronization sourceId/targetId resolver helper.
 *
 * Classifies a Synchronization sourceId/targetId value into one of four variants
 * (integer primary key / register-schema slug pair / RFC 4122 uuid / unrecognised)
 * and resolves a legacy integer primary key to the matching OpenRegister source
 * object's uuid via `\OCA\OpenRegister\Service\ObjectService`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Classify and resolve a Synchronization sourceId/targetId value.
 *
 * Synchronizations carry sourceId/targetId in three legitimate shapes:
 *   - an integer primary key against the legacy oc_openconnector_sources table
 *     (resolved to the matching openconnector source object's uuid)
 *   - a register/schema slug pair (e.g. "zaken/zaak" — kept as-is)
 *   - an RFC 4122 uuid (kept as-is)
 *
 * Anything that matches none of those is reported as 'unrecognised' and
 * preserved verbatim — the caller decides how to handle it. Extracted from
 * `LegacyToRegisterMigrator::resolveSyncRef` (chain B) per spec
 * `openconnector-services-direct-or-usage` requirement
 * "Synchronization.sourceId branching logic must survive intact" (D6).
 *
 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
 */
final class SyncRefResolver {

	/**
	 * The reference formats kept as they are, by variant, checked in order.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = [
		'register-schema' => '/^[\w-]+\/[\w-]+$/',
		'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve a sourceId/targetId value to its uuid form + variant tag.
	 *
	 * @param string $value The raw sourceId/targetId value.
	 *
	 * @return array{value: string, variant: 'integer-pk'|'register-schema'|'uuid'|'unrecognised'}
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public function resolve(string $value): array {
		if ($value === '') {
			return ['value' => $value, 'variant' => 'unrecognised'];
		}

		if (preg_match('/^\d+$/', $value) === 1) {
			$uuid = $this->lookupSourceUuidByInt(legacyId: (int)$value);
			return ['value' => ($uuid ?? $value), 'variant' => 'integer-pk'];
		}

		foreach (self::FORMATS as $variant => $pattern) {
			if (preg_match($pattern, $value) === 1) {
				return ['value' => $value, 'variant' => $variant];
			}
		}

		$this->logger->warning(sprintf('SyncRefResolver: value "%s" matches no known format — preserved as-is, marked unrecognised', $value));

		return ['value' => $value, 'variant' => 'unrecognised'];
	}//end resolve()

	/**
	 * Look up the openconnector source uuid by its legacy integer primary key.
	 *
	 * Returns null when no matching source is found; the caller treats this
	 * as "unresolved integer-PK" and preserves the raw value.
	 *
	 * @param int $legacyId The legacy integer primary key of the source.
	 *
	 * @return string|null The resolved source uuid, or null when unresolved.
	 */
	private function lookupSourceUuidByInt(int $legacyId): ?string {
		$objects = $this->sourceRows(legacyId: $legacyId);
		if (is_array($objects) === false || count($objects) === 0) {
			return null;
		}

		return $this->extractUuid(row: $objects[0]);
	}//end lookupSourceUuidByInt()

	/**
	 * The source rows with this legacy integer id, or null when the read fails.
	 *
	 * @param int $legacyId The legacy integer primary key.
	 *
	 * @return mixed The rows (any shape the read answers).
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	private function sourceRows(int $legacyId): mixed {
		try {
			// OpenRegister's ObjectService::findAll takes a single config array
			// (register/schema/id live inside `filters`), NOT positional
			// register/schema arguments. See OR ObjectService::findAll().
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'source', 'id' => $legacyId]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('SyncRefResolver: lookup of source legacyId=%d raised %s — treating as unresolved', $legacyId, $e::class)
			);
			return null;
		}

		// FindAll() returns either a `['results' => [...]]` envelope or a
		// bare list depending on the render config; normalise to a list.
		return ($result['results'] ?? $result);
	}//end sourceRows()

	/**
	 * The uuid of an entity or of an array row; null when it has none or an empty one.
	 *
	 * @param mixed $row An entity or a row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	private function extractUuid(mixed $row): ?string {
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			return self::nonEmpty(value: (string)$row->getUuid());
		}

		if (is_array($row) === true && is_string($row['uuid'] ?? null) === true) {
			return self::nonEmpty(value: $row['uuid']);
		}

		return null;
	}//end extractUuid()

	/**
	 * A string, or null when it is empty.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	private static function nonEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nonEmpty()
}//end class
