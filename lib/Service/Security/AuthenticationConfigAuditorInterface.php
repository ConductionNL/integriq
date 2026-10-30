<?php

/**
 * Contract of the AuthenticationConfigAuditor.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
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

namespace OCA\Integriq\Service\Security;

/**
 * Public operations of {@see AuthenticationConfigAuditor} that other components call.
 */
interface AuthenticationConfigAuditorInterface {
	/**
	 * The vestigial field this auditor reports on.
	 *
	 * @var string
	 */
	public const FIELD = 'authenticationConfig';

	/**
	 * Audit every source's `authenticationConfig`.
	 *
	 * Per-source isolation: an unreadable source is recorded as `unreadable` and
	 * never aborts the batch, and never counts as clear.
	 *
	 * @param integer $limit Maximum number of sources to inspect.
	 *
	 * @return array<string, mixed> The audit report (key names only — never a value).
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public function auditAll(int $limit = 1000): array;

	/**
	 * Audit ONE source.
	 *
	 * The read goes through {@see InlineSecretMigrationPlanner::readRawSource()},
	 * which uses `_render: false`. THAT ARGUMENT IS LOAD-BEARING: `authenticationConfig`
	 * is `writeOnly: true`, and OpenRegister strips every writeOnly property on ANY
	 * rendered read — the strip is SCHEMA-gated, not `_rbac`-gated (openregister#389/#429).
	 * A rendered read would therefore report EVERY source as "clear" and this audit
	 * would greenlight dropping a schema property that still holds live credentials.
	 *
	 * @param string $uuid The source uuid.
	 * @param string $name The source name.
	 *
	 * @return array<string, mixed> The per-source record (key names only — never a value).
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public function auditSource(string $uuid, string $name): array;
}
