<?php

/**
 * Unit tests for the selection rules of an endpoint with several targets.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\UpstreamSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Rules, groups, weights, stickiness, breaker health and the retry rule.
 */
#[CoversClass(UpstreamSelector::class)]
class UpstreamSelectorTest extends TestCase {

	/**
	 * A selector whose random source is a seeded Mersenne Twister.
	 *
	 * @param int $seed The seed.
	 *
	 * @return UpstreamSelector
	 */
	private static function seeded(int $seed = 42): UpstreamSelector {
		mt_srand($seed);
		return new UpstreamSelector(random: static fn (int $max): int => mt_rand(0, ($max - 1)));
	}//end seeded()

	/**
	 * The design's seed endpoint: a 95/5 canary and an archive rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint(): array {
		return [
			'targetType' => 'api',
			'targets' => [
				['source' => 'zaken-oud', 'weight' => 95],
				['source' => 'zaken-nieuw', 'weight' => 5],
				['source' => 'zaken-archief', 'when' => ['==' => [['var' => 'parameters.archief'], 'true']]],
			],
		];
	}//end endpoint()

	/**
	 * An endpoint without targets yields none, so it keeps its single targetId.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutTargetsHasNone(): void {
		$selector = new UpstreamSelector();

		$this->assertSame([], $selector->targetsOf(endpointData: ['targetType' => 'api', 'targetId' => 'src-1']));
		$this->assertSame([], $selector->targetsOf(endpointData: ['targets' => 'not-a-list']));
		$this->assertSame([], $selector->targetsOf(endpointData: ['targets' => [['weight' => 3], ['source' => '']]]));
	}//end testAnEndpointWithoutTargetsHasNone()

	/**
	 * Targets are normalised: default weight one, default group without a rule, a group of its own with one.
	 *
	 * @return void
	 */
	public function testTargetsAreNormalised(): void {
		$targets = (new UpstreamSelector())->targetsOf(
			endpointData: ['targets' => [
				['source' => 'a'],
				['source' => 'b', 'weight' => -4, 'when' => []],
				['source' => 'c', 'when' => ['==' => [1, 1]]],
				['source' => 'd', 'when' => ['==' => [1, 1]], 'group' => 'eu'],
			]]
		);

		$this->assertSame(
			[
				['index' => 0, 'source' => 'a', 'weight' => 1, 'when' => null, 'group' => ''],
				['index' => 1, 'source' => 'b', 'weight' => 0, 'when' => null, 'group' => ''],
				['index' => 2, 'source' => 'c', 'weight' => 1, 'when' => ['==' => [1, 1]], 'group' => 'rule:2'],
				['index' => 3, 'source' => 'd', 'weight' => 1, 'when' => ['==' => [1, 1]], 'group' => 'eu'],
			],
			$targets
		);
	}//end testTargetsAreNormalised()

	/**
	 * Weights 95 and 5 over 10,000 picks split within one percent of the weights.
	 *
	 * @return void
	 */
	public function testWeightsSplitTheTrafficWithinOnePercent(): void {
		$selector = self::seeded();
		$choice = $selector->candidates(targets: $selector->targetsOf(endpointData: self::endpoint()), request: ['parameters' => [], 'headers' => [], 'body' => []]);

		$this->assertSame('weight', $choice['reason']);
		$this->assertSame(['zaken-oud', 'zaken-nieuw'], array_column($choice['targets'], 'source'));

		$counts = ['zaken-oud' => 0, 'zaken-nieuw' => 0];
		for ($i = 0; $i < 10000; $i++) {
			$counts[$selector->pick(candidates: $choice['targets'])['source']]++;
		}

		$this->assertEqualsWithDelta(9500, $counts['zaken-oud'], 100);
		$this->assertEqualsWithDelta(500, $counts['zaken-nieuw'], 100);
	}//end testWeightsSplitTheTrafficWithinOnePercent()

	/**
	 * The random point maps onto the weights in order: 0..94 the first target, 95..99 the second.
	 *
	 * @return void
	 */
	public function testThePickFollowsTheCumulativeWeights(): void {
		$targets = [['source' => 'a', 'weight' => 95], ['source' => 'b', 'weight' => 5]];

		$this->assertSame('a', (new UpstreamSelector(random: static fn (int $max): int => 94))->pick(candidates: $targets)['source']);
		$this->assertSame('b', (new UpstreamSelector(random: static fn (int $max): int => 95))->pick(candidates: $targets)['source']);
		$this->assertSame('b', (new UpstreamSelector(random: static fn (int $max): int => 99))->pick(candidates: $targets)['source']);
	}//end testThePickFollowsTheCumulativeWeights()

	/**
	 * Only zero weights: every candidate is equally likely; no candidate: null.
	 *
	 * @return void
	 */
	public function testZeroWeightsAreSpreadEvenlyAndNoCandidateIsNull(): void {
		$targets = [['source' => 'a', 'weight' => 0], ['source' => 'b', 'weight' => 0]];
		$maxSeen = null;
		$selector = new UpstreamSelector(random: static function (int $max) use (&$maxSeen): int {
			$maxSeen = $max;
			return 1;
		});

		$this->assertSame('b', $selector->pick(candidates: $targets)['source']);
		$this->assertSame(2, $maxSeen);
		$this->assertNull($selector->pick(candidates: []));
	}//end testZeroWeightsAreSpreadEvenlyAndNoCandidateIsNull()

