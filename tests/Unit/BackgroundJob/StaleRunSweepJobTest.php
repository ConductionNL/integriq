<?php

/**
 * Unit tests for StaleRunSweepJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/job-scheduling/spec.md#requirement-abandoned-synchronization-runs-are-swept-to-a-terminal-state-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\StaleRunSweepJob;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the abandoned synchronisation-run sweep.
 *
 * WHAT THESE PIN, AND WHY EACH ONE MATTERS
 *
 * The job closes synchronisation-run records that stopped reporting progress.
 * Every one of its decisions is destructive in one direction and invisible in
 * the other: closing a LIVE run marks a working synchronisation as failed,
 * while failing to close a dead one leaves a run displayed as active forever.
 * Neither shows up as an error, so both are pinned here.
 *
 * The arm that earns its place is `testARunWithNoUsableTimestampIsNotClosed`.
 * "No timestamp" is the one input where closing would be an unforced error —
 * the job cannot know the run is dead, so it must leave it alone. A version
 * that treated a missing date as "old enough" would pass every other arm.
 *
 * @spec openspec/specs/job-scheduling/spec.md#requirement-abandoned-synchronization-runs-are-swept-to-a-terminal-state-req-006
 */
final class StaleRunSweepJobTest extends TestCase {

	/**
	 * Build the job with mocked collaborators.
	 *
	 * @param OrObjectService $objects The mocked object service.
	 * @param LoggerInterface $logger  The mocked logger.
	 *
	 * @return StaleRunSweepJob The job under test.
	 */
	private function makeJob(OrObjectService $objects, LoggerInterface $logger): StaleRunSweepJob {
		$time = $this->createMock(ITimeFactory::class);

		return new StaleRunSweepJob($time, $objects, $logger);

	}//end makeJob()

	/**
	 * Invoke the protected run().
	 *
	 * @param StaleRunSweepJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(StaleRunSweepJob $job): void {
		$reflection = new \ReflectionMethod($job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($job, null);

	}//end invokeRun()

	/**
	 * A run entity stub exposing the three accessors the job uses.
	 *
	 * @param array       $object  The stored object payload.
	 * @param string      $uuid    The entity uuid.
	 * @param string|null $created The creation timestamp.
	 *
	 * @return object The stub.
	 */
	private function runEntity(array $object, string $uuid = 'run-1', ?string $created = null): object {
		return new class($object, $uuid, $created) {

			/**
			 * @param array       $object  Payload.
			 * @param string      $uuid    Uuid.
			 * @param string|null $created Created timestamp.
			 */
			public function __construct(
				private array $object,
				private string $uuid,
				private ?string $created,
			) {
			}

			/**
			 * @return array The payload.
			 */
			public function getObject(): array {
				return $this->object;
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * @return string|null The created timestamp.
			 */
			public function getCreated(): ?string {
				return $this->created;
			}
		};

	}//end runEntity()

	/**
	 * A read failure is logged and returns — it never escapes the job.
	 *
	 * A background job that throws takes the cron tick with it, so every other
	 * job queued behind it stops too. The read is the one call most likely to
	 * fail on a degraded instance, which is why it is the arm listed first.
	 *
	 * @return void
	 */
	public function testAReadFailureIsSwallowedAndLogged(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database is gone'));
		$objects->expects($this->never())->method('saveObject');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->invokeRun($this->makeJob($objects, $logger));

	}//end testAReadFailureIsSwallowedAndLogged()

	/**
	 * A run that reported progress recently is left alone.
	 *
	 * @return void
	 */
	public function testARecentRunIsNotClosed(): void {
		$recent = date('c', (time() - 60));

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			['results' => [$this->runEntity(['status' => 'running', 'updatedAt' => $recent])]]
		);
		$objects->expects($this->never())->method('saveObject');

		$this->invokeRun($this->makeJob($objects, $this->createMock(LoggerInterface::class)));

	}//end testARecentRunIsNotClosed()

