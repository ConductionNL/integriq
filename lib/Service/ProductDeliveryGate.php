<?php

/**
 * Integriq ProductDeliveryGate
 *
 * A push subscription opened through an API product channel rides on the
 * consumer's product subscription (events-async-api-products, REQ-AAPI-003).
 * Before each push delivery this gate reads that product subscription: a
 * revoked or missing one stops the delivery, and a delivery over the tier's
 * quota waits until the quota window ends instead of being dropped. The read
 * happens at dispatch, so a revoke takes effect on the next delivery.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a product channel's push delivery may go now.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
 */
class ProductDeliveryGate {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService         $objects    Reads the product subscription and the product.
	 * @param ProductPolicyService    $policy     Tier policy and the shared counter key.
	 * @param InboundRateLimitService $rateLimits The tier counters the product's endpoints spend.
	 * @param LoggerInterface         $logger     The logger.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	public function __construct(
		private readonly ORObjectService $objects,
		private readonly ProductPolicyService $policy,
		private readonly InboundRateLimitService $rateLimits,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a push delivery must not go now, and why.
	 *
	 * A subscription that names no product subscription is not gated. Each
	 * delivery that goes spends one unit of the tier budget.
	 *
	 * @param array<string, mixed> $subscriptionData The push subscription.
	 *
	 * @return array{reason: string, abandon: bool, retryAfter: ?int}|null Null when the delivery may go;
	 *   `abandon` true stops it for good, false defers it by `retryAfter` seconds.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-push-deliveries-follow-the-product-subscription-req-aapi-003
	 */
	public function hold(array $subscriptionData): ?array {
		$riddenOn = trim((string)($subscriptionData['productSubscription'] ?? ''));
		if ($riddenOn === '') {
			return null;
		}

		$subscription = $this->read(id: $riddenOn, schema: 'api_product_subscription');
		if ($subscription === null || ($subscription->getObject()['status'] ?? '') !== 'active') {
			return ['reason' => 'The product subscription this delivery rides on is not active', 'abandon' => true, 'retryAfter' => null];
		}

		$product = $this->read(id: (string)($subscription->getObject()['product'] ?? ''), schema: 'api_product');
		if ($product === null) {
			return ['reason' => 'The API product of this delivery no longer exists', 'abandon' => true, 'retryAfter' => null];
		}

		$tier = $this->policy->resolveTierPolicy(product: $product, subscription: $subscription);
		if ($tier === null || ($tier['rateLimit'] === null && $tier['quota'] === null)) {
			return null;
		}

		$decision = $this->rateLimits->enforce(
			consumerKey: $this->policy->counterKey(product: $product, consumerUuid: (string)($subscription->getObject()['consumer'] ?? '')),
			rateLimit: $tier['rateLimit'],
			quota: $tier['quota']
		);
		if ($decision->allowed === true) {
			return null;
		}

		return [
			'reason' => 'The tier budget of the product subscription is spent; delivery waits for the window to end',
			'abandon' => false,
			'retryAfter' => max(1, (int)($decision->retryAfter ?? $decision->resetSeconds ?? 60)),
		];
	}//end hold()

	/**
	 * One integriq object by id, or null.
	 *
	 * @param string $id     The id.
	 * @param string $schema The schema.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function read(string $id, string $schema): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			return $this->objects->find(id: $id, register: 'integriq', schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->info('integriq: ' . $schema . ' ' . $id . ' could not be read for a push delivery: ' . $exception->getMessage());
			return null;
		}
	}//end read()
}//end class
