<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ActingUser;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Acting as another user for one piece of work.
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 */
final class ActingUserTest extends TestCase {

	/**
	 * Every identity made active, in order (null restores none).
	 *
	 * @var list<IUser|null>
	 */
	private array $active = [];

	/**
	 * The service over a session with no user and a manager that knows one user.
	 *
	 * @param IUser $known The one user the manager resolves, as `beheerder`.
	 *
	 * @return ActingUser
	 */
	private function actingUser(IUser $known): ActingUser {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->active[] = $user;
			}
		);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			static function (string $uid) use ($known): ?IUser {
				if ($uid === 'beheerder') {
					return $known;
				}

				return null;
			}
		);

		return new ActingUser(userManager: $users, userSession: $session);
	}//end actingUser()

	/**
	 * Work runs as the user, and the prior (no) user is restored afterwards.
	 *
	 * @return void
	 */
	public function testRunAsActsAsTheUserAndRestoresThePriorOne(): void {
		$owner = $this->createMock(originalClassName: IUser::class);

		$result = $this->actingUser(known: $owner)->runAs(user: $owner, work: static fn (): string => 'done');

		$this->assertSame(expected: 'done', actual: $result);
		$this->assertSame(expected: [$owner, null], actual: $this->active);
	}//end testRunAsActsAsTheUserAndRestoresThePriorOne()

	/**
	 * The prior user is restored even when the work throws.
	 *
	 * @return void
	 */
	public function testRunAsRestoresThePriorUserWhenTheWorkThrows(): void {
		$owner = $this->createMock(originalClassName: IUser::class);

		try {
			$this->actingUser(known: $owner)->runAs(
				user: $owner,
				work: static function (): never {
					throw new RuntimeException(message: 'refused');
				}
			);
			$this->fail(message: 'the exception must reach the caller');
		} catch (RuntimeException $exception) {
			$this->assertSame(expected: 'refused', actual: $exception->getMessage());
		}

		$this->assertSame(expected: [$owner, null], actual: $this->active);
	}//end testRunAsRestoresThePriorUserWhenTheWorkThrows()

	/**
	 * Find resolves an existing user and answers null for an empty or unknown id.
	 *
	 * @return void
	 */
	public function testFindResolvesOnlyAnExistingUser(): void {
		$owner = $this->createMock(originalClassName: IUser::class);
		$acting = $this->actingUser(known: $owner);

		$this->assertSame(expected: $owner, actual: $acting->find(userId: ' beheerder '));
		$this->assertNull(actual: $acting->find(userId: ''));
		$this->assertNull(actual: $acting->find(userId: 'vertrokken'));
		$this->assertNull(actual: $acting->current());
	}//end testFindResolvesOnlyAnExistingUser()
}//end class
