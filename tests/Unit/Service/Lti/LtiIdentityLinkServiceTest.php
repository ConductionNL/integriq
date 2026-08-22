<?php

/**
 * Unit tests for LtiIdentityLinkService.
 *
 * Covers REQ-LTI-012: the conservative `manualLinkOnly` default never
 * auto-creates a user; `autoProvisionAsRole` is an explicit per-platform
 * opt-in bounded to a named group; two platforms sharing the same `sub`
 * value never collide (keyed on `(ltiPlatformId, subject)`); a misconfigured
 * `autoProvisionAsRole` (no `defaultProvisionGroup`) fails closed to
 * "unlinked" rather than auto-provisioning unbounded.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-tool-provider-role/specs/lti-platform/spec.md#req-lti-012
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiIdentityLinkService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for `(ltiPlatformId, subject)` identity resolution and opt-in provisioning.
 */
class LtiIdentityLinkServiceTest extends TestCase {

	/**
	 * In-memory `lti_platform` rows keyed by uuid.
	 *
	 * @var array<string, array>
	 */
	private array $platforms = [];

	/**
	 * In-memory `lti_identity_link` rows keyed by a synthetic "uuid".
	 *
	 * @var array<string, array>
	 */
	private array $links = [];

	/**
	 * In-memory Nextcloud users keyed by uid (uid => true).
	 *
	 * @var array<string, bool>
	 */
	private array $users = [];

	/**
	 * In-memory Nextcloud groups keyed by group id, each value a list of member uids.
	 *
	 * @var array<string, string[]>
	 */
	private array $groups = [];

