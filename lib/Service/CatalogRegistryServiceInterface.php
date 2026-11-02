<?php

/**
 * Contract of the CatalogRegistryService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see CatalogRegistryService} that other components call.
 */
interface CatalogRegistryServiceInterface {
	/**
	 * Assemble every catalog entry from the three sources (REQ-003).
	 *
	 * Each entry is a plain array matching the `catalog_item` schema's
	 * property names (minus `status`, which callers compute separately via
	 * {@see resolveStatus()} since it must be re-checkable live).
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-materialization-is-idempotent
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-counts-only-real-connectors-once-each-req-ccx-004
	 */
	public function collect(): array;

	/**
	 * Re-read a register.d seed fragment's full raw `source` object payload
	 * for a given slug — used by `CatalogController::instantiate()` to
	 * create a brand-new Source when a mock-seeded template's Source object
	 * doesn't exist yet (e.g. it was deleted, or a future seed ships with
	 * `isEnabled: false` by default).
	 *
	 * Unlike {@see collectFromSeedFragments()}, this returns the FULL
	 * object body (all configuration fields), not just the catalog display
	 * fields, since it feeds a real `saveObject()` call.
	 *
	 * @param string $slug The source template's slug (e.g. "brp-haalcentraal").
	 *
	 * @return array<string,mixed>|null The raw source object payload (minus `@self`), or null when not found.
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
	 * @spec openspec/specs/connector-catalog/spec.md#requirement-the-store-lists-templates-it-does-not-install-req-ccx-001
	 */
	public function findSeedSourcePayload(string $slug): ?array;

	/**
	 * Live-resolve an entry's status, given its mechanism/flagKey/
	 * sourceTemplateSlug (REQ-002's per-item live re-check, and the
	 * materialisation-time cache write).
	 *
	 * @param array<string,mixed> $entry A collect()-shaped entry (or the
	 *                                   equivalent fields read back off a
	 *                                   materialised `catalog_item` object).
	 *
	 * @return string One of 'available' | 'dormant'.
	 *
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-flag-gated-dormant-item
	 * @spec openspec/specs/connector-catalog/spec.md#scenario-status-badge-reflects-a-mock-seeded-available-item
	 */
	public function resolveStatus(array $entry): string;
}
