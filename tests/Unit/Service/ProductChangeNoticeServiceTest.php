<?php

/**
 * Unit tests for ProductChangeNoticeService: which product changes are told
 * to subscribers, one notice per active subscription addressed to its owner,
 * the new-version notice and the expiry reminder.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductChangeNoticeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the product change notices.
 */
#[CoversClass(ProductChangeNoticeService::class)]
#[UsesClass(RegisterSave::class)]
class ProductChangeNoticeServiceTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService&MockObject $objects;

	/**
	 * The service under test.
	 *
	 * @var ProductChangeNoticeService
	 */
	private ProductChangeNoticeService $service;

	/**
	 * Notices saved, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over a mocked object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(OrObjectService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => vsprintf($text, $params));

		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return self::entity(uuid: 'notice-' . count($this->saved), data: $object);
			}
		);

		$this->service = new ProductChangeNoticeService(orObjectService: $this->objects, l: $l, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

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
	 * Answer findAll with subscriptions and products by schema, and find with consumers by id.
	 *
	 * @param array<string, list<ObjectEntity>> $subscriptionsByProduct Active subscriptions per product uuid.
	 * @param array<string, string>             $consumerOwners          Consumer uuid to its userId.
	 * @param list<ObjectEntity>                $products                Products for a productSlug lookup.
	 *
	 * @return void
	 */
	private function withData(array $subscriptionsByProduct, array $consumerOwners, array $products=[]): void {
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($subscriptionsByProduct, $products): array {
				$filters = $config['filters'];
				if ($filters['schema'] === 'api_product_subscription') {
					TestCase::assertSame('active', $filters['status']);
					return ['results' => ($subscriptionsByProduct[$filters['product']] ?? [])];
				}

				return ['results' => $products];
			}
		);
		$this->objects->method('find')->willReturnCallback(
			static function (string $id) use ($consumerOwners): ObjectEntity {
				if (isset($consumerOwners[$id]) === false) {
					throw new RuntimeException('not found');
				}

				return self::entity(uuid: $id, data: ['userId' => $consumerOwners[$id]]);
			}
		);
	}//end withData()

	/**
	 * Deprecating, retiring and moving the sunset date are each one kind; other edits are none.
	 *
	 * @return void
	 */
	public function testTheKindOfChangeIsJudgedFromBeforeAndAfter(): void {
		$this->assertSame('deprecated', $this->service->kindFor(old: ['status' => 'active'], new: ['status' => 'deprecated', 'sunsetDate' => '2027-01-01']));
		$this->assertSame('retired', $this->service->kindFor(old: ['status' => 'deprecated'], new: ['status' => 'retired']));
		$this->assertSame('sunset-date-set', $this->service->kindFor(old: ['status' => 'deprecated', 'sunsetDate' => '2027-01-01'], new: ['status' => 'deprecated', 'sunsetDate' => '2027-03-01']));
		$this->assertNull($this->service->kindFor(old: ['status' => 'deprecated', 'sunsetDate' => '2027-01-01'], new: ['status' => 'deprecated', 'sunsetDate' => '2027-01-01', 'name' => 'renamed']));
		$this->assertNull($this->service->kindFor(old: ['status' => 'retired'], new: ['status' => 'retired', 'sunsetDate' => '2027-05-01']));
		$this->assertNull($this->service->kindFor(old: [], new: ['status' => 'active']));
	}//end testTheKindOfChangeIsJudgedFromBeforeAndAfter()

	/**
	 * GIVEN Besluiten API with an active subscription owned by a developer, WHEN it is
	 * deprecated with sunset 2027-01-01, THEN one notice to that developer names the product and the date.
	 *
	 * @return void
	 */
	public function testADeprecationWritesOneNoticePerActiveSubscriptionToItsOwner(): void {
		$product = self::entity(uuid: 'p-1', data: ['name' => 'Besluiten API', 'status' => 'deprecated', 'sunsetDate' => '2027-01-01T00:00:00+00:00']);
		$this->withData(
			subscriptionsByProduct: ['p-1' => [
				self::entity(uuid: 's-1', data: ['consumer' => 'c-1', 'requesterUserId' => 'admin']),
				self::entity(uuid: 's-2', data: ['consumer' => 'c-2']),
			]],
			consumerOwners: ['c-1' => 'developer1', 'c-2' => 'developer2']
		);

		$this->assertSame(2, $this->service->notifySubscribers(product: $product, kind: 'deprecated'));

		$this->assertCount(2, $this->saved);
		$this->assertSame('developer1', $this->saved[0]['recipientUserId']);
		$this->assertSame('developer2', $this->saved[1]['recipientUserId']);
		$this->assertSame('p-1', $this->saved[0]['product']);
		$this->assertSame('s-1', $this->saved[0]['subscription']);
		$this->assertSame('deprecated', $this->saved[0]['kind']);
		$this->assertSame('Besluiten API', $this->saved[0]['productName']);
		$this->assertSame('Besluiten API is deprecated and is retired on 2027-01-01.', $this->saved[0]['message']);
		$this->assertSame('2027-01-01T00:00:00+00:00', $this->saved[0]['effectiveDate']);
	}//end testADeprecationWritesOneNoticePerActiveSubscriptionToItsOwner()

	/**
	 * A consumer without an account falls back to the requester; with neither, no notice is written.
	 *
	 * @return void
	 */
	public function testTheRequesterIsToldWhenTheConsumerHasNoOwnerAndNobodyWhenNeitherExists(): void {
		$product = self::entity(uuid: 'p-1', data: ['name' => 'Zaken API', 'status' => 'retired']);
		$this->withData(
			subscriptionsByProduct: ['p-1' => [
				self::entity(uuid: 's-1', data: ['consumer' => 'c-gone', 'requesterUserId' => 'alice']),
				self::entity(uuid: 's-2', data: ['consumer' => 'c-gone']),
			]],
			consumerOwners: []
		);

		$this->assertSame(1, $this->service->notifySubscribers(product: $product, kind: 'retired'));
		$this->assertSame('alice', $this->saved[0]['recipientUserId']);
		$this->assertSame('Zaken API is retired. Its endpoints no longer answer.', $this->saved[0]['message']);
		$this->assertArrayNotHasKey('effectiveDate', $this->saved[0]);
	}//end testTheRequesterIsToldWhenTheConsumerHasNoOwnerAndNobodyWhenNeitherExists()

	/**
	 * A new version tells the subscribers of the other versions, not of the new row itself.
	 *
	 * @return void
	 */
	public function testANewVersionTellsTheSubscribersOfTheOtherVersions(): void {
		$v1 = self::entity(uuid: 'p-1', data: ['name' => 'Zaken API', 'productSlug' => 'zaken-api', 'version' => '1.0.0']);
		$v2 = self::entity(uuid: 'p-2', data: ['name' => 'Zaken API', 'productSlug' => 'zaken-api', 'version' => '2.0.0']);
		$this->withData(
			subscriptionsByProduct: [
				'p-1' => [self::entity(uuid: 's-1', data: ['consumer' => 'c-1'])],
				'p-2' => [self::entity(uuid: 's-9', data: ['consumer' => 'c-1'])],
			],
			consumerOwners: ['c-1' => 'developer1'],
			products: [$v1, $v2]
		);

		$this->assertSame(1, $this->service->notifyNewVersion(newProduct: $v2));
		$this->assertSame('new-version', $this->saved[0]['kind']);
		$this->assertSame('p-1', $this->saved[0]['product']);
		$this->assertSame('Zaken API has a new version: 2.0.0.', $this->saved[0]['message']);
	}//end testANewVersionTellsTheSubscribersOfTheOtherVersions()

	/**
	 * A product without a productSlug has no other versions to tell.
	 *
	 * @return void
	 */
	public function testAProductWithoutASlugTellsNobodyOfANewVersion(): void {
		$this->objects->expects($this->never())->method('findAll');

		$this->assertSame(0, $this->service->notifyNewVersion(newProduct: self::entity(uuid: 'p-1', data: ['name' => 'X'])));
	}//end testAProductWithoutASlugTellsNobodyOfANewVersion()

	/**
	 * The expiry reminder names the product and the end date.
	 *
	 * @return void
	 */
	public function testTheExpiryReminderNamesTheProductAndTheEndDate(): void {
		$this->withData(subscriptionsByProduct: [], consumerOwners: ['c-1' => 'developer1']);
		$subscription = self::entity(uuid: 's-1', data: ['consumer' => 'c-1']);
		$product = self::entity(uuid: 'p-1', data: ['name' => 'Zaken API']);

		$notice = $this->service->notifyExpiring(subscription: $subscription, product: $product, expiresAt: '2026-12-31T23:59:59+00:00');

		$this->assertInstanceOf(ObjectEntity::class, $notice);
		$this->assertSame('subscription-expiring', $this->saved[0]['kind']);
		$this->assertSame('Your subscription to Zaken API ends on 2026-12-31.', $this->saved[0]['message']);
		$this->assertSame('developer1', $this->saved[0]['recipientUserId']);
	}//end testTheExpiryReminderNamesTheProductAndTheEndDate()

	/**
	 * A notice that cannot be saved is not counted and does not throw.
	 *
	 * @return void
	 */
	public function testANoticeThatCannotBeSavedIsNotCounted(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(['results' => [self::entity(uuid: 's-1', data: ['requesterUserId' => 'alice'])]]);
		$objects->method('saveObject')->willThrowException(new RuntimeException('schema not found'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$service = new ProductChangeNoticeService(orObjectService: $objects, l: $l, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(0, $service->notifySubscribers(product: self::entity(uuid: 'p-1', data: ['name' => 'X']), kind: 'retired'));
	}//end testANoticeThatCannotBeSavedIsNotCounted()
}//end class
