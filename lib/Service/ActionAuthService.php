<?php

/**
 * Action Authorization Service
 *
 * Implements the ADR-023 action-level authorization pattern: each controller
 * method declares an action name (e.g. "source.test") and delegates the
 * authorization decision to this service, which resolves the action against
 * an admin-configured matrix stored in IAppConfig.
 *
 * This service is the canonical place to enforce action RBAC. Per ADR-023:
 *   - Data RBAC (who can read/write which objects) is OpenRegister's job.
 *   - Action RBAC (who can invoke which controller method) is this service.
 *   - Admin-only operations (editing the matrix itself, app config, rebase)
 *     bypass this service and use #[AuthorizedAdminSetting(Application::APP_ID)]
 *     at the route layer.
 *
 * Controllers call `requireAction` which throws OCSForbiddenException when
 * the caller's groups don't intersect the matrix entry for the action.
 *
 * Admin users always pass. The matrix defaults to ["admin"] for every
 * declared action — first-install safe posture. The admin broadens via
 * the settings UI.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\AppInfo\Application;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Action-level authorization service.
 *
 * Enforces ADR-023 action RBAC: controllers call requireAction with a
 * dot-separated action name; this service checks the admin-configured
 * action-to-group mapping stored in IAppConfig.
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
 */
class ActionAuthService {
	private const CONFIG_KEY = 'actions';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig IAppConfig for reading/writing the matrix
	 * @param IGroupManager $groupManager Group manager for resolving user groups
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

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
	public function requireAction(IUser $user, string $action): void {
		// Admin always passes — break-glass for ops / debugging.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		$allowedGroups = $this->getAllowedGroups(action: $action);

		// An "admin"-only entry means non-admins never pass (admin already
		// returned above). Empty entry means nobody is allowed.
		if (count($allowedGroups) === 0 || $allowedGroups === ['admin']) {
			throw new OCSForbiddenException(
				"Action '{$action}' requires admin rights"
			);
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		// Exclude "admin" from matrix entry before intersection — admin was
		// already checked above; its presence in the entry is a display hint,
		// not a group membership check.
		$nonAdminAllowed = array_values(array_diff($allowedGroups, ['admin']));

		if (count(array_intersect($userGroups, $nonAdminAllowed)) === 0) {
			throw new OCSForbiddenException(
				"Action '{$action}' not allowed for your groups"
			);
		}

	}//end requireAction()

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
	public function can(IUser $user, string $action): bool {
		try {
			$this->requireAction(user: $user, action: $action);
			return true;
		} catch (OCSForbiddenException $e) {
			return false;
		}

	}//end can()

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
	public function getAllowedGroups(string $action): array {
		$matrix = $this->getMatrix();
		return $matrix[$action] ?? ['admin'];
	}//end getAllowedGroups()

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
	public function getMatrix(): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '{}');

		try {
			$decoded = json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return [];
		}

		if (is_array($decoded) === false) {
			return [];
		}

		// Normalize: discard any non-array values + any non-string group entries.
		$matrix = [];
		foreach ($decoded as $action => $groups) {
			if (is_string($action) === false || is_array($groups) === false) {
				continue;
			}

			$clean = [];
			foreach ($groups as $g) {
				if (is_string($g) === true && $g !== '') {
					$clean[] = $g;
				}
			}

			$matrix[$action] = array_values(array_unique($clean));
		}

		return $matrix;
	}//end getMatrix()

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
	public function setMatrix(array $matrix): void {
		// Normalize on write — same shape as getMatrix returns.
		$normalized = [];
		foreach ($matrix as $action => $groups) {
			$clean = [];
			foreach ($groups as $g) {
				if (is_string($g) === true && $g !== '') {
					$clean[] = $g;
				}
			}

			$normalized[$action] = array_values(array_unique($clean));
		}

		$json = json_encode($normalized, flags: JSON_THROW_ON_ERROR);
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $json);

	}//end setMatrix()

	/**
	 * List all action keys currently in the matrix.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/action-authorization/spec.md#requirement-the-action-registry-is-declared-seeded-admin-only-and-stored-in-iappconfig
	 */
	public function getActions(): array {
		return array_keys($this->getMatrix());
	}//end getActions()
}//end class
