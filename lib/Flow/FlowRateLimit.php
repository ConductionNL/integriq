<?php

/**
 * Integriq Flow Rate Limit.
 *
 * The ONE place a rate-limited fetch is turned into a suspension, shared by
 * every node that can be refused by a source.
 *
 * WHY IT IS SHARED RATHER THAN COPIED
 * -----------------------------------
 * `synchronization-run` proved the mechanism (a twelve-shard crawl where nine
 * shards were refused at entry and the run reported success), and
 * `source-paginate` inherits exactly the same failure mode the moment it makes
 * the fetch. Two copies of a clamp are two clamps: the day one of them learns
 * about a new header, the other keeps parking runs for an hour. So the bounds
 * and the reset arithmetic live here once, and each node keeps only its own
 * logging and its own message.
 *
 * WHY IT IS CLAMPED AT BOTH ENDS
 * ------------------------------
 * The reset time comes from the source's own `X-RateLimit-Reset`, which
 * `CallService::sourceRateLimit()` already reads and stores — a header we do
 * not control. A reset already in the past (clock skew, or a source reporting
 * the window it just closed) would make the run due immediately and spin
 * against a source still refusing it. An absurd one — what an
 * epoch/milliseconds mix-up looks like — would park the run tens of thousands
 * of years out, which is indistinguishable from the run having quietly died.
 *
 * A source that gives no usable reset still gets a real wake-up time, because
 * suspending with no `resumeAt` at all leaves the run waiting for a signal
 * nothing sends.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Turns a source's rate-limit refusal into a bounded suspension.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
final class FlowRateLimit {

	/**
	 * The shortest a rate-limit suspension may last, in seconds.
	 *
	 * @var int
	 */
	public const MIN_WAIT_SECONDS = 60;

	/**
	 * The longest, in seconds.
	 *
	 * @var int
	 */
	public const MAX_WAIT_SECONDS = 3600;

	/**
	 * The suspension a rate-limit refusal earns.
	 *
	 * @param TooManyRequestsHttpException $exception The refusal, carrying the rate-limit headers.
	 * @param string $subject What the run was doing — named in the reason so an
	 *                        operator reading "suspended" can see what is waiting.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
	 */
	public static function suspensionFor(TooManyRequestsHttpException $exception, string $subject): FlowSuspension {
		$resumeAt = self::resetTimeFrom(exception: $exception);

		return new FlowSuspension(
			resumeAt: $resumeAt,
			reason: sprintf(
				'rate limited by the source; waiting until %s to continue "%s"',
				$resumeAt->format('c'),
				$subject
			)
		);

	}//end suspensionFor()

	/**
	 * When the source says its limit lifts, clamped at both ends.
	 *
	 * @param TooManyRequestsHttpException $exception The refusal.
	 *
	 * @return DateTime When to try again.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
	 */
	public static function resetTimeFrom(TooManyRequestsHttpException $exception): DateTime {
		$reset = (int)(($exception->getHeaders()['X-RateLimit-Reset'] ?? 0));
		$now = time();

		$seconds = ($reset - $now);
		if ($reset <= 0 || $seconds < self::MIN_WAIT_SECONDS) {
			$seconds = self::MIN_WAIT_SECONDS;
		}

		if ($seconds > self::MAX_WAIT_SECONDS) {
			$seconds = self::MAX_WAIT_SECONDS;
		}

		return (new DateTime())->setTimestamp($now + $seconds);
	}//end resetTimeFrom()
}//end class
