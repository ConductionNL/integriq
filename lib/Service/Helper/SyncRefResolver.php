<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Service\Helper;

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
 * @spec openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact
 */
final class SyncRefResolver
{

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {

    }

    /**
     * Resolve a sourceId/targetId value to its uuid form + variant tag.
     *
     * @param string $value The raw sourceId/targetId value.
     *
     * @return array{value: string, variant: 'integer-pk'|'register-schema'|'uuid'|'unrecognised'}
     */
    public function resolve(string $value): array
    {
        if ($value === '') {
            return ['value' => $value, 'variant' => 'unrecognised'];
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            $uuid = $this->lookupSourceUuidByInt((int) $value);
            return ['value' => ($uuid ?? $value), 'variant' => 'integer-pk'];
        }

        if (preg_match('/^[\w-]+\/[\w-]+$/', $value) === 1) {
            return ['value' => $value, 'variant' => 'register-schema'];
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return ['value' => $value, 'variant' => 'uuid'];
        }

        $this->logger->warning(
            sprintf(
                'SyncRefResolver: value "%s" matches no known format — preserved as-is, marked unrecognised',
                $value
            )
        );

        return ['value' => $value, 'variant' => 'unrecognised'];
    }

    /**
     * Look up the openconnector source uuid by its legacy integer primary key.
     *
     * Returns null when no matching source is found; the caller treats this
     * as "unresolved integer-PK" and preserves the raw value.
     */
    private function lookupSourceUuidByInt(int $legacyId): ?string
    {
        try {
            $objects = $this->objectService->findAll(
                'openconnector',
                'source',
                null,
                null,
                ['id' => $legacyId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'SyncRefResolver: lookup of source legacyId=%d raised %s — treating as unresolved',
                    $legacyId,
                    $e::class
                )
            );
            return null;
        }

        if (is_array($objects) === false || count($objects) === 0) {
            return null;
        }

        $first = $objects[0];
        if (is_object($first) === true && method_exists($first, 'getUuid') === true) {
            $uuid = $first->getUuid();
            return ($uuid === '' ? null : $uuid);
        }

        if (is_array($first) === true && isset($first['uuid']) === true && is_string($first['uuid']) === true) {
            return ($first['uuid'] === '' ? null : $first['uuid']);
        }

        return null;
    }

}
