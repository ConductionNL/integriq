<?php

/**
 * Contract of the UserService.
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

use OCP\IUser;

/**
 * Public operations of {@see UserService} that other components call.
 */
interface UserServiceInterface {
	/**
	 * Get current authenticated user
	 *
	 * This method retrieves the currently authenticated user from the session
	 * and returns null if no user is authenticated.
	 *
	 * @return IUser|null The current user or null if not authenticated
	 *
	 * @psalm-return   IUser|null
	 * @phpstan-return IUser|null
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function getCurrentUser(): ?IUser;

	/**
	 * Build comprehensive user data array
	 *
	 * This method constructs a complete user data array including all standard
	 * NextCloud user properties, custom profile fields, quota information,
	 * group memberships, and additional profile data from AccountManager.
	 *
	 * @param IUser $user The user object to build data for
	 *
	 * @return array The comprehensive user data array
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function buildUserDataArray(IUser $user): array;

	/**
	 * Update user properties based on provided data
	 *
	 * This method safely updates user properties that are allowed to be modified
	 * through the API, including standard user properties and custom profile fields.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return array Result of the update operation including organization changes
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $data
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $data
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function updateUserProperties(IUser $user, array $data): array;

	/**
	 * Get custom name fields for a user (accessible to other apps)
	 *
	 * This method retrieves the custom name fields (firstName, lastName, middleName)
	 * for a given user. These fields are stored in the 'core' namespace making them
	 * accessible to other NextCloud apps.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Array containing name fields
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array{firstName: string|null, lastName: string|null, middleName: string|null}
	 * @phpstan-param  IUser $user
	 * @phpstan-return array{firstName: string|null, lastName: string|null, middleName: string|null}
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function getCustomNameFields(IUser $user): array;

	/**
	 * Set custom name fields for a user (accessible to other apps)
	 *
	 * This method stores the custom name fields in the 'core' namespace making them
	 * accessible to other NextCloud apps while maintaining consistency.
	 *
	 * @param IUser $user The user object
	 * @param array $nameFields Array containing name field values
	 *
	 * @return void
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $nameFields
	 * @psalm-return   void
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $nameFields
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function setCustomNameFields(IUser $user, array $nameFields): void;
}