	/**
	 * A run past the staleness window is closed as failed, and says who closed it.
	 *
	 * The message assertion is not decoration. The counters on an abandoned run
	 * are the last ones observed, not a final tally, so a reader who cannot tell
	 * a swept record from a self-reported failure will read those numbers as the
	 * run's outcome.
	 *
	 * @return void
	 */
	public function testAStaleRunIsClosedAsFailed(): void {
		$old = date('c', (time() - (StaleRunSweepJob::STALE_AFTER_SECONDS + 60)));

		$saved = null;
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			['results' => [$this->runEntity(['status' => 'running', 'updatedAt' => $old])]]
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved) {
				$saved = ($args[0] ?? null);
				return null;
			}
		);

		$this->invokeRun($this->makeJob($objects, $this->createMock(LoggerInterface::class)));

		$this->assertIsArray($saved, 'a stale run must be written back');
		$this->assertSame('failed', $saved['status']);
		$this->assertNotEmpty($saved['finishedAt'] ?? null);
		$this->assertStringContainsString(
			'StaleRunSweepJob',
			($saved['message'] ?? ''),
			'the record must say it was closed by the sweep rather than by the run itself'
		);

	}//end testAStaleRunIsClosedAsFailed()

	/**
	 * A run with no usable timestamp is NOT closed.
	 *
	 * THE ARM THAT EARNS ITS PLACE. With no date the job cannot know the run is
	 * dead, and closing it would mark a possibly-live synchronisation as failed
	 * on no evidence. A version treating a missing timestamp as "old enough"
	 * passes every other arm in this file.
	 *
	 * @return void
	 */
	public function testARunWithNoUsableTimestampIsNotClosed(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			['results' => [$this->runEntity(['status' => 'running'], 'run-no-date', null)]]
		);
		$objects->expects($this->never())->method('saveObject');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->invokeRun($this->makeJob($objects, $logger));

	}//end testARunWithNoUsableTimestampIsNotClosed()

	/**
	 * An empty result set does no work and does not fail.
	 *
	 * @return void
	 */
	public function testNoRunningRunsIsANoOp(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(['results' => []]);
		$objects->expects($this->never())->method('saveObject');

		$this->invokeRun($this->makeJob($objects, $this->createMock(LoggerInterface::class)));

	}//end testNoRunningRunsIsANoOp()
	/**
	 * Every sweep decision matches the golden file recorded before run() was
	 * split: which timestamp is read, which runs are closed, what a closed run
	 * is saved as, and what is logged.
	 *
	 * Regenerate with STALE_SWEEP_GOLDEN=1.
	 *
	 * @return void
	 */
	public function testEverySweepDecisionMatchesTheGoldenFile(): void {
		$old = gmdate('c', (time() - 7200));
		$recent = gmdate('c', (time() - 60));
		$runs = [
			$this->runEntity(['status' => 'running', 'updatedAt' => $recent, 'n' => 1], 'recent-updated'),
			$this->runEntity(['status' => 'running', 'updatedAt' => $old, 'n' => 2], 'stale-updated'),
			$this->runEntity(['status' => 'running', 'startedAt' => $old], 'stale-started'),
			$this->runEntity(['status' => 'running', 'updatedAt' => $recent, 'startedAt' => $old], 'updated-wins'),
			$this->runEntity(['status' => 'running', 'updatedAt' => 'not a date'], 'garbage-created-stale', $old),
			$this->runEntity(['status' => 'running'], 'none-created-recent', $recent),
			$this->runEntity(['status' => 'running'], 'nothing-usable'),
			$this->runEntity(['status' => 'running', 'updatedAt' => (time() - 7200)], 'int-stale'),
			$this->runEntity(['status' => 'running', 'updatedAt' => (time() - 1700)], 'just-under'),
			$this->runEntity(['status' => 'running', 'updatedAt' => $old], 'save-fails'),
		];

		$actual = [];
		foreach (['paged' => ['results' => $runs], 'bare' => $runs, 'read-fails' => 'throw', 'empty' => ['results' => []]] as $shape => $answer) {
			$saved = [];
			$logged = [];
			$objects = $this->createMock(OrObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				function (array $config = [], bool $rbac = true, bool $multitenancy = true) use ($answer, &$logged) {
					$logged[] = ['findAll', $config, $rbac, $multitenancy];
					if ($answer === 'throw') {
						throw new \RuntimeException('db down');
					}

					return $answer;
				}
			);
			$objects->method('saveObject')->willReturnCallback(
				function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $rbac = true, bool $multitenancy = true, bool $silent = false, bool $validation = true) use (&$saved) {
					if ($uuid === 'save-fails') {
						throw new \RuntimeException('write refused');
					}

					foreach (['finishedAt', 'updatedAt', 'startedAt'] as $clock) {
						if (isset($object[$clock]) === true) {
							$object[$clock] = (is_int($object[$clock]) === true ? '(int)' : '(set)');
						}
					}

					$saved[] = ['uuid' => $uuid, 'object' => $object, 'register' => $register, 'schema' => $schema, 'flags' => [$rbac, $multitenancy, $silent, $validation]];
					return new \OCA\OpenRegister\Db\ObjectEntity();
				}
			);
			$logger = $this->createMock(LoggerInterface::class);
			foreach (['warning', 'info'] as $level) {
				$logger->method($level)->willReturnCallback(
					function (string $message) use ($level, &$logged) {
						$logged[] = [$level, $message];
					}
				);
			}

			$this->invokeRun($this->makeJob($objects, $logger));
			$actual[$shape] = ['saved' => $saved, 'logged' => $logged];
		}//end foreach

		$golden = __DIR__ . '/../../fixtures/backgroundjob/stale-run-sweep.json';
		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('STALE_SWEEP_GOLDEN') === '1') {
			if (is_dir(dirname($golden)) === false) {
				mkdir(dirname($golden), 0777, true);
			}

			file_put_contents($golden, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents($golden), true), json_decode($encoded, true));
	}//end testEverySweepDecisionMatchesTheGoldenFile()
}//end class
