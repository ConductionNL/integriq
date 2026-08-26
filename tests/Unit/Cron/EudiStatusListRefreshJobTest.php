<?php

/**
 * Unit tests for EudiStatusListRefreshJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Cron;

use OCA\Integriq\Cron\EudiStatusListRefreshJob;
use OCA\Integriq\Service\EudiStatusListService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the status-list refresh sweep background job.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
 */
class EudiStatusListRefreshJobTest extends TestCase {

	/**
	 * @var EudiStatusListService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $statusListService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var EudiStatusListRefreshJob
	 */
	private EudiStatusListRefreshJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->statusListService = $this->createMock(EudiStatusListService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new EudiStatusListRefreshJob($timeFactory, $this->statusListService, $this->logger);
	}//end setUp()

	/**
	 * The job wires its dependencies and constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(EudiStatusListRefreshJob::class, $this->job);
	}//end testConstructs()

	/**
	 * REQ-EUDI-008b: running the job invokes refreshNearExpiry.
	 *
	 * @return void
	 */
	public function testRunInvokesRefreshNearExpiry(): void {
		$this->statusListService->expects($this->once())
			->method('refreshNearExpiry')
			->willReturn(2);

		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($this->job, null);
	}//end testRunInvokesRefreshNearExpiry()

	/**
	 * A sweep exception is caught and logged, never rethrown (a single
	 * poisoned row must not wedge the cron pipeline).
	 *
	 * @return void
	 */
	public function testRunContainsExceptions(): void {
		$this->statusListService->method('refreshNearExpiry')
			->willThrowException(new \RuntimeException('poisoned status list row'));

		$this->logger->expects($this->once())->method('error');

		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);

		// Must not throw.
		$reflection->invoke($this->job, null);
		$this->assertTrue(true);
	}//end testRunContainsExceptions()
}//end class
