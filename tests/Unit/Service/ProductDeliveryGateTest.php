<?php

/**
 * Unit tests for ProductDeliveryGate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ProductDeliveryGate;
use OCA\Integriq\Service\ProductPolicyService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The gate reads the product subscription at dispatch and spends the tier budget.
 */
class ProductDeliveryGateTest extends TestCase {

	/**
	 * Objects by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The keys the budget was spent under.
	 *
	 * @var array<int, string>
	 */
	private array $spent = [];

	/**
	 * Set up a product with a limited tier and an active subscription.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->spent = [];
		$this->rows = [
			'ps-1' => ['status' => 'active', 'product' => 'prod-1', 'consumer' => 'cons-1', 'tier' => 'free'],
			'prod-1' => ['tiers' => ['free' => ['rateLimit' => ['requests' => 2, 'window' => 60], 'quota' => ['requests' => 100, 'period' => 'day']]]],
		];
	}//end setUp()

	/**
	 * A subscription outside any product is not gated and reads nothing.
	 *
	 * @return void
	 */
	public function testASubscriptionOutsideAProductIsNotHeld(): void {
		$this->assertNull($this->gate(allowed: false)->hold(subscriptionData: ['sink' => 'https://x.example']));
		$this->assertSame([], $this->spent);
	}//end testASubscriptionOutsideAProductIsNotHeld()

	/**
	 * A revoked, or vanished, product subscription stops the delivery for good.
	 *
	 * @return void
	 */
	public function testARevokedOrMissingSubscriptionAbandons(): void {
		$this->rows['ps-1']['status'] = 'revoked';
		$held = $this->gate(allowed: true)->hold(subscriptionData: ['productSubscription' => 'ps-1']);
		$this->assertSame([true, null], [$held['abandon'], $held['retryAfter']]);

		$this->assertTrue($this->gate(allowed: true)->hold(subscriptionData: ['productSubscription' => 'gone'])['abandon']);
		$this->assertSame([], $this->spent);
	}//end testARevokedOrMissingSubscriptionAbandons()

	/**
	 * Within budget the delivery goes and spends one unit under the product's key.
	 *
	 * @return void
	 */
	public function testWithinBudgetTheDeliveryGoesAndSpendsTheSharedKey(): void {
		$this->assertNull($this->gate(allowed: true)->hold(subscriptionData: ['productSubscription' => 'ps-1']));
		$this->assertSame(['product:prod-1:consumer:cons-1'], $this->spent);
	}//end testWithinBudgetTheDeliveryGoesAndSpendsTheSharedKey()

	/**
	 * Over budget the delivery is deferred by the window's retry-after.
	 *
	 * @return void
	 */
	public function testOverBudgetTheDeliveryWaitsForTheWindow(): void {
		$this->assertSame(
			['reason' => 'The tier budget of the product subscription is spent; delivery waits for the window to end', 'abandon' => false, 'retryAfter' => 900],
			$this->gate(allowed: false)->hold(subscriptionData: ['productSubscription' => 'ps-1'])
		);
	}//end testOverBudgetTheDeliveryWaitsForTheWindow()

	/**
	 * A tier with no limits is never held.
	 *
	 * @return void
	 */
	public function testAnUnlimitedTierIsNotHeld(): void {
		$this->rows['prod-1']['tiers']['free'] = [];
		$this->assertNull($this->gate(allowed: false)->hold(subscriptionData: ['productSubscription' => 'ps-1']));
		$this->assertSame([], $this->spent);
	}//end testAnUnlimitedTierIsNotHeld()

	/**
	 * The gate over the rows.
	 *
	 * @param bool $allowed Whether the budget allows the delivery.
	 *
	 * @return ProductDeliveryGate The gate.
	 */
	private function gate(bool $allowed): ProductDeliveryGate {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id): ObjectEntity {
				if (isset($this->rows[$id]) === false) {
					throw new RuntimeException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->rows[$id]);
				return $entity;
			}
		);
		$limits = $this->createMock(InboundRateLimitService::class);
		$limits->method('enforce')->willReturnCallback(
			function (string $consumerKey) use ($allowed): RateLimitDecision {
				$this->spent[] = $consumerKey;
				return new RateLimitDecision(allowed: $allowed, retryAfter: ($allowed === true ? null : 900), reason: 'quota');
			}
		);

		return new ProductDeliveryGate($objects, new ProductPolicyService(orObjectService: $objects, logger: new NullLogger()), $limits, new NullLogger());
	}//end gate()
}//end class
