<?php

/**
 * Unit tests for ProductPolicyService — the API product policy shared by a
 * product's endpoints and channels: the active subscription lookup, the tier
 * policy, the counter key and the deprecation headers.
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
 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ProductPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the shared product policy.
 */
#[CoversClass(ProductPolicyService::class)]
class ProductPolicyServiceTest extends TestCase {

	/**
	 * An object entity.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
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
	 * The active subscription is looked up by consumer, product and status, and the first match is answered.
	 *
	 * @return void
	 */
	public function testTheActiveSubscriptionIsLookedUpByConsumerAndProduct(): void {
		$subscription = self::entity(uuid: 'sub-1', data: ['tier' => 'free']);
		$objects = $this->createMock(ORObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => [
					'register' => 'integriq',
					'schema' => 'api_product_subscription',
					'consumer' => 'c-1',
					'product' => 'p-1',
					'status' => 'active',
				],
				'limit' => 5,
			]
		)->willReturn(['results' => [$subscription, self::entity(uuid: 'sub-2', data: [])]]);

		$policy = new ProductPolicyService(orObjectService: $objects, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame($subscription, $policy->resolveActiveSubscription(consumerUuid: 'c-1', productUuid: 'p-1'));
	}//end testTheActiveSubscriptionIsLookedUpByConsumerAndProduct()

	/**
	 * No match, a non-entity match or a failing lookup all answer null, and a failure is logged.
	 *
	 * @return void
	 */
	public function testNoActiveSubscriptionAnswersNull(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnOnConsecutiveCalls(['results' => []], [['tier' => 'free']], $this->throwException(new RuntimeException('db down')));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('integriq: failed to resolve api_product_subscription: db down');
		$policy = new ProductPolicyService(orObjectService: $objects, logger: $logger);

		$this->assertNull($policy->resolveActiveSubscription(consumerUuid: 'c-1', productUuid: 'p-1'));
		$this->assertNull($policy->resolveActiveSubscription(consumerUuid: 'c-1', productUuid: 'p-1'));
		$this->assertNull($policy->resolveActiveSubscription(consumerUuid: 'c-1', productUuid: 'p-1'));
	}//end testNoActiveSubscriptionAnswersNull()

	/**
	 * A subscription's tier resolves to its rate limit and quota; an unknown or missing tier answers null.
	 *
	 * @return void
	 */
	public function testTheTierResolvesToItsPolicy(): void {
		$policy = new ProductPolicyService(orObjectService: $this->createMock(ORObjectService::class), logger: $this->createMock(LoggerInterface::class));
		$product = self::entity(
			uuid: 'p-1',
			data: ['tiers' => ['free' => ['rateLimit' => ['requestsPerWindow' => 2, 'windowSeconds' => 60], 'quota' => 'none'], 'gold' => 'broken']]
		);

		$this->assertSame(
			['rateLimit' => ['requestsPerWindow' => 2, 'windowSeconds' => 60], 'quota' => null],
			$policy->resolveTierPolicy(product: $product, subscription: self::entity(uuid: 's', data: ['tier' => 'free']))
		);
		$this->assertNull($policy->resolveTierPolicy(product: $product, subscription: self::entity(uuid: 's', data: ['tier' => 'gold'])));
		$this->assertNull($policy->resolveTierPolicy(product: $product, subscription: self::entity(uuid: 's', data: ['tier' => 'platinum'])));
		$this->assertNull($policy->resolveTierPolicy(product: $product, subscription: self::entity(uuid: 's', data: [])));
	}//end testTheTierResolvesToItsPolicy()

	/**
	 * Endpoints and channels count under one key per product and consumer.
	 *
	 * @return void
	 */
	public function testTheCounterKeyNamesProductAndConsumer(): void {
		$policy = new ProductPolicyService(orObjectService: $this->createMock(ORObjectService::class), logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('product:p-1:consumer:c-1', $policy->counterKey(product: self::entity(uuid: 'p-1', data: []), consumerUuid: 'c-1'));
	}//end testTheCounterKeyNamesProductAndConsumer()

	/**
	 * A deprecated product carries Deprecation and a UTC Sunset; an active one carries none; a bad date is logged and left out.
	 *
	 * @return void
	 */
	public function testDeprecationHeaders(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$policy = new ProductPolicyService(orObjectService: $this->createMock(ORObjectService::class), logger: $logger);

		$this->assertSame(
			['Deprecation' => 'true', 'Sunset' => 'Sat, 31 Jan 2026 23:00:00 GMT'],
			$policy->buildDeprecationHeaders(product: self::entity(uuid: 'p', data: ['status' => 'deprecated', 'sunsetDate' => '2026-02-01T00:00:00+01:00']))
		);
		$this->assertSame([], $policy->buildDeprecationHeaders(product: self::entity(uuid: 'p', data: ['status' => 'active', 'sunsetDate' => '2026-02-01'])));
		$this->assertSame(['Deprecation' => 'true'], $policy->buildDeprecationHeaders(product: self::entity(uuid: 'p', data: ['status' => 'deprecated', 'sunsetDate' => 'not a date'])));
	}//end testDeprecationHeaders()
}//end class
