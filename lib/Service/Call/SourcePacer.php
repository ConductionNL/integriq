<?php

/**
 * Integriq Source Pacer
 *
 * Spaces calls to a source so they stay under its rate limit instead of
 * being refused once the budget is spent.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Call;

use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * A shared budget per source, kept as the time the next call is due
 * (a generic cell rate: each call moves that time on by one interval).
 *
 * Two paces feed the interval and the larger gap wins (design D2):
 * - configured: `source.pace` `{maxCalls, perSeconds, burst}`;
 * - announced: the source's `rateLimitRemaining` calls spread over the time
 *   left until `rateLimitReset`. With nothing remaining the call waits for
 *   the reset.
 *
 * The due time lives in Nextcloud's distributed cache and is moved with
 * compare-and-swap, so every worker and job spends the same budget. Without
 * a distributed cache that can do that, the budget is kept per process
 * ({@see isShared()} says which).
 *
 * How long a call may wait depends on its caller (design D3): a live
 * gateway call `pace.maxLiveWaitMs` (default 2,000), background work
 * `pace.maxBackgroundWaitSeconds` (default 900). A longer wait is refused
 * with the seconds until the call's turn, and nothing is reserved.
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
 */
class SourcePacer {

	/**
	 * Default longest wait of a live call, in milliseconds.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_LIVE_WAIT_MS = 2000;

	/**
	 * Default longest wait of a background call, in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_BACKGROUND_WAIT_SECONDS = 900;

	/**
	 * Attempts at moving the shared due time before writing it unconditionally.
	 *
	 * @var int
	 */
	private const CAS_ATTEMPTS = 50;

	/**
	 * The distributed cache, or null when the budget is per process.
	 *
	 * @var IMemcache|null
	 */
	private ?IMemcache $shared = null;

	/**
	 * The per-process due times by source, used without a shared cache.
	 *
	 * @var array<string,int>
	 */
	private array $local = [];

	/**
	 * The clock the pacer reads and sleeps with.
	 *
	 * @var PacerClock
	 */
	private readonly PacerClock $clock;

	/**
	 * @param ICacheFactory $cacheFactory Provides the distributed cache holding the shared budget.
	 * @param PacerClock|null $clock The clock; the wall clock when null.
	 */
	public function __construct(ICacheFactory $cacheFactory, ?PacerClock $clock = null) {
		$this->clock = ($clock ?? new PacerClock());
		if ($cacheFactory->isAvailable() === true) {
			$cache = $cacheFactory->createDistributed('integriq.source.pace');
			if ($cache instanceof IMemcache) {
				$this->shared = $cache;
			}
		}
	}//end __construct()

	/**
	 * Whether the budget is shared by every worker (a distributed cache) or kept per process.
	 *
	 * @return bool True when shared.
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function isShared(): bool {
		return $this->shared !== null;
	}//end isShared()

	/**
	 * Whether a source is paced at all: it has a usable configured pace, or it
	 * announced a reset time together with its remaining calls.
	 *
	 * @param array $sourceData The source.
	 *
	 * @return bool True when calls to it are paced.
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function isPaced(array $sourceData): bool {
		return $this->configuredIntervalMicros(pace: $this->paceOf(sourceData: $sourceData)) > 0
			|| (is_numeric($sourceData['rateLimitRemaining'] ?? null) === true && is_numeric($sourceData['rateLimitReset'] ?? null) === true);
	}//end isPaced()

	/**
	 * Take the next turn for a call to a source.
	 *
	 * @param string $sourceKey The source's identity (its uuid) the budget is kept under.
	 * @param array $sourceData The source: `pace`, `rateLimitRemaining`, `rateLimitReset`.
	 * @param bool $live True for a live gateway call, false for background work.
	 *
	 * @return PacingDecision The wait, or a refusal with the seconds until the call's turn.
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002
	 */
	public function acquire(string $sourceKey, array $sourceData, bool $live): PacingDecision {
		$pace = $this->paceOf(sourceData: $sourceData);
		$now = $this->clock->nowMicros();
		$configured = $this->configuredIntervalMicros(pace: $pace);
		$announced = $this->announced(sourceData: $sourceData, now: $now);
		$interval = max($configured, $announced['interval']);
		$notBefore = $announced['notBefore'];

		if ($interval === 0 && $notBefore <= $now) {
			return new PacingDecision();
		}

		// The burst lets that many calls go back to back before spacing starts;
		// it applies to the configured pace only.
		$tolerance = 0;
		if ($configured > 0 && $configured >= $announced['interval']) {
			$tolerance = (($this->burstOf(pace: $pace) - 1) * $interval);
		}

		$maxWait = $this->maxWaitMicros(pace: $pace, live: $live);
		$key = 'due.' . $sourceKey;

		for ($attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++) {
			$stored = $this->read(key: $key);
			$due = max($now, ($stored ?? 0));
			$start = max($now, ($due - $tolerance), $notBefore);
			$wait = ($start - $now);

			if ($wait > $maxWait) {
				return new PacingDecision(
					refused: true,
					retryAfterSeconds: max(1, (int)ceil($wait / 1000000))
				);
			}

			$next = (max($due, $start) + $interval);
			if ($this->swap(key: $key, old: $stored, new: $next, now: $now) === true) {
				return new PacingDecision(waitMicros: $wait);
			}

			// Another worker took a turn in between: read again from the current time.
			$now = $this->clock->nowMicros();
		}

		// Contention that never settles: take the turn without the swap rather
		// than send unpaced.
		$this->write(key: $key, value: ($start + $interval), now: $now);

		return new PacingDecision(waitMicros: $wait);
	}//end acquire()

