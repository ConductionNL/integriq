<?php

/**
 * Integriq ActingUser.
 *
 * The user a piece of work acts as, and running work as another user for its
 * duration.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Closure;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Who acts, and acting as someone else for one piece of work.
 *
 * The identity is set volatile, so it never reaches the PHP session, and the
 * prior identity is restored in a `finally`, so it never bleeds into the next
 * piece of work (the same pattern as FlowOwner::runAs() and JobService).
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 */
class ActingUser {

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves a user id to a user.
	 * @param IUserSession $userSession Carries the acting identity.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The user the current request acts as, or null when there is none.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function current(): ?IUser {
		return $this->userSession->getUser();
	}//end current()

	/**
	 * An existing user by id, or null when the id is empty or names no user.
	 *
	 * @param string $userId The user id.
	 *
	 * @return IUser|null
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function find(string $userId): ?IUser {
		$userId = trim($userId);
		if ($userId === '') {
			return null;
		}

		return $this->userManager->get($userId);
	}//end find()

	/**
	 * Run work as a user, and restore the prior identity afterwards.
	 *
	 * @param IUser   $user The identity to act as.
	 * @param Closure $work The work.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function runAs(IUser $user, Closure $work): mixed {
		$prior = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($user);
		try {
			return $work();
		} finally {
			$this->userSession->setVolatileActiveUser($prior);
		}
	}//end runAs()
}//end class
