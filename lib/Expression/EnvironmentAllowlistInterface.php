<?php

/**
 * Contract of the EnvironmentAllowlist.
 *
 * @category Contract
 * @package  OCA\Integriq\Expression
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

namespace OCA\Integriq\Expression;

/**
 * Public operations of {@see EnvironmentAllowlist} that other components call.
 */
interface EnvironmentAllowlistInterface {
	/**
	 * Why a key may not be added, or null when it may.
	 *
	 * @param string $key The key.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function refusalFor(string $key): ?string;

	/**
	 * The listed keys, each with who added it and when.
	 *
	 * @return array<string, array{addedBy: string, addedAt: string}> The list.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function entries(): array;

	/**
	 * Whether a key is on the list.
	 *
	 * 🔴 EXACT MATCH, CASE SENSITIVE. An environment variable name is case
	 * sensitive on every system this runs on, so a case-insensitive check would
	 * admit `db_password` for an entry reading `DB_PASSWORD` — a different
	 * variable that an administrator never looked at.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when it is listed.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function allows(string $key): bool;

	/**
	 * Add a key, recording who added it.
	 *
	 * @param string $key       The key.
	 * @param string $principal Who added it.
	 *
	 * @return array{added: bool, reason: string} What happened.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function add(string $key, string $principal): array;

	/**
	 * Remove a key.
	 *
	 * @param string $key The key.
	 *
	 * @return bool True when it was on the list and is not any more.
	 *
	 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
	 */
	public function remove(string $key): bool;
}