	/**
	 * Wait for a decided turn.
	 *
	 * @param PacingDecision $decision The decision {@see acquire()} gave.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function wait(PacingDecision $decision): void {
		if ($decision->refused === false && $decision->waitMicros > 0) {
			$this->clock->sleepMicros($decision->waitMicros);
		}
	}//end wait()

	/**
	 * The source's configured pace, or an empty array.
	 *
	 * @param array $sourceData The source.
	 *
	 * @return array The pace.
	 */
	private function paceOf(array $sourceData): array {
		$pace = ($sourceData['pace'] ?? null);
		if (is_array($pace) === false) {
			return [];
		}

		return $pace;
	}//end paceOf()

	/**
	 * The gap between two calls the configured pace asks for, 0 when there is none.
	 *
	 * @param array $pace The configured pace.
	 *
	 * @return int Microseconds.
	 */
	private function configuredIntervalMicros(array $pace): int {
		$maxCalls = ($pace['maxCalls'] ?? null);
		$perSeconds = ($pace['perSeconds'] ?? null);
		if (is_numeric($maxCalls) === false || is_numeric($perSeconds) === false
			|| (float)$maxCalls <= 0 || (float)$perSeconds <= 0
		) {
			return 0;
		}

		return (int)round(((float)$perSeconds * 1000000) / (float)$maxCalls);
	}//end configuredIntervalMicros()

	/**
	 * The calls the configured pace lets go back to back, at least one.
	 *
	 * @param array $pace The configured pace.
	 *
	 * @return int The burst.
	 */
	private function burstOf(array $pace): int {
		$burst = ($pace['burst'] ?? 1);
		if (is_numeric($burst) === false) {
			return 1;
		}

		return max(1, (int)$burst);
	}//end burstOf()

	/**
	 * What the source announced: the gap that spreads its remaining calls over
	 * the time left in its window, and, with nothing remaining, the reset.
	 *
	 * @param array $sourceData The source.
	 * @param int $now The current time in microseconds.
	 *
	 * @return array{interval:int,notBefore:int} The announced gap and the earliest start.
	 */
	private function announced(array $sourceData, int $now): array {
		$remaining = ($sourceData['rateLimitRemaining'] ?? null);
		$reset = ($sourceData['rateLimitReset'] ?? null);
		if (is_numeric($remaining) === false || is_numeric($reset) === false) {
			return ['interval' => 0, 'notBefore' => 0];
		}

		$left = (((int)$reset * 1000000) - $now);
		if ($left <= 0) {
			return ['interval' => 0, 'notBefore' => 0];
		}

		if ((int)$remaining <= 0) {
			return ['interval' => 0, 'notBefore' => ((int)$reset * 1000000)];
		}

		return ['interval' => intdiv($left, (int)$remaining), 'notBefore' => 0];
	}//end announced()

	/**
	 * The longest a call may wait for its turn.
	 *
	 * @param array $pace The configured pace.
	 * @param bool $live True for a live gateway call.
	 *
	 * @return int Microseconds.
	 */
	private function maxWaitMicros(array $pace, bool $live): int {
		if ($live === true) {
			$ms = ($pace['maxLiveWaitMs'] ?? self::DEFAULT_MAX_LIVE_WAIT_MS);
			if (is_numeric($ms) === false || (int)$ms < 0) {
				$ms = self::DEFAULT_MAX_LIVE_WAIT_MS;
			}

			return ((int)$ms * 1000);
		}

		$seconds = ($pace['maxBackgroundWaitSeconds'] ?? self::DEFAULT_MAX_BACKGROUND_WAIT_SECONDS);
		if (is_numeric($seconds) === false || (int)$seconds < 0) {
			$seconds = self::DEFAULT_MAX_BACKGROUND_WAIT_SECONDS;
		}

		return ((int)$seconds * 1000000);
	}//end maxWaitMicros()

	/**
	 * The stored due time of a budget.
	 *
	 * @param string $key The budget's key.
	 *
	 * @return int|null Microseconds, or null when none is stored.
	 */
	private function read(string $key): ?int {
		if ($this->shared === null) {
			return ($this->local[$key] ?? null);
		}

		$value = $this->shared->get($key);
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end read()

	/**
	 * Move a budget's due time from the value read to the new one, only when no one moved it in between.
	 *
	 * @param string $key The budget's key.
	 * @param int|null $old The value read, null when none was stored.
	 * @param int $new The new due time.
	 * @param int $now The current time, for the entry's lifetime.
	 *
	 * @return bool True when this call moved it.
	 */
	private function swap(string $key, ?int $old, int $new, int $now): bool {
		if ($this->shared === null) {
			$this->local[$key] = $new;
			return true;
		}

		if ($old === null) {
			return (bool)$this->shared->add($key, $new, $this->ttl(due: $new, now: $now));
		}

		return (bool)$this->shared->cas($key, $old, $new);
	}//end swap()

	/**
	 * Write a budget's due time unconditionally.
	 *
	 * @param string $key The budget's key.
	 * @param int $value The due time.
	 * @param int $now The current time, for the entry's lifetime.
	 *
	 * @return void
	 */
	private function write(string $key, int $value, int $now): void {
		if ($this->shared === null) {
			$this->local[$key] = $value;
			return;
		}

		$this->shared->set($key, $value, $this->ttl(due: $value, now: $now));
	}//end write()

	/**
	 * How long a due time is kept: a day, or longer when it lies further ahead.
	 * A compare-and-swap keeps the lifetime the entry was added with, so it is
	 * generous; a due time in the past is harmless, the current time wins.
	 *
	 * @param int $due The due time.
	 * @param int $now The current time.
	 *
	 * @return int Seconds.
	 */
	private function ttl(int $due, int $now): int {
		return max(86400, ((int)ceil(($due - $now) / 1000000) + 60));
	}//end ttl()
}//end class
