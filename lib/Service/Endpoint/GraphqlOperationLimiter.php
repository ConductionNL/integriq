<?php

/**
 * Integriq GraphqlOperationLimiter
 *
 * Applies a graphql endpoint's per-operation rate limits, counted per caller.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCA\Integriq\Service\RateLimit\InboundRateLimitServiceInterface;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Counts each GraphQL operation against the endpoint's `operationLimits` (design D1).
 *
 * `operationLimits` maps an operation name to `{requestsPerWindow, windowSeconds}`,
 * the shape a consumer's own `rateLimit` has, and is counted with the same
 * limiter. The counter is per endpoint, per operation and per caller, so one
 * consumer's heavy `zakenLijst` neither spends another consumer's allowance
 * nor that consumer's allowance for `zaakDetails`.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 */
class GraphqlOperationLimiter {

	/**
	 * Constructor for GraphqlOperationLimiter.
	 *
	 * @param InboundRateLimitServiceInterface $rateLimits The limiter consumer rate limits use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly InboundRateLimitServiceInterface $rateLimits,
	) {

	}//end __construct()

	/**
	 * The limit an endpoint sets for one operation, or null when it sets none.
	 *
	 * An entry counts only with a positive `requestsPerWindow` and `windowSeconds`;
	 * an anonymous operation has no name to look up and is never limited here.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param GraphqlOperation     $operation    The operation.
	 *
	 * @return array{requestsPerWindow: int, windowSeconds: int}|null
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public static function limitFor(array $endpointData, GraphqlOperation $operation): ?array {
		$limits = ($endpointData['operationLimits'] ?? null);
		if ($operation->name === null || is_array($limits) === false) {
			return null;
		}

		$limit = ($limits[$operation->name] ?? null);
		if (is_array($limit) === false) {
			return null;
		}

		$requests = filter_var(($limit['requestsPerWindow'] ?? null), FILTER_VALIDATE_INT);
		$window   = filter_var(($limit['windowSeconds'] ?? null), FILTER_VALIDATE_INT);
		if ($requests === false || $window === false || $requests < 1 || $window < 1) {
			return null;
		}

		return ['requestsPerWindow' => $requests, 'windowSeconds' => $window];

	}//end limitFor()

	/**
	 * Count one operation and refuse it past its limit.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 * @param string               $endpointKey  The endpoint's uuid.
	 * @param GraphqlOperation     $operation    The operation.
	 * @param string               $callerKey    The caller: `consumer:<uuid>` or `ip:<address>`.
	 *
	 * @return JSONResponse|null A GraphQL error with 429 past the limit, null otherwise.
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
	 */
	public function enforce(array $endpointData, string $endpointKey, GraphqlOperation $operation, string $callerKey): ?JSONResponse {
		$limit = self::limitFor(endpointData: $endpointData, operation: $operation);
		if ($limit === null) {
			return null;
		}

		$decision = $this->rateLimits->enforce(
			consumerKey: 'graphql:' . $endpointKey . ':' . $operation->name . ':' . $callerKey,
			rateLimit: $limit,
			quota: null
		);
		if ($decision->allowed === true) {
			return null;
		}

		return self::refusal(name: (string)$operation->name, decision: $decision);

	}//end enforce()

	/**
	 * The GraphQL error for an operation past its limit.
	 *
	 * @param string            $name     The operation name.
	 * @param RateLimitDecision $decision The limiter's refusal.
	 *
	 * @return JSONResponse
	 */
	private static function refusal(string $name, RateLimitDecision $decision): JSONResponse {
		return new JSONResponse(
			[
				'errors' => [
					[
						'message' => 'The operation "' . $name . '" has reached its rate limit on this endpoint.',
						'extensions' => ['code' => 'OPERATION_RATE_LIMITED', 'retryAfter' => $decision->retryAfter],
					],
				],
			],
			Http::STATUS_TOO_MANY_REQUESTS,
			$decision->toHeaders()
		);

	}//end refusal()
}//end class
