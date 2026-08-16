<?php

/**
 * A rate limit suspends the run instead of ending it.
 *
 * The measured failure this replaces: a twelve-shard crawl where the first
 * three shards spent the whole `code_search` budget and the remaining nine were
 * refused at entry. The run reported success. Re-running did not catch up — the
 * completed shards ran again, spent the budget again, and the same nine
 * starved. Three runs 65 s apart each returned the same 641 repositories.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Flow;

use OCA\OpenConnector\Flow\SynchronizationRunNode;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class SynchronizationRunNodeRateLimitTest extends TestCase {
	private SynchronizationRunNode $node;
	private ReflectionMethod $suspend;

	protected function setUp(): void {
		$this->node = (new ReflectionClass(SynchronizationRunNode::class))->newInstanceWithoutConstructor();

		// The suspension path logs; nothing else it touches is a collaborator.
		$logger = new ReflectionProperty(SynchronizationRunNode::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($this->node, $this->createMock(LoggerInterface::class));

		$this->suspend = new ReflectionMethod(SynchronizationRunNode::class, 'suspendUntilTheLimitLifts');
		$this->suspend->setAccessible(true);
	}

	/**
	 * @param int|null $reset The source's X-RateLimit-Reset value.
	 *
	 * @return FlowSuspension The resulting suspension.
	 */
	private function suspensionFor(?int $reset): FlowSuspension {
		$headers = [];
		if ($reset !== null) {
			$headers['X-RateLimit-Reset'] = $reset;
		}

		return $this->suspend->invoke(
			$this->node,
			new TooManyRequestsHttpException(message: 'Rate Limit on Source has been exceeded.', headers: $headers),
			'publiccode-shard-4'
		);
	}

	/**
	 * The source's own reset is honoured: waking earlier would only be refused
	 * again, and a fixed backoff would usually wake far too late.
	 */
	public function testItWaitsUntilTheSourceSaysTheLimitLifts(): void {
		$reset = (time() + 300);

		$resumeAt = $this->suspensionFor($reset)->getResumeAt();

		$this->assertNotNull($resumeAt);
		$this->assertEqualsWithDelta($reset, $resumeAt->getTimestamp(), 2);
	}

	/**
	 * A reset in the PAST would make the run due immediately and spin against a
	 * source that is still refusing it — a hot loop dressed as a retry.
	 */
	public function testAResetInThePastIsFlooredRatherThanSpinning(): void {
		$resumeAt = $this->suspensionFor(time() - 5000)->getResumeAt();

		$this->assertGreaterThanOrEqual(time() + 59, $resumeAt->getTimestamp());
	}

	/**
	 * A source that reports no reset still gets a real wait. Suspending with no
	 * `resumeAt` would leave the run waiting for a signal nothing sends —
	 * unreachable, and holding its flow's schedule shut behind it.
	 *
	 * This is the assertion that keeps this node out of the class of bug the
	 * signal reaper exists to clean up.
	 */
	public function testASourceWithNoResetStillGetsAWakeUpTime(): void {
		$resumeAt = $this->suspensionFor(null)->getResumeAt();

		$this->assertNotNull($resumeAt, 'A suspension with no resumeAt is one nothing can wake.');
		$this->assertGreaterThan(time(), $resumeAt->getTimestamp());
	}

	/**
	 * An epoch/milliseconds mix-up reads as a reset tens of thousands of years
	 * out. Parking a run on it looks exactly like the run having quietly died.
	 */
	public function testAnAbsurdResetIsCapped(): void {
		$resumeAt = $this->suspensionFor(time() * 1000)->getResumeAt();

		$this->assertLessThanOrEqual(time() + 3600, $resumeAt->getTimestamp());
	}

	/**
	 * The run log has to say WHY it is waiting. "Suspended" with no cause is
	 * the thing an operator cannot act on — and a crawl that is rate limited
	 * looks identical to one that is merely slow.
	 */
	public function testTheReasonNamesTheRateLimitAndTheSynchronization(): void {
		$message = $this->suspensionFor(time() + 60)->getMessage();

		$this->assertStringContainsString('rate limited', $message);
		$this->assertStringContainsString('publiccode-shard-4', $message);
	}
}
