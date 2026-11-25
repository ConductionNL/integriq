<?php

/**
 * Unit tests for SubscriptionExpiryService: an ended subscription is marked
 * expired, one ending within fourteen days is reminded once, others are left.
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
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\Helper\RegisterSave;
use OCA\Integriq\Service\ProductChangeNoticeService;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\Integriq\Service\SubscriptionExpiryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the daily expiry pass.
 */
#[CoversClass(SubscriptionExpiryService::class)]
#[UsesClass(ProductPolicyService::class)]
#[UsesClass(RegisterSave::class)]
class SubscriptionExpiryServiceTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService&MockObject $objects;

	/**
	 * The notice service.
	 *
	 * @var ProductChangeNoticeService&MockObject
	 */
	private ProductChangeNoticeService&MockObject $notices;

	/**
	 * Saved subscription data, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

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
	 * The service over the given active subscriptions.
	 *
	 * @param list<ObjectEntity> $active The active subscriptions.
	 *
	 * @return SubscriptionExpiryService The service.
	 */
	private function service(array $active): SubscriptionExpiryService {
		$this->objects = $this->createMock(OrObjectService::class);
		$this->notices = $this->createMock(ProductChangeNoticeService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($active): array {
				TestCase::assertSame(['register' => 'integriq', 'schema' => 'api_product_subscription', 'status' => 'active'], $config['filters']);
				return ['results' => $active];
			}
		);
		$this->objects->method('find')->willReturn(self::entity(uuid: 'p-1', data: ['name' => 'Zaken API']));
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				return self::entity(uuid: 'saved', data: $object);
			}
		);

		return new SubscriptionExpiryService(
			orObjectService: $this->objects,
			notices: $this->notices,
			policy: new ProductPolicyService(orObjectService: $this->objects, logger: $logger),
			logger: $logger
		);
	}//end service()

	/**
	 * GIVEN a subscription ending 2026-12-31, WHEN the pass runs on 2027-01-02, THEN it is expired.
	 *
	 * @return void
	 */
	public function testASubscriptionPastItsEndDateIsMarkedExpired(): void {
		$service = $this->service(active: [self::entity(uuid: 's-1', data: ['product' => 'p-1', 'status' => 'active', 'expiresAt' => '2026-12-31T23:59:59+00:00'])]);
		$this->notices->expects($this->never())->method('notifyExpiring');

		$counts = $service->sweep(now: new DateTimeImmutable('2027-01-02T08:00:00+00:00'));

		$this->assertSame(['expired' => 1, 'reminded' => 0], $counts);
		$this->assertSame('expired', $this->saved[0]['status']);
	}//end testASubscriptionPastItsEndDateIsMarkedExpired()

	/**
	 * A subscription ending within fourteen days is reminded once, and the reminder is stamped.
	 *
	 * @return void
	 */
	public function testASubscriptionEndingWithinFourteenDaysIsRemindedOnce(): void {
		$service = $this->service(active: [
			self::entity(uuid: 's-1', data: ['product' => 'p-1', 'status' => 'active', 'expiresAt' => '2026-12-31T23:59:59+00:00']),
			self::entity(uuid: 's-2', data: ['product' => 'p-1', 'status' => 'active', 'expiresAt' => '2026-12-30T00:00:00+00:00', 'expiryReminderSentAt' => '2026-12-16T08:00:00+00:00']),
		]);
		$this->notices->expects($this->once())->method('notifyExpiring')
			->with($this->callback(static fn (ObjectEntity $s): bool => $s->getUuid() === 's-1'), $this->anything(), '2026-12-31T23:59:59+00:00')
			->willReturn(self::entity(uuid: 'n-1', data: []));

		$counts = $service->sweep(now: new DateTimeImmutable('2026-12-20T08:00:00+00:00'));

		$this->assertSame(['expired' => 0, 'reminded' => 1], $counts);
		$this->assertSame('active', $this->saved[0]['status']);
		$this->assertSame('2026-12-20T08:00:00+00:00', $this->saved[0]['expiryReminderSentAt']);
	}//end testASubscriptionEndingWithinFourteenDaysIsRemindedOnce()

	/**
	 * A subscription without an end date, or ending later than fourteen days, is left alone.
	 *
	 * @return void
	 */
	public function testSubscriptionsWithoutANearEndDateAreLeftAlone(): void {
		$service = $this->service(active: [
			self::entity(uuid: 's-1', data: ['product' => 'p-1', 'status' => 'active']),
			self::entity(uuid: 's-2', data: ['product' => 'p-1', 'status' => 'active', 'expiresAt' => '2027-06-01T00:00:00+00:00']),
		]);
		$this->notices->expects($this->never())->method('notifyExpiring');

		$this->assertSame(['expired' => 0, 'reminded' => 0], $service->sweep(now: new DateTimeImmutable('2026-12-20T08:00:00+00:00')));
		$this->assertSame([], $this->saved);
	}//end testSubscriptionsWithoutANearEndDateAreLeftAlone()

	/**
	 * When nobody could be told, the reminder is not stamped, so the next pass tries again.
	 *
	 * @return void
	 */
	public function testAReminderNobodyReceivedIsNotStamped(): void {
		$service = $this->service(active: [self::entity(uuid: 's-1', data: ['product' => 'p-1', 'status' => 'active', 'expiresAt' => '2026-12-31T23:59:59+00:00'])]);
		$this->notices->method('notifyExpiring')->willReturn(null);

		$this->assertSame(['expired' => 0, 'reminded' => 0], $service->sweep(now: new DateTimeImmutable('2026-12-20T08:00:00+00:00')));
		$this->assertSame([], $this->saved);
	}//end testAReminderNobodyReceivedIsNotStamped()
}//end class