	/**
	 * Build an LtiIdentityLinkService backed by in-memory OR/user/group stores.
	 *
	 * @return LtiIdentityLinkService
	 */
	private function makeService(): LtiIdentityLinkService {
		$orObjectService = $this->createMock(ObjectService::class);

		$orObjectService->method('find')->willReturnCallback(
			function ($id, $_extend = [], $files = false, $register = null, $schema = null, $_rbac = true, $_multitenancy = true) {
				if (isset($this->platforms[$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->platforms[$id]);
				return $entity;
			}
		);

		$orObjectService->method('findAll')->willReturnCallback(
			function ($config = [], $_rbac = true, $_multitenancy = true) {
				$filters = ($config['filters'] ?? []);
				$results = [];

				foreach ($this->links as $uuid => $data) {
					if (($data['ltiPlatformId'] ?? null) !== ($filters['ltiPlatformId'] ?? null)) {
						continue;
					}

					if (($data['subject'] ?? null) !== ($filters['subject'] ?? null)) {
						continue;
					}

					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($data);
					$results[] = $entity;
				}

				return ['results' => $results];
			}
		);

		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null, $_rbac = true, $_multitenancy = true) {
				// Application-level composite-uniqueness guard (mirrors OR's
				// ValidateObject `configuration.unique` enforcement for
				// (ltiPlatformId, subject) on lti_identity_link — see
				// openregister/lib/Service/Object/ValidateObject.php).
				foreach ($this->links as $existing) {
					if (($existing['ltiPlatformId'] ?? null) === ($object['ltiPlatformId'] ?? null)
						&& ($existing['subject'] ?? null) === ($object['subject'] ?? null)
					) {
						throw new \RuntimeException('Fields are not unique: ltiPlatformId, subject');
					}
				}

				$newUuid = ($uuid ?? ('link-' . count($this->links)));
				$this->links[$newUuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($newUuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function ($uid) {
				if (isset($this->users[$uid]) === false) {
					return null;
				}

				return $this->makeUser($uid);
			}
		);
		$userManager->method('createUser')->willReturnCallback(
			function ($uid, $password) {
				$this->users[$uid] = true;
				return $this->makeUser($uid);
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function ($gid) {
				if (isset($this->groups[$gid]) === false) {
					return null;
				}

				return $this->makeGroup($gid);
			}
		);
		$groupManager->method('createGroup')->willReturnCallback(
			function ($gid) {
				$this->groups[$gid] = [];
				return $this->makeGroup($gid);
			}
		);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('x');

		return new LtiIdentityLinkService($orObjectService, $userManager, $groupManager, $secureRandom, new NullLogger());
	}//end makeService()

	/**
	 * Build an IUser double backed by the in-memory `$this->users`/`$this->groups` state.
	 *
	 * @param string $uid The Nextcloud userId.
	 *
	 * @return IUser
	 */
	private function makeUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end makeUser()

	/**
	 * Build an IGroup double backed by the in-memory `$this->groups` state.
	 *
	 * @param string $gid The Nextcloud group id.
	 *
	 * @return IGroup
	 */
	private function makeGroup(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturnCallback(
			fn (IUser $user) => in_array($user->getUID(), ($this->groups[$gid] ?? []), true)
		);
		$group->method('addUser')->willReturnCallback(
			function (IUser $user) use ($gid) {
				$this->groups[$gid][] = $user->getUID();
			}
		);

		return $group;
	}//end makeGroup()

	/**
	 * Seed an `lti_platform` row.
	 *
	 * @param string $uuid The platform uuid.
	 * @param array $extra Extra fields (`identityPolicy`, `defaultProvisionGroup`, ...).
	 *
	 * @return void
	 */
	private function seedPlatform(string $uuid, array $extra = []): void {
		$this->platforms[$uuid] = $extra;

	}//end seedPlatform()

	// =========================================================================
	// manualLinkOnly (default)
	// =========================================================================

	/**
	 * An unlinked subject under the (explicit) `manualLinkOnly` policy is
	 * reported unlinked; no Nextcloud user is created.
	 *
	 * @return void
	 */
	public function testUnlinkedSubjectUnderManualLinkOnlyReportsUnlinkedNoUserCreated(): void {
		$this->seedPlatform('plat-1', ['identityPolicy' => 'manualLinkOnly']);
		$service = $this->makeService();

		$result = $service->resolveIdentity('plat-1', 'user-42');

		$this->assertSame(['status' => 'unlinked', 'userId' => null], $result);
		$this->assertEmpty($this->users);
		$this->assertEmpty($this->links);

	}//end testUnlinkedSubjectUnderManualLinkOnlyReportsUnlinkedNoUserCreated()

	/**
	 * The DEFAULT policy (no `identityPolicy` field at all — a platform row
	 * that predates this change, or one that simply never set it) behaves
	 * identically to an explicit `manualLinkOnly` — conservative default,
	 * REQ-LTI-012.
	 *
	 * @return void
	 */
	public function testMissingIdentityPolicyDefaultsToManualLinkOnly(): void {
		$this->seedPlatform('plat-default', []);
		$service = $this->makeService();

		$result = $service->resolveIdentity('plat-default', 'user-1');

		$this->assertSame(['status' => 'unlinked', 'userId' => null], $result);
		$this->assertEmpty($this->users);

	}//end testMissingIdentityPolicyDefaultsToManualLinkOnly()

	/**
	 * A manually-created `lti_identity_link` row (an admin linked an
	 * existing account) resolves as linked without any provisioning.
	 *
	 * @return void
	 */
	public function testManuallyLinkedSubjectResolvesAsLinked(): void {
		$this->seedPlatform('plat-2', ['identityPolicy' => 'manualLinkOnly']);
		$this->links['link-manual'] = [
			'ltiPlatformId' => 'plat-2',
			'subject' => 'user-7',
			'userId' => 'existing-nc-user',
			'provisioningMethod' => 'manual',
			'linkedByUserId' => 'admin-1',
		];

		$service = $this->makeService();
		$result = $service->resolveIdentity('plat-2', 'user-7');

		$this->assertSame(['status' => 'linked', 'userId' => 'existing-nc-user'], $result);
		$this->assertEmpty($this->users, 'A manually-linked resolution MUST NOT provision a new user');

	}//end testManuallyLinkedSubjectResolvesAsLinked()

	// =========================================================================
	// autoProvisionAsRole (explicit opt-in)
	// =========================================================================

	/**
	 * A first-seen subject under `autoProvisionAsRole` provisions a new
	 * Nextcloud user into the configured `defaultProvisionGroup` and records
	 * `provisioningMethod: auto`.
	 *
	 * @return void
	 */
	public function testFirstSeenSubjectUnderAutoProvisionProvisionsUserIntoConfiguredGroup(): void {
		$this->seedPlatform(
			'plat-3',
			['identityPolicy' => 'autoProvisionAsRole', 'defaultProvisionGroup' => 'scholiq-lti-learners']
		);
		$service = $this->makeService();

		$result = $service->resolveIdentity('plat-3', 'user-99');

		$this->assertSame('linked', $result['status']);
		$this->assertNotEmpty($result['userId']);
		$this->assertContains($result['userId'], ($this->groups['scholiq-lti-learners'] ?? []));

		$link = array_values($this->links)[0];
		$this->assertSame('auto', $link['provisioningMethod']);
		$this->assertSame('plat-3', $link['ltiPlatformId']);
		$this->assertSame('user-99', $link['subject']);
		$this->assertNull($link['linkedByUserId']);

	}//end testFirstSeenSubjectUnderAutoProvisionProvisionsUserIntoConfiguredGroup()

	/**
	 * A repeat launch from the same `(ltiPlatformId, subject)` reuses the
	 * previously-provisioned user rather than creating a second one.
	 *
	 * @return void
	 */
	public function testRepeatLaunchReusesPreviouslyProvisionedUser(): void {
		$this->seedPlatform(
			'plat-4',
			['identityPolicy' => 'autoProvisionAsRole', 'defaultProvisionGroup' => 'scholiq-lti-learners']
		);
		$service = $this->makeService();

		$first = $service->resolveIdentity('plat-4', 'user-55');
		$second = $service->resolveIdentity('plat-4', 'user-55');

		$this->assertSame($first['userId'], $second['userId']);
		$this->assertCount(1, $this->users);
		$this->assertCount(1, $this->links);

	}//end testRepeatLaunchReusesPreviouslyProvisionedUser()

	/**
	 * `autoProvisionAsRole` set without a `defaultProvisionGroup` (a
	 * misconfiguration the schema-level `if/then` should normally prevent,
	 * but defensively re-checked here) fails closed to "unlinked" rather
	 * than auto-provisioning into no group / an unbounded scope.
	 *
	 * @return void
	 */
	public function testAutoProvisionWithoutGroupFailsClosedToUnlinked(): void {
		$this->seedPlatform('plat-5', ['identityPolicy' => 'autoProvisionAsRole']);
		$service = $this->makeService();

		$result = $service->resolveIdentity('plat-5', 'user-1');

		$this->assertSame(['status' => 'unlinked', 'userId' => null], $result);
		$this->assertEmpty($this->users);

	}//end testAutoProvisionWithoutGroupFailsClosedToUnlinked()

	// =========================================================================
	// (ltiPlatformId, subject) scoping — no cross-platform collision
	// =========================================================================

	/**
	 * The same `sub` value presented by two different platforms resolves to
	 * two INDEPENDENT `lti_identity_link` rows, never the same one —
	 * REQ-LTI-012 scenario 3.
	 *
	 * @return void
	 */
	public function testSameSubjectFromTwoDifferentPlatformsNeverCollides(): void {
		$this->seedPlatform(
			'plat-a',
			['identityPolicy' => 'autoProvisionAsRole', 'defaultProvisionGroup' => 'group-a']
		);
		$this->seedPlatform(
			'plat-b',
			['identityPolicy' => 'autoProvisionAsRole', 'defaultProvisionGroup' => 'group-b']
		);
		$service = $this->makeService();

		$resultA = $service->resolveIdentity('plat-a', 'user-42');
		$resultB = $service->resolveIdentity('plat-b', 'user-42');

		$this->assertNotSame($resultA['userId'], $resultB['userId']);
		$this->assertCount(2, $this->links);
		$this->assertCount(2, $this->users);

	}//end testSameSubjectFromTwoDifferentPlatformsNeverCollides()

	// =========================================================================
	// Misc
	// =========================================================================

	/**
	 * Resolving identity against an unknown `lti_platform` uuid throws
	 * rather than silently resolving unlinked (a caller passing a bad uuid
	 * is a programming error, not a legitimate "no policy configured" case).
	 *
	 * @return void
	 */
	public function testUnknownPlatformThrowsValidationException(): void {
		$service = $this->makeService();

		$this->expectException(LtiValidationException::class);
		$service->resolveIdentity('does-not-exist', 'user-1');

	}//end testUnknownPlatformThrowsValidationException()
}//end class
