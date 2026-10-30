<?php

/**
 * Contract of the ActionAuthService.
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

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;

/**
 * Public operations of {@see ActionAuthService} that other components call.
 */
interface ActionAuthServiceInterface {
	/**
	 * Require that the user may perform the named action.
	 *
	 * Admin users always pass (break-glass). Non-admins pass only when any
	 * of their groups intersects the matrix entry for the action.
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $action Dot-separated action name (e.g. "source.test").
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user's groups don't match the action's allowed groups.
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
	 */
	public function requireAction(IUser $user, string $action): void;

	/**
	 * Check whether the user may perform the named action (non-throwing).
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $action Dot-separated action name.
	 *
	 * @return bool True if the user may perform the action.
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
	 */
	public function can(IUser $user, string $action): bool;

	/**
	 * Get the list of groups allowed to perform the action.
	 *
	 * Returns the matrix entry for the action, or ["admin"] as the safe
	 * default when the action is not in the matrix.
	 *
	 * @param string $action Dot-separated action name.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
	 */
	public function getAllowedGroups(string $action): array;

	/**
	 * Get the full action-to-groups matrix.
	 *
	 * Reads the JSON-encoded matrix from IAppConfig. Missing or malformed
	 * config returns an empty array (default-deny — admin-only for every
	 * action since getAllowedGroups falls back to ["admin"]).
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
	 */
	public function getMatrix(): array;

	/**
	 * Set the full action-to-groups matrix.
	 *
	 * Caller MUST enforce admin-only before invoking (this method does not
	 * gate writes — it's called from an admin-only settings endpoint).
	 *
	 * @param array<string, array<int, string>> $matrix The new matrix.
	 *
	 * @return void
	 *
	 * @throws \JsonException When the matrix cannot be encoded.
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
	 */
	public function setMatrix(array $matrix): void;

	/**
	 * List all action keys currently in the matrix.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
	 */
	public function getActions(): array;
}
