<?php

/**
 * Integriq ProductPolicyService.
 *
 * The API product policy shared by a product's endpoints and its event
 * channels: the consumer's active subscription, the subscription tier's rate
 * limit and quota under one counter key, and the deprecation headers.
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
 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lifted out of EndpointService unchanged, so a product endpoint and a product
 * channel are held to one policy (design D2). The rate limiter itself, the
 * 429 answer and the throttle log stay with the caller; this service says
 * which subscription, which tier policy and which counter key apply.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
 */
class ProductPolicyService {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $orObjectService OpenRegister's object service.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
	 */
	public function __construct(
		private readonly ORObjectService $orObjectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The counter key a consumer's calls to a product are limited under, for endpoints and channels alike.
	 *
	 * @param ObjectEntity $product      The api_product.
	 * @param string       $consumerUuid The consumer's uuid.
	 *
	 * @return string The key `product:<product uuid>:consumer:<consumer uuid>`.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	public function counterKey(ObjectEntity $product, string $consumerUuid): string {
		return 'product:' . ((string)$product->getUuid()) . ':consumer:' . $consumerUuid;
	}//end counterKey()

	/**
	 * Resolve a consumer's `active` `api_product_subscription` to a given
	 * product, or null when it has none (pending_approval, rejected, revoked
	 * and absent all collapse to the same null; REQ-APG-004).
	 *
	 * @param string $consumerUuid The resolved consumer's uuid.
	 * @param string $productUuid  The api_product's uuid.
	 *
	 * @return ObjectEntity|null The active subscription, or null.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
	 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
	 */
	public function resolveActiveSubscription(string $consumerUuid, string $productUuid): ?ObjectEntity {
		try {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => 'integriq',
						'schema' => 'api_product_subscription',
						'consumer' => $consumerUuid,
						'product' => $productUuid,
						'status' => 'active',
					],
					'limit' => 5,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: failed to resolve api_product_subscription: ' . $e->getMessage());
			return null;
		}

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false || $results === []) {
			return null;
		}

		$first = reset($results);
		if ($first instanceof ObjectEntity) {
			return $first;
		}

		return null;
	}//end resolveActiveSubscription()

	/**
	 * Resolve a subscription's tier to its `rateLimit`/`quota` policy on the
	 * owning product, or null when the tier no longer exists on the product.
	 *
	 * @param ObjectEntity $product      The api_product.
	 * @param ObjectEntity $subscription The active api_product_subscription.
	 *
	 * @return array{rateLimit: array|null, quota: array|null}|null
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
	 */
	public function resolveTierPolicy(ObjectEntity $product, ObjectEntity $subscription): ?array {
		$tiers = ($product->getObject()['tiers'] ?? []);
		$tierName = (string)($subscription->getObject()['tier'] ?? '');

		if (is_array($tiers) === false || $tierName === ''
			|| isset($tiers[$tierName]) === false || is_array($tiers[$tierName]) === false
		) {
			return null;
		}

		$tier = $tiers[$tierName];
		$rateLimit = ($tier['rateLimit'] ?? null);
		$quota = ($tier['quota'] ?? null);

		if (is_array($rateLimit) === false) {
			$rateLimit = null;
		}

		if (is_array($quota) === false) {
			$quota = null;
		}

		return [
			'rateLimit' => $rateLimit,
			'quota' => $quota,
		];
	}//end resolveTierPolicy()

	/**
	 * Build the RFC 8594 `Deprecation`/`Sunset` header pair for a product, or
	 * `[]` when the product is not `deprecated` (REQ-APG-006/REQ-EP-008).
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006
	 * @spec openspec/changes/events-async-api-products/tasks.md#task-1
	 */
	public function buildDeprecationHeaders(ObjectEntity $product): array {
		$data = $product->getObject();
		if (($data['status'] ?? '') !== 'deprecated') {
			return [];
		}

		$headers = ['Deprecation' => 'true'];
		$sunsetDate = ($data['sunsetDate'] ?? null);

		if (is_string($sunsetDate) === true && $sunsetDate !== '') {
			try {
				$sunset = new DateTime($sunsetDate);
				$sunset->setTimezone(new DateTimeZone('UTC'));
				$headers['Sunset'] = $sunset->format('D, d M Y H:i:s') . ' GMT';
			} catch (Throwable $e) {
				$this->logger->warning('integriq: invalid api_product.sunsetDate: ' . $e->getMessage());
			}
		}

		return $headers;
	}//end buildDeprecationHeaders()
}//end class