	/**
	 * A sticky pick is stable per consumer and ignores the random source.
	 *
	 * @return void
	 */
	public function testAStickyPickIsStablePerConsumer(): void {
		$targets = [['source' => 'a', 'weight' => 50], ['source' => 'b', 'weight' => 50]];
		$first = self::seeded(1)->pick(candidates: $targets, stickyKey: 'consumer-7');

		for ($seed = 2; $seed < 30; $seed++) {
			$this->assertSame($first, self::seeded($seed)->pick(candidates: $targets, stickyKey: 'consumer-7'));
		}

		$this->assertSame(($targets[(crc32('consumer-7') % 100) < 50 ? 0 : 1]), $first);

		$seen = [];
		foreach (range(1, 40) as $n) {
			$seen[self::seeded()->pick(candidates: $targets, stickyKey: 'consumer-' . $n)['source']] = true;
		}

		$this->assertCount(2, $seen, 'different consumers land on both targets');
	}//end testAStickyPickIsStablePerConsumer()

	/**
	 * A query rule sends archive requests to the archive target, with the rule as the reason.
	 *
	 * @return void
	 */
	public function testAMatchingRuleChoosesItsTarget(): void {
		$selector = new UpstreamSelector();
		$choice = $selector->candidates(
			targets: $selector->targetsOf(endpointData: self::endpoint()),
			request: ['parameters' => ['archief' => 'true'], 'headers' => [], 'body' => []]
		);

		$this->assertSame('rule:2', $choice['reason']);
		$this->assertSame('rule:2', $choice['group']);
		$this->assertSame(['zaken-archief'], array_column($choice['targets'], 'source'));
	}//end testAMatchingRuleChoosesItsTarget()

	/**
	 * A rule on body.zaaktype routes a matching JSON body; a named group carries all its members.
	 *
	 * @return void
	 */
	public function testABodyRuleRoutesToItsGroup(): void {
		$rule = ['==' => [['var' => 'body.zaaktype'], 'vergunning']];
		$selector = new UpstreamSelector();
		$targets = $selector->targetsOf(
			endpointData: ['targets' => [
				['source' => 'default'],
				['source' => 'vth-1', 'when' => $rule, 'group' => 'vth', 'weight' => 3],
				['source' => 'vth-2', 'when' => $rule, 'group' => 'vth'],
			]]
		);

		$this->assertTrue($selector->readsBody(targets: $targets));

		$matched = $selector->candidates(targets: $targets, request: ['parameters' => [], 'headers' => [], 'body' => ['zaaktype' => 'vergunning']]);
		$this->assertSame('rule:1', $matched['reason']);
		$this->assertSame('vth', $matched['group']);
		$this->assertSame(['vth-1', 'vth-2'], array_column($matched['targets'], 'source'));

		$other = $selector->candidates(targets: $targets, request: ['parameters' => [], 'headers' => [], 'body' => ['zaaktype' => 'melding']]);
		$this->assertSame('weight', $other['reason']);
		$this->assertSame(['default'], array_column($other['targets'], 'source'));
	}//end testABodyRuleRoutesToItsGroup()

	/**
	 * The body is only needed when a rule reads it; a broken rule never matches.
	 *
	 * @return void
	 */
	public function testOnlyABodyRuleReadsTheBodyAndABrokenRuleNeverMatches(): void {
		$selector = new UpstreamSelector();
		$targets = $selector->targetsOf(endpointData: self::endpoint());
		$this->assertFalse($selector->readsBody(targets: $targets));

		$broken = $selector->targetsOf(endpointData: ['targets' => [['source' => 'x', 'when' => ['no-such-operator' => [1]]]]]);
		$choice = $selector->candidates(targets: $broken, request: ['parameters' => [], 'headers' => [], 'body' => []]);
		$this->assertSame('weight', $choice['reason']);
		$this->assertSame([], $choice['targets']);
	}//end testOnlyABodyRuleReadsTheBodyAndABrokenRuleNeverMatches()

	/**
	 * Open and cooling down is down; closed, or open past the cooldown (half-open), is a candidate.
	 *
	 * @return void
	 */
	public function testABreakerIsDownOnlyWhileOpenAndCoolingDown(): void {
		$selector = new UpstreamSelector();
		$now = 1_000_000;

		$this->assertFalse($selector->isDown(sourceData: [], now: $now));
		$this->assertFalse($selector->isDown(sourceData: ['circuitBreakerState' => 'closed', 'circuitBreakerOpenedAt' => $now], now: $now));
		$this->assertTrue($selector->isDown(sourceData: ['circuitBreakerState' => 'open', 'circuitBreakerOpenedAt' => ($now - 29)], now: $now));
		$this->assertFalse($selector->isDown(sourceData: ['circuitBreakerState' => 'open', 'circuitBreakerOpenedAt' => ($now - 30)], now: $now));
		$this->assertTrue(
			$selector->isDown(sourceData: ['circuitBreakerState' => 'open', 'circuitBreakerOpenedAt' => ($now - 100), 'circuitBreakerCooldownSeconds' => 120], now: $now)
		);
	}//end testABreakerIsDownOnlyWhileOpenAndCoolingDown()

	/**
	 * GET, HEAD, PUT and DELETE retry on 502, 503 and 504; POST and PATCH never; other statuses never.
	 *
	 * @return void
	 */
	public function testOnlyIdempotentMethodsRetryOnGatewayFailures(): void {
		$selector = new UpstreamSelector();

		foreach (['GET', 'head', 'PUT', 'DELETE'] as $method) {
			foreach ([502, 503, 504] as $status) {
				$this->assertTrue($selector->mayRetry(method: $method, status: $status), $method . ' ' . $status);
			}

			$this->assertFalse($selector->mayRetry(method: $method, status: 500));
			$this->assertFalse($selector->mayRetry(method: $method, status: 200));
		}

		foreach (['POST', 'PATCH'] as $method) {
			$this->assertFalse($selector->mayRetry(method: $method, status: 503));
		}
	}//end testOnlyIdempotentMethodsRetryOnGatewayFailures()
}//end class
