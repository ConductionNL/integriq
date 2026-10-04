<?php

/**
 * A user session that holds an active user, as Nextcloud's own does.
 *
 * `setVolatileActiveUser()` sets the active user and `getUser()` returns it.
 * A test that runs code through OpenRegister's `ObjectService::runAs()` with
 * this session sees the identity change and the restore for real, instead of
 * a mock that returns whatever it was told to return.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCP\IUser;
use OCP\IUserSession;

/**
 * In-memory IUserSession with a real active-user slot.
 */
class ActiveUserSession implements IUserSession {

	/**
	 * Every user that became active, in order (null for nobody).
	 *
	 * @var list<string|null>
	 */
	public array $history = [];

	/**
	 * Constructor.
	 *
	 * @param IUser|null $user The user active at the start.
	 */
	public function __construct(
		private ?IUser $user = null,
	) {
	}//end __construct()

	/**
	 * Not used by the code under test.
	 *
	 * @param string $uid      Unused.
	 * @param string $password Unused.
	 *
	 * @return bool Always false.
	 */
	public function login($uid, $password) {
		return false;
	}//end login()

	/**
	 * Clear the active user.
	 *
	 * @return void
	 */
	public function logout() {
		$this->user = null;
	}//end logout()

	/**
	 * Set the active user.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return void
	 */
	public function setUser($user) {
		$this->setVolatileActiveUser($user);
	}//end setUser()

	/**
	 * Set the active user without touching a PHP session.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return void
	 */
	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
		$this->history[] = $user?->getUID();
	}//end setVolatileActiveUser()

	/**
	 * The active user.
	 *
	 * @return IUser|null The user, or null for nobody.
	 */
	public function getUser() {
		return $this->user;
	}//end getUser()

	/**
	 * Whether a user is active.
	 *
	 * @return bool
	 */
	public function isLoggedIn() {
		return $this->user !== null;
	}//end isLoggedIn()

	/**
	 * Not used by the code under test.
	 *
	 * @return string|null Always null.
	 */
	public function getImpersonatingUserID(): ?string {
		return null;
	}//end getImpersonatingUserID()

	/**
	 * Not used by the code under test.
	 *
	 * @param bool $useCurrentUser Unused.
	 *
	 * @return void
	 */
	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}//end setImpersonatingUserID()
}//end class
