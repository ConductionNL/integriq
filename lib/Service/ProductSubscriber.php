<?php

/**
 * Integriq ProductSubscriber.
 *
 * Resolves the consumer behind a request's bearer token and its active
 * subscription to an API product, or the refusal: 403 `subscription_required`
 * without a token, a consumer or an active subscription, 401 for a token that
 * does not authenticate.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The subscriber of an API product behind a bearer token.
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
 */
class ProductSubscriber {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterCredentialBridgeInterface $credentials Authenticates the bearer token.
	 * @param ProductPolicyServiceInterface         $policy      Finds the consumer's active subscription.
	 */
	public function __construct(
		private readonly OpenRegisterCredentialBridgeInterface $credentials,
		private readonly ProductPolicyServiceInterface $policy,
	) {
	}//end __construct()

	/**
	 * The consumer and its active subscription to the product, or the refusal.
	 *
	 * @param string       $authorization The request's Authorization header.
	 * @param ObjectEntity $product       The product version.
	 *
	 * @return array{consumer: ObjectEntity, subscription: ObjectEntity}|JSONResponse
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
	 */
	public function resolve(string $authorization, ObjectEntity $product): array|JSONResponse {
		$subscriptionRequired = new JSONResponse(
			['error' => 'subscription_required', 'message' => 'No active subscription to this API product'],
			Http::STATUS_FORBIDDEN
		);

		if (str_starts_with($authorization, 'Bearer ') === false || substr_count($authorization, '.') !== 2) {
			return $subscriptionRequired;
		}

		try {
			$this->credentials->authorizeJwt(authorization: $authorization);
		} catch (AuthenticationException $exception) {
			return new JSONResponse(['error' => 'unauthorized', 'message' => $exception->getMessage()], Http::STATUS_UNAUTHORIZED);
		}

		$consumer = $this->credentials->getResolvedConsumer();
		if ($consumer === null) {
			return $subscriptionRequired;
		}

		$subscription = $this->policy->resolveActiveSubscription(consumerUuid: (string)$consumer->getUuid(), productUuid: (string)$product->getUuid());
		if ($subscription === null) {
			return $subscriptionRequired;
		}

		return ['consumer' => $consumer, 'subscription' => $subscription];

	}//end resolve()
}//end class
