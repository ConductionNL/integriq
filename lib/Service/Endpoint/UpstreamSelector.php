<?php

/**
 * Integriq UpstreamSelector.
 *
 * Chooses which of an endpoint's targets serves a request: the first group
 * whose rule matches the request, without the targets whose circuit breaker
 * is open, by weight or by a stable hash of the consumer.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use Closure;
use JWadhams\JsonLogic;
use Throwable;

/**
 * The selection rules of an endpoint with several targets (design D2 and D3).
 *
 * Holds no state and calls nothing: the router loads the sources and makes
 * the calls. The random source is injectable so the weighted spread can be
 * tested with a seeded generator.
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
class UpstreamSelector {

	/**
	 * The methods retried once on another target: the first call cannot have had an effect a second one repeats.
	 */
	public const RETRYABLE_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE'];

	/**
	 * The answers, besides a connection error (which CallService records as 503), that warrant the retry.
	 */
	public const RETRYABLE_STATUSES = [502, 503, 504];

	/**
	 * The cooldown of a breaker whose source names none; the same default CallService applies.
	 */
	public const DEFAULT_COOLDOWN_SECONDS = 30;

	/**
	 * The group of the targets without a rule.
	 */
	public const DEFAULT_GROUP = '';

	/**
	 * Returns an integer from 0 up to, not including, its argument.
	 *
	 * @var Closure(int): int
	 */
	private readonly Closure $random;

	/**
	 * Constructor.
	 *
	 * @param Closure|null $random Returns an integer in [0, max); random_int when null.
	 */
	public function __construct(?Closure $random = null) {
		$this->random = ($random ?? static fn (int $max): int => random_int(0, ($max - 1)));
	}//end __construct()

	/**
	 * The endpoint's targets, normalised; empty when it has none.
	 *
	 * Each target keeps its position as `index`, its `source` uuid, a weight
	 * of at least zero (one when absent), its `when` rule or null, and its
	 * group: the default group without a rule, else its named group, else a
	 * group of its own.
	 *
	 * @param array<string, mixed> $endpointData The endpoint object.
	 *
	 * @return list<array{index: int, source: string, weight: int, when: mixed, group: string}>
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
	 */
	public function targetsOf(array $endpointData): array {
		$targets = ($endpointData['targets'] ?? []);
		if (is_array($targets) === false) {
			return [];
		}

		$normalised = [];
		foreach (array_values($targets) as $index => $target) {
			if (is_array($target) === false || is_string($target['source'] ?? null) === false || $target['source'] === '') {
				continue;
			}

			$when = ($target['when'] ?? null);
			if ($when === [] || $when === '') {
				$when = null;
			}

			$group = self::DEFAULT_GROUP;
			if ($when !== null) {
				$group = (string)($target['group'] ?? '');
				if ($group === '') {
					$group = 'rule:' . $index;
				}
			}

			$normalised[] = [
				'index' => $index,
				'source' => $target['source'],
				'weight' => max(0, (int)($target['weight'] ?? 1)),
				'when' => $when,
				'group' => $group,
			];
		}//end foreach

		return $normalised;
	}//end targetsOf()

	/**
	 * Whether any target's rule reads the request body, so the body is decoded only then.
	 *
	 * @param list<array{when: mixed}> $targets The normalised targets.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-upstream-routing/design.md
	 */
	public function readsBody(array $targets): bool {
		foreach ($targets as $target) {
			if ($target['when'] !== null && str_contains((string)json_encode($target['when']), '"body') === true) {
				return true;
			}
		}

		return false;
	}//end readsBody()

	/**
	 * The targets of the first group whose rule matches the request, else the targets without a rule.
	 *
	 * @param list<array{index: int, source: string, weight: int, when: mixed, group: string}> $targets The normalised targets.
	 * @param array<string, mixed> $request `parameters`, `headers` and, when decoded, `body`.
	 *
	 * @return array{group: string, reason: string, targets: list<array{index: int, source: string, weight: int, when: mixed, group: string}>}
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
	 */
	public function candidates(array $targets, array $request): array {
		foreach ($targets as $target) {
			if ($target['when'] === null || $this->matches(rule: $target['when'], request: $request) === false) {
				continue;
			}

			return [
				'group' => $target['group'],
				'reason' => 'rule:' . $target['index'],
				'targets' => $this->inGroup(targets: $targets, group: $target['group']),
			];
		}

		return [
			'group' => self::DEFAULT_GROUP,
			'reason' => 'weight',
			'targets' => $this->inGroup(targets: $targets, group: self::DEFAULT_GROUP),
		];
	}//end candidates()

	/**
	 * Whether a source's circuit breaker is open and still cooling down.
	 *
	 * A breaker whose cooldown has passed is half-open: CallService lets one
	 * probe through, so the target stays a candidate.
	 *
	 * @param array<string, mixed> $sourceData The source object.
	 * @param int                  $now        The current Unix time.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002
	 */
	public function isDown(array $sourceData, int $now): bool {
		if ((string)($sourceData['circuitBreakerState'] ?? 'closed') !== 'open') {
			return false;
		}

		$cooldown = (int)($sourceData['circuitBreakerCooldownSeconds'] ?? self::DEFAULT_COOLDOWN_SECONDS);

		return ($now - (int)($sourceData['circuitBreakerOpenedAt'] ?? 0)) < $cooldown;
	}//end isDown()

	/**
	 * Pick one candidate: by a stable hash of the sticky key when given, else at random by weight.
	 *
	 * Candidates of weight zero are only picked when every candidate weighs zero, then evenly.
	 *
	 * @param list<T>     $candidates The healthy candidates.
	 * @param string|null $stickyKey  The consumer to keep on one target, or null.
	 *
	 * @return T|null Null when there is no candidate.
	 *
	 * @template T of array{weight: int}
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
	 */
	public function pick(array $candidates, ?string $stickyKey = null): ?array {
		if ($candidates === []) {
			return null;
		}

		$weights = array_map(static fn (array $candidate): int => $candidate['weight'], $candidates);
		if (array_sum($weights) === 0) {
			$weights = array_fill(0, count($candidates), 1);
		}

		$total = array_sum($weights);
		$point = ($this->random)($total);
		if ($stickyKey !== null && $stickyKey !== '') {
			$point = (crc32($stickyKey) % $total);
		}

		foreach ($candidates as $position => $candidate) {
			$point -= $weights[$position];
			if ($point < 0) {
				return $candidate;
			}
		}

		return $candidates[array_key_last($candidates)];
	}//end pick()

	/**
	 * Whether a call that ended in this status is tried once more on another target.
	 *
	 * @param string $method The request method.
	 * @param int    $status The status the target answered; CallService records a connection error as 503.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002
	 */
	public function mayRetry(string $method, int $status): bool {
		return in_array(strtoupper($method), self::RETRYABLE_METHODS, true) === true
			&& in_array($status, self::RETRYABLE_STATUSES, true) === true;
	}//end mayRetry()

	/**
	 * Whether a rule holds for the request; a rule that fails to evaluate does not match.
	 *
	 * @param mixed                $rule    The JsonLogic rule.
	 * @param array<string, mixed> $request The request data.
	 *
	 * @return bool
	 */
	private function matches(mixed $rule, array $request): bool {
		try {
			$result = JsonLogic::apply($rule, $request);
		} catch (Throwable $e) {
			return false;
		}

		return JsonLogic::truthy($result) === true;
	}//end matches()

	/**
	 * The targets of one group, in their order.
	 *
	 * @param list<array{index: int, source: string, weight: int, when: mixed, group: string}> $targets The normalised targets.
	 * @param string $group The group.
	 *
	 * @return list<array{index: int, source: string, weight: int, when: mixed, group: string}>
	 */
	private function inGroup(array $targets, string $group): array {
		return array_values(array_filter($targets, static fn (array $target): bool => $target['group'] === $group));
	}//end inGroup()
}//end class
