<?php

/**
 * Unit tests for PortalController: the developer group gate, public products
 * only, applications the developer owns, and request access that refuses
 * another developer's application with 404 and writes nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\PortalController;
use OCA\Integriq\Service\DeveloperPortalAccess;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductSubscriptionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the developer portal routes.
 */
#[CoversClass(PortalController::class)]
#[UsesClass(DeveloperPortalAccess::class)]
#[UsesClass(RegisterSave::class)]
class PortalControllerTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService&MockObject $objects;

	/**
	 * The subscribe path.
	 *
	 * @var ProductSubscriptionService&MockObject
	 */
	private ProductSubscriptionService&MockObject $subscriptions;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * An object entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * The controller for a signed-in account, a developer or not.
	 *
	 * @param string $uid         The account.
	 * @param bool   $isDeveloper Whether it is in the developer group.
	 *
	 * @return PortalController The controller.
	 */
	private function controller(string $uid='developer-a', bool $isDeveloper=true): PortalController {
		$this->objects = $this->createMock(OrObjectService::class);
		$this->subscriptions = $this->createMock(ProductSubscriptionService::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
		$this->groups->method('isInGroup')->willReturnCallback(
			static fn (string $user, string $group): bool => $isDeveloper === true && $group === 'integriq-developers'
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));

		return new PortalController(
			request: $request,
			objects: $this->objects,
			subscriptions: $this->subscriptions,
			access: new DeveloperPortalAccess(appConfig: $this->config, groupManager: $this->groups),
			userSession: $session,
			appConfig: $this->config,
			groupManager: $this->groups,
			l: $l,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A user outside the developer group gets 403 and nothing is read.
	 *
	 * @return void
	 */
	public function testAUserOutsideTheDeveloperGroupIsRefused(): void {
		$controller = $this->controller(isDeveloper: false);
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->products()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->applications()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->requestAccess('c-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->createApplication()->getStatus());
	}//end testAUserOutsideTheDeveloperGroupIsRefused()

	/**
	 * REQ-DEVP-001: a developer sees the public products with their tiers, and no private or retired one.
	 *
	 * @return void
	 */
	public function testADeveloperSeesThePublicProductsOnly(): void {
		$controller = $this->controller();
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'api_product', 'visibility' => 'public'], 'limit' => 500], false)
			->willReturn(['results' => [
				self::entity(uuid: 'p-1', data: ['name' => 'Zaken API', 'visibility' => 'public', 'status' => 'active', 'tiers' => ['basic' => [], 'premium' => ['requiresApproval' => true]]]),
				self::entity(uuid: 'p-2', data: ['name' => 'KVK Lookup API', 'visibility' => 'private']),
				self::entity(uuid: 'p-3', data: ['name' => 'Old API', 'visibility' => 'public', 'status' => 'retired']),
			]]);

		$results = $controller->products()->getData()['results'];

		$this->assertSame(['Zaken API'], array_column($results, 'name'));
		$this->assertSame([['name' => 'basic', 'requiresApproval' => false], ['name' => 'premium', 'requiresApproval' => true]], $results[0]['tiers']);
	}//end testADeveloperSeesThePublicProductsOnly()

	/**
	 * Creating an application writes a consumer the developer owns.
	 *
	 * @return void
	 */
	public function testCreatingAnApplicationWritesAConsumerTheDeveloperOwns(): void {
		$controller = $this->controller();
		$this->params = ['name' => ' My app ', 'description' => 'Reads cases'];
		$this->objects->expects($this->once())->method('saveObject')
			// RBAC off: the consumer schema is admin-only, the controller's developer gate decides.
			->with(['name' => 'My app', 'description' => 'Reads cases', 'userId' => 'developer-a', 'ownerKind' => 'developer'], $this->anything(), false)
			->willReturnCallback(static fn (array $object): ObjectEntity => self::entity(uuid: 'c-new', data: $object));
		$this->objects->method('findAll')->willReturn(['results' => []]);

		$response = $controller->createApplication();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('c-new', $response->getData()['id']);
		$this->assertSame([], $response->getData()['subscriptions']);
	}//end testCreatingAnApplicationWritesAConsumerTheDeveloperOwns()

	/**
	 * An application needs a name.
	 *
	 * @return void
	 */
	public function testAnApplicationWithoutANameIsRefused(): void {
		$controller = $this->controller();
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createApplication()->getStatus());
	}//end testAnApplicationWithoutANameIsRefused()

	/**
	 * The applications list holds only the developer's own developer-owned consumers, each with its subscriptions.
	 *
	 * @return void
	 */
	public function testTheApplicationsListHoldsOnlyTheDevelopersOwn(): void {
		$controller = $this->controller();
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'consumer') {
					TestCase::assertSame('developer-a', $filters['userId']);
					return ['results' => [
						self::entity(uuid: 'c-1', data: ['name' => 'Mine', 'userId' => 'developer-a', 'ownerKind' => 'developer']),
						self::entity(uuid: 'c-2', data: ['name' => 'Admin made', 'userId' => 'developer-a', 'ownerKind' => 'admin']),
					]];
				}

				TestCase::assertSame('c-1', $filters['consumer']);
				return ['results' => [self::entity(uuid: 's-1', data: ['product' => 'p-1', 'tier' => 'basic', 'status' => 'active', 'expiresAt' => '2026-12-31'])]];
			}
		);

		$results = $controller->applications()->getData()['results'];

		$this->assertSame(['Mine'], array_column($results, 'name'));
		$this->assertSame([['id' => 's-1', 'product' => 'p-1', 'tier' => 'basic', 'status' => 'active', 'expiresAt' => '2026-12-31']], $results[0]['subscriptions']);
	}//end testTheApplicationsListHoldsOnlyTheDevelopersOwn()

	/**
	 * REQ-DEVP-002: developer A requesting access with developer B's application gets 404 and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-developer-requests-access-for-their-own-application-req-devp-002
	 */
	public function testAnotherDevelopersApplicationIsOutOfReach(): void {
		$controller = $this->controller(uid: 'developer-a');
		$this->params = ['productId' => 'p-1', 'tier' => 'basic'];
		$this->objects->method('find')->willReturn(self::entity(uuid: 'c-b', data: ['userId' => 'developer-b', 'ownerKind' => 'developer']));
		$this->subscriptions->expects($this->never())->method('subscribe');
		$this->objects->expects($this->never())->method('saveObject');

		$response = $controller->requestAccess('c-b');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnotherDevelopersApplicationIsOutOfReach()

	/**
	 * A private product cannot be requested through the portal, and answers like a missing one.
	 *
	 * @return void
	 */
	public function testAPrivateProductCannotBeRequested(): void {
		$controller = $this->controller();
		$this->params = ['productId' => 'p-2', 'tier' => 'gold'];
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ObjectEntity => match ($id) {
				'c-1' => self::entity(uuid: 'c-1', data: ['userId' => 'developer-a', 'ownerKind' => 'developer']),
				default => self::entity(uuid: 'p-2', data: ['visibility' => 'private']),
			}
		);
		$this->subscriptions->expects($this->never())->method('subscribe');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->requestAccess('c-1')->getStatus());
	}//end testAPrivateProductCannotBeRequested()

	/**
	 * REQ-DEVP-002: a request on a gated tier goes through the shared subscribe path and waits for approval.
	 *
	 * @return void
	 */
	public function testARequestOnAGatedTierWaitsForTheApprover(): void {
		$controller = $this->controller();
		$this->params = ['productId' => 'p-1', 'tier' => 'premium'];
		$product = self::entity(uuid: 'p-1', data: ['visibility' => 'public', 'status' => 'active']);
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ObjectEntity => match ($id) {
				'c-1' => self::entity(uuid: 'c-1', data: ['userId' => 'developer-a', 'ownerKind' => 'developer']),
				default => $product,
			}
		);
		$this->subscriptions->expects($this->once())->method('subscribe')
			->with($product, 'c-1', 'premium', 'developer-a')
			->willReturn(['subscription' => self::entity(uuid: 's-1', data: ['product' => 'p-1', 'tier' => 'premium', 'status' => 'pending_approval']), 'pending' => true]);

		$response = $controller->requestAccess('c-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame('pending_approval', $response->getData()['status']);
	}//end testARequestOnAGatedTierWaitsForTheApprover()

	/**
	 * Without a tier the product's default tier is requested; an unknown one is a 400.
	 *
	 * @return void
	 */
	public function testTheDefaultTierIsUsedAndAnUnknownTierIsRefused(): void {
		$controller = $this->controller();
		$this->params = ['productId' => 'p-1'];
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ObjectEntity => match ($id) {
				'c-1' => self::entity(uuid: 'c-1', data: ['userId' => 'developer-a', 'ownerKind' => 'developer']),
				default => self::entity(uuid: 'p-1', data: ['defaultTier' => 'basic']),
			}
		);
		$this->subscriptions->expects($this->once())->method('subscribe')
			->with($this->anything(), 'c-1', 'basic', 'developer-a')
			->willThrowException(new \InvalidArgumentException('Unknown tier: basic'));

		$response = $controller->requestAccess('c-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unknown tier: basic', $response->getData()['error']);
	}//end testTheDefaultTierIsUsedAndAnUnknownTierIsRefused()

	/**
	 * The settings name the developer group with its member count; a group that does not exist is refused.
	 *
	 * @return void
	 */
	public function testTheSettingsNameTheGroupAndItsMembers(): void {
		$controller = $this->controller();
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(3);
		$this->groups->method('get')->willReturnCallback(static fn (string $id): ?IGroup => ($id === 'integriq-developers' ? $group : null));

		$this->assertSame(['group' => 'integriq-developers', 'members' => 3], $controller->getSettings()->getData());

		$this->params = ['group' => 'nobody-here'];
		$this->config->expects($this->never())->method('setValueString');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->setSettings()->getStatus());
	}//end testTheSettingsNameTheGroupAndItsMembers()

	/**
	 * Naming an existing group stores it; clearing the field deletes the key.
	 *
	 * @return void
	 */
	public function testSavingTheGroupStoresOrClearsIt(): void {
		$controller = $this->controller();
		$this->groups->method('get')->willReturn($this->createMock(IGroup::class));
		$this->config->expects($this->once())->method('setValueString')->with('integriq', DeveloperPortalAccess::CONFIG_KEY, 'partners');
		$this->config->expects($this->once())->method('deleteKey')->with('integriq', DeveloperPortalAccess::CONFIG_KEY);

		$this->params = ['group' => 'partners'];
		$this->assertSame(Http::STATUS_OK, $controller->setSettings()->getStatus());
		$this->params = ['group' => ''];
		$this->assertSame(Http::STATUS_OK, $controller->setSettings()->getStatus());
	}//end testSavingTheGroupStoresOrClearsIt()

	/**
	 * A failing lookup reads as an empty list rather than an error.
	 *
	 * @return void
	 */
	public function testAFailingLookupReadsAsEmpty(): void {
		$controller = $this->controller();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));

		$this->assertSame(['results' => []], $controller->products()->getData());
	}//end testAFailingLookupReadsAsEmpty()
}//end class
