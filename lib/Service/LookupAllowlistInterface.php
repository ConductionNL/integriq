<?php

/**
 * Contract of the LookupAllowlist.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see LookupAllowlist} that other components call.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
 */
interface LookupAllowlistInterface {
	/**
	 * The allowed schemas, each with who added it and when.
	 *
	 * @return array<string, array{addedBy: string, addedAt: string}>
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function entries(): array;


	/**
	 * Add a schema to the list.
	 *
	 * @param string $schema    The schema.
	 * @param string $principal Who added it.
	 *
	 * @return array{added: bool, reason: string}
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function add(string $schema, string $principal): array;

	/**
	 * Remove a schema from the list.
	 *
	 * @param string $schema The schema.
	 *
	 * @return bool Whether it was on the list.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-the-lookup-allowlist-is-administered-and-every-change-is-recorded-req-mfl-005
	 */
	public function remove(string $schema): bool;
}//end interface
