<?php

/**
 * Contract of the ProductPolicyService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ProductPolicyService} that other components call.
 */
interface ProductPolicyServiceInterface {
	/**
	 * The counter key a consumer's calls to a product are limited under, for endpoints and channels alike.
	 *
	 * @param ObjectEntity $product      The api_product.
	 * @param string       $consumerUuid The consumer's uuid.
	 *
	 * @return string The key `product:<product uuid>:consumer:<consumer uuid>`.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	public function counterKey(ObjectEntity $product, string $consumerUuid): string;

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
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-1
	 */
	public function resolveActiveSubscription(string $consumerUuid, string $productUuid): ?ObjectEntity;

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
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-1
	 */
	public function resolveTierPolicy(ObjectEntity $product, ObjectEntity $subscription): ?array;

	/**
	 * Build the RFC 8594 `Deprecation`/`Sunset` header pair for a product, or
	 * `[]` when the product is not `deprecated` (REQ-APG-006/REQ-EP-008).
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006
	 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/tasks.md#task-1
	 */
	public function buildDeprecationHeaders(ObjectEntity $product): array;

	/**
	 * The date a subscription ended on, when its `expiresAt` has passed, else null.
	 *
	 * @param ObjectEntity            $subscription The api_product_subscription.
	 * @param DateTimeInterface|null  $now          The moment to judge at; now when null.
	 *
	 * @return string|null The end date as `Y-m-d`, or null while the subscription runs.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
	 */
	public function expiredOn(ObjectEntity $subscription, ?DateTimeInterface $now=null): ?string;

	/**
	 * Whether a product is retired, so its endpoints answer 410 Gone.
	 *
	 * @param ObjectEntity $product The api_product.
	 *
	 * @return bool True when its status is `retired`.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
	 */
	public function isRetired(ObjectEntity $product): bool;
}
