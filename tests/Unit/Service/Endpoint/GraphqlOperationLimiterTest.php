<?php

/**
 * Unit tests for a graphql endpoint's per-operation rate limits.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\GraphqlOperation;
use OCA\Integriq\Service\Endpoint\GraphqlOperationLimiter;
use OCA\Integriq\Service\RateLimit\InboundRateLimitServiceInterface;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint limits zakenLijst to 2 per minute and leaves zaakDetails unlimited.
 */
#[CoversClass(GraphqlOperationLimiter::class)]
#[UsesClass(GraphqlOperation::class)]
#[UsesClass(RateLimitDecision::class)]
class GraphqlOperationLimiterTest extends TestCase {

	private const ENDPOINT = [
		'targetType' => 'graphql',
		'operationLimits' => [
			'zakenLijst' => ['requestsPerWindow' => 2, 'windowSeconds' => 60],
			'kapot' => ['requestsPerWindow' => 0, 'windowSeconds' => 60],
		],
	];

	/**
	 * An operation.
	 *
	 * @param string|null $name The operation name.
	 *
	 * @return GraphqlOperation
	 */
	private static function operation(?string $name): GraphqlOperation {
		return new GraphqlOperation(type: 'query', name: $name, depth: 2, rootFields: ['zaken']);
	}

	/**
	 * A limiter that counts per key, like the real one, and records what it was asked.
	 *
	 * @param array<int, array{0: string, 1: array|null}> $asked Filled with every call.
	 *
	 * @return InboundRateLimitServiceInterface
	 */
	private function countingLimiter(array &$asked): InboundRateLimitServiceInterface {
		$counts = [];
		$limiter = $this->createMock(InboundRateLimitServiceInterface::class);
		$limiter->method('enforce')->willReturnCallback(
			function (string $consumerKey, ?array $rateLimit, ?array $quota) use (&$asked, &$counts): RateLimitDecision {
				$asked[] = [$consumerKey, $rateLimit];
				$counts[$consumerKey] = ($counts[$consumerKey] ?? 0) + 1;
				$allowed = $counts[$consumerKey] <= $rateLimit['requestsPerWindow'];
				return new RateLimitDecision(allowed: $allowed, hasRateLimit: true, limit: $rateLimit['requestsPerWindow'], remaining: 0, resetSeconds: 30, retryAfter: ($allowed ? null : 30), reason: ($allowed ? null : RateLimitDecision::REASON_RATE_LIMIT));
			}
		);

		return $limiter;
	}

	public function testTheLimitOfANamedOperationIsRead(): void {
		$this->assertSame(
			['requestsPerWindow' => 2, 'windowSeconds' => 60],
			GraphqlOperationLimiter::limitFor(endpointData: self::ENDPOINT, operation: self::operation(name: 'zakenLijst'))
		);
	}

	public function testAnUnlistedAnonymousOrInvalidLimitIsNoLimit(): void {
		$this->assertNull(GraphqlOperationLimiter::limitFor(endpointData: self::ENDPOINT, operation: self::operation(name: 'zaakDetails')));
		$this->assertNull(GraphqlOperationLimiter::limitFor(endpointData: self::ENDPOINT, operation: self::operation(name: null)));
		$this->assertNull(GraphqlOperationLimiter::limitFor(endpointData: self::ENDPOINT, operation: self::operation(name: 'kapot')));
		$this->assertNull(GraphqlOperationLimiter::limitFor(endpointData: ['operationLimits' => 'x'], operation: self::operation(name: 'zakenLijst')));
	}

	public function testTheThirdCallOfALimitedOperationGetsAGraphqlErrorWith429(): void {
		$asked = [];
		$limiter = new GraphqlOperationLimiter(rateLimits: $this->countingLimiter(asked: $asked));

		$first  = $limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-1');
		$second = $limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-1');
		$third  = $limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-1');

		$this->assertNull($first);
		$this->assertNull($second);
		$this->assertNotNull($third);
		$this->assertSame(429, $third->getStatus());
		$this->assertSame('OPERATION_RATE_LIMITED', $third->getData()['errors'][0]['extensions']['code']);
		$this->assertStringContainsString('zakenLijst', $third->getData()['errors'][0]['message']);
		$this->assertSame('graphql:ep-1:zakenLijst:consumer:c-1', $asked[0][0]);
		$this->assertSame(['requestsPerWindow' => 2, 'windowSeconds' => 60], $asked[0][1]);
	}

	public function testEachConsumerAndEachOperationHasItsOwnCount(): void {
		$asked = [];
		$limiter = new GraphqlOperationLimiter(rateLimits: $this->countingLimiter(asked: $asked));
		for ($i = 0; $i < 2; $i++) {
			$limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-1');
		}

		$this->assertNull($limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-2'));
		$this->assertNull($limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-2', operation: self::operation(name: 'zakenLijst'), callerKey: 'consumer:c-1'));
	}

	public function testAnUnlimitedOperationIsNeverCounted(): void {
		$asked = [];
		$limiter = new GraphqlOperationLimiter(rateLimits: $this->countingLimiter(asked: $asked));

		$this->assertNull($limiter->enforce(endpointData: self::ENDPOINT, endpointKey: 'ep-1', operation: self::operation(name: 'zaakDetails'), callerKey: 'ip:10.0.0.1'));
		$this->assertSame([], $asked);
	}
}
