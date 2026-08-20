<?php

/**
 * Unit tests for AuthorizationService::authorizeNcSession() (ocon#1068).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The `nc-session` authentication type authorises the CURRENT Nextcloud
 * session user. Because the endpoint dispatch route is
 * `#[PublicPage] #[NoCSRFRequired]`, these tests pin the three properties that
 * make it safe rather than merely functional: no session is a refusal, no CSRF
 * token is a refusal, and the users/groups allow-list is honoured.
 */
class AuthorizationServiceNcSessionTest extends TestCase {

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var AuthorizationService
	 */
	private AuthorizationService $service;

	/**
	 * Build the service with fully mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$this->service = new AuthorizationService(
			$this->createMock(IUserManager::class),
			$this->userSession,
			ObjectServiceMockBuilder::make($this),
			$this->groupManager,
			$cacheFactory,
			$this->request
		);

	}//end setUp()

	/**
	 * Build a user double.
	 *
	 * @param string $uid The user's UID.
	 * @param array $groups The GIDs the user belongs to.
	 *
	 * @return IUser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildUser(string $uid, array $groups = []) {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($uid . '@example.test');

		$groupObjects = [];
		foreach ($groups as $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$groupObjects[] = $group;
		}

		$this->groupManager->method('getUserGroups')->willReturn($groupObjects);

		return $user;
	}//end buildUser()

	/**
	 * An unauthenticated request is refused — this is the fail-closed default
	 * for a route NC's own middleware never gates.
	 *
	 * @return void
	 */
	public function testUnauthenticatedRequestIsRefused(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(AuthenticationException::class);

		$this->service->authorizeNcSession([], []);

	}//end testUnauthenticatedRequestIsRefused()

	/**
	 * A logged-in session whose `isLoggedIn()` is true but which resolves no
	 * user is still a refusal.
	 *
	 * @return void
	 */
	public function testLoggedInWithoutResolvableUserIsRefused(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(AuthenticationException::class);

		$this->service->authorizeNcSession([], []);

	}//end testLoggedInWithoutResolvableUserIsRefused()

	/**
	 * THE key security assertion: a valid session with NO valid CSRF token is
	 * refused. Without this the route's `#[NoCSRFRequired]` would leave every
	 * `nc-session` endpoint forgeable from any origin the user visits.
	 *
	 * @return void
	 */
	public function testValidSessionWithoutCsrfTokenIsRefused(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('alice'));
		$this->request->method('passesCSRFCheck')->willReturn(false);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('Not authorized');

		$this->service->authorizeNcSession([], []);

	}//end testValidSessionWithoutCsrfTokenIsRefused()

	/**
	 * A valid session plus a valid CSRF token, with no allow-list configured,
	 * authorises any authenticated user — the same "empty list means any" rule
	 * the `basic` and `oauth` types use.
	 *
	 * @return void
	 */
	public function testValidSessionWithCsrfTokenAndNoAllowListPasses(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('alice'));
		$this->request->method('passesCSRFCheck')->willReturn(true);

		$this->service->authorizeNcSession([], []);

		$this->addToAssertionCount(1);

	}//end testValidSessionWithCsrfTokenAndNoAllowListPasses()

	/**
	 * The group allow-list is honoured: a member of an allowed group passes.
	 *
	 * @return void
	 */
	public function testGroupAllowListAdmitsAMember(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('alice', ['admin', 'users']));
		$this->request->method('passesCSRFCheck')->willReturn(true);

		$this->service->authorizeNcSession([], ['admin']);

		$this->addToAssertionCount(1);

	}//end testGroupAllowListAdmitsAMember()

	/**
	 * The group allow-list is honoured in the other direction: a
	 * CSRF-valid, fully authenticated user outside every allowed group is
	 * refused rather than admitted.
	 *
	 * @return void
	 */
	public function testGroupAllowListRefusesANonMember(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('mallory', ['users']));
		$this->request->method('passesCSRFCheck')->willReturn(true);

		$this->expectException(AuthenticationException::class);

		$this->service->authorizeNcSession([], ['admin']);

	}//end testGroupAllowListRefusesANonMember()

	/**
	 * The user allow-list is honoured on its own, with no group membership.
	 *
	 * @return void
	 */
	public function testUserAllowListAdmitsANamedUser(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('alice'));
		$this->request->method('passesCSRFCheck')->willReturn(true);

		$this->service->authorizeNcSession(['alice'], []);

		$this->addToAssertionCount(1);

	}//end testUserAllowListAdmitsANamedUser()

	/**
	 * An allow-list never rescues a missing CSRF token: the token check runs
	 * first and refuses before the ACL is consulted.
	 *
	 * @return void
	 */
	public function testAllowListedUserStillNeedsACsrfToken(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->buildUser('alice', ['admin']));
		$this->request->method('passesCSRFCheck')->willReturn(false);

		$this->expectException(AuthenticationException::class);

		$this->service->authorizeNcSession(['alice'], ['admin']);

	}//end testAllowListedUserStillNeedsACsrfToken()
}//end class
