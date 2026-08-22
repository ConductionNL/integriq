<?php

/**
 * Unit tests for DeferredViewCascadeJob (ADR-078 / gate-61).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Cron;

use OCA\Integriq\Cron\DeferredViewCascadeJob;
use OCA\Integriq\Service\SourceMappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The deferred half of the view-delete cascade.
 *
 * EVERY TEST HERE DRAINS THROUGH `ActorForwardedJob::run()`, NEVER THROUGH
 * `runDeferred()`. That is not a stylistic choice. `runDeferred()` is the
 * cascade with the actor guard NOT held — no identity re-established, no
 * "captured user is gone, refuse" check, no `finally` restore. It is the one
 * condition production never has, so a test that entered there would report
 * success for a job that, in cron, either runs as the wrong user or does not
 * run at all. `runVia()` below is the only entry point in this file.
 *
 * The listener half is covered by ViewDeletedEventListenerTest; the two files
 * meet at the entry array shape asserted in both.
 */
class DeferredViewCascadeJobTest extends TestCase {

	/**
	 * Ordered log of everything the job did, in the order it did it.
	 *
	 * Recorded rather than asserted per-call because ORDER is the property
	 * under test: a delete that happens before impersonation is a delete
	 * performed as the wrong user.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

	/**
	 * Reset the trace between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->trace = [];
	}//end setUp()

	/**
	 * Build an ObjectEntity carrying a uuid, as `findAll()` returns them.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);

		return $entity;
	}//end row()

	/**
	 * A user double whose uid is recorded when impersonated.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Assemble the job with a given OpenRegister service and session.
	 *
	 * @param ObjectService|null $openRegister  What getOpenRegisters() answers.
	 * @param IUserManager       $userManager   Resolver for the captured user id.
	 * @param IUserSession       $userSession   Session to impersonate on.
	 * @param LoggerInterface    $logger        The logger.
	 *
	 * @return DeferredViewCascadeJob
	 */
	private function job(
		?ObjectService $openRegister,
		IUserManager $userManager,
		IUserSession $userSession,
		LoggerInterface $logger
	): DeferredViewCascadeJob {
		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($openRegister);

		return new DeferredViewCascadeJob(
			$this->createMock(ITimeFactory::class),
			$userSession,
			$userManager,
			$this->createMock(OrganisationService::class),
			$logger,
			$sourceMapping
		);
	}//end job()

	/**
	 * Run the job the way cron does: through ActorForwardedJob::run().
	 *
	 * `run()` is protected on the OCP Job hierarchy (QueuedJob::start() is
	 * final and reaches it), so reflection is how a unit test enters it — the
	 * same shape EventRetryJobTest already uses. What matters is WHICH method:
	 * this is the base class's run(), so the entries decode, the actor is
	 * resolved, impersonation happens and the session is restored, exactly as
	 * in a cron worker.
	 *
	 * @param DeferredViewCascadeJob $job      The job under test.
	 * @param mixed                  $argument The raw `oc_jobs.argument` payload.
	 *
	 * @return void
	 */
	private function runVia(DeferredViewCascadeJob $job, mixed $argument): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, $argument);
	}//end runVia()

	/**
	 * Build the job argument exactly as ListenerDeferralService serialises it.
	 *
	 * @param string|null                      $userId  Captured acting user.
	 * @param array<int, array<string, mixed>> $entries Captured entries.
	 *
	 * @return array<string, mixed>
	 */
	private function argument(?string $userId, array $entries): array {
		return (new DeferredListenerContext(
			userId: $userId,
			orgUuid: null,
			entries: $entries
		))->toJobArguments();
	}//end argument()

	/**
	 * A session that records impersonation into the shared trace.
	 *
	 * @return IUserSession
	 */
	private function tracingSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->trace[] = 'setUser:' . ($user === null ? 'null' : $user->getUID());
			}
		);

		return $session;
	}//end tracingSession()

	/**
	 * An OpenRegister double that records findAll/deleteObject into the trace.
	 *
	 * `willReturnCallback` rather than `->with(...)`: the production call uses
	 * NAMED arguments, and a `with()` constraint on a mock whose stub signature
	 * carries defaults compares against those defaults for anything not named.
	 * Recording positionally sidesteps that entirely.
	 *
	 * @param array<int, ObjectEntity> $rows What findAll() answers.
	 *
	 * @return ObjectService
	 */
	private function tracingOpenRegister(array $rows): ObjectService {
		$openRegister = $this->createMock(ObjectService::class);

		$openRegister->method('findAll')->willReturnCallback(
			function (array $config = []) use ($rows): array {
				$this->trace[] = 'findAll:' . json_encode($config);

				return $rows;
			}
		);

		$openRegister->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null, string|int|null $register = null, string|int|null $schema = null): bool {
				$this->trace[] = 'deleteObject:' . $uuid . '@' . $register . '/' . $schema;

				return true;
			}
		);

		return $openRegister;
	}//end tracingOpenRegister()

	/**
	 * POSITIVE CONTROL — the cascade actually deletes, as the captured actor.
	 *
	 * This is the assertion the whole file exists for, and it is the one that
	 * FAILED against the code as merged: the job called `$openRegister->delete()`,
	 * which does not exist on OpenRegister's ObjectService, so the trace
	 * contained no `deleteObject:` entry at all — the cascade deleted nothing
	 * and logged a warning instead.
	 *
	 * @return void
	 */
	public function testCascadeDeletesEveryMatchingRowAsTheCapturedActor(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$job = $this->job(
			$this->tracingOpenRegister([$this->row('ev-1'), $this->row('ev-2')]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia(
			$job,
			$this->argument(
				'alice',
				[['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]]
			)
		);

		// Both rows were deleted, by uuid, scoped to the captured register and
		// schema.
		$this->assertContains('deleteObject:ev-1@7/42', $this->trace);
		$this->assertContains('deleteObject:ev-2@7/42', $this->trace);

		// And the guard was HELD while they were: impersonation first, both
		// deletes, restore last. An out-of-order trace means rows were removed
		// under whatever identity the cron worker happened to carry.
		$this->assertSame(
			'setUser:alice',
			$this->trace[0],
			'The actor must be re-established BEFORE any row is deleted.'
		);
		$this->assertSame(
			'setUser:null',
			end($this->trace),
			'The previous session user must be restored after the cascade.'
		);
	}//end testCascadeDeletesEveryMatchingRowAsTheCapturedActor()

	/**
	 * The lookup is scoped and bounded — it is never an unbounded findAll.
	 *
	 * ADR-078 fix action 2. The inline listener this job replaced ran an
	 * unbounded findAll() inside the user's delete request; dropping the cap
	 * while moving it to cron would reintroduce the full scan out of sight.
	 *
	 * @return void
	 */
	public function testLookupIsScopedToTheIdentifierAndBounded(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$job = $this->job(
			$this->tracingOpenRegister([]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia(
			$job,
			$this->argument(
				'alice',
				[['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]]
			)
		);

		$calls = array_values(array_filter(
			$this->trace,
			static fn (string $line): bool => str_starts_with($line, 'findAll:')
		));
		$this->assertCount(1, $calls);

		$config = json_decode(substr($calls[0], strlen('findAll:')), true);
		$this->assertSame(7, $config['filters']['register']);
		$this->assertSame(42, $config['filters']['schema']);
		$this->assertSame('gemma-view-1', $config['filters']['identifier']);
		$this->assertSame(DeferredViewCascadeJob::CASCADE_LIMIT, $config['limit']);
	}//end testLookupIsScopedToTheIdentifierAndBounded()

	/**
	 * A result at the row cap is reported — it means the data is not what the
	 * cascade assumes, and the remainder is silently left behind.
	 *
	 * @return void
	 */
	public function testHittingTheRowCapWarns(): void {
		$rows = [];
		for ($i = 0; $i < DeferredViewCascadeJob::CASCADE_LIMIT; $i++) {
			$rows[] = $this->row('ev-' . $i);
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = 'warning:' . $message;
			}
		);

		$job = $this->job($this->tracingOpenRegister($rows), $userManager, $this->tracingSession(), $logger);

		$this->runVia(
			$job,
			$this->argument('alice', [['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]])
		);

		$this->assertContains(
			'warning:OpenConnector: extended-view cascade hit its row cap — some rows may remain',
			$this->trace
		);
		// The capped batch is still deleted — a warning is not a refusal.
		$this->assertContains('deleteObject:ev-0@7/42', $this->trace);
	}//end testHittingTheRowCapWarns()

	/**
	 * OpenRegister gone between dispatch and run: nothing is deleted, and the
	 * reason is recorded rather than invented around.
	 *
	 * @return void
	 */
	public function testOpenRegisterUnavailableDeletesNothingAndWarns(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = 'warning:' . $message;
			}
		);

		$job = $this->job(null, $userManager, $this->tracingSession(), $logger);

		$this->runVia(
			$job,
			$this->argument('alice', [['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]])
		);

		$this->assertContains(
			'warning:OpenConnector: extended-view cascade skipped, OpenRegister object service unavailable',
			$this->trace
		);
		$this->assertSame(
			[],
			array_values(array_filter($this->trace, static fn (string $l): bool => str_starts_with($l, 'deleteObject:'))),
			'Nothing may be deleted when the object service is gone.'
		);
	}//end testOpenRegisterUnavailableDeletesNothingAndWarns()

	/**
	 * A lookup failure is contained to its own entry — the next entry still
	 * runs. At-least-once delivery means one poisoned row must not strand the
	 * rest of the batch.
	 *
	 * @return void
	 */
	public function testALookupFailureDoesNotStrandTheRestOfTheBatch(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = 'warning:' . $message;
			}
		);

		$openRegister = $this->createMock(ObjectService::class);
		$openRegister->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				if (($config['filters']['identifier'] ?? '') === 'boom') {
					throw new \RuntimeException('lookup exploded');
				}

				return [$this->row('ev-9')];
			}
		);
		$openRegister->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null, string|int|null $register = null, string|int|null $schema = null): bool {
				$this->trace[] = 'deleteObject:' . $uuid . '@' . $register . '/' . $schema;

				return true;
			}
		);

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($openRegister);

		$job = new DeferredViewCascadeJob(
			$this->createMock(ITimeFactory::class),
			$this->tracingSession(),
			$userManager,
			$this->createMock(OrganisationService::class),
			$logger,
			$sourceMapping
		);

		$this->runVia(
			$job,
			$this->argument(
				'alice',
				[
					['identifier' => 'boom', 'register' => 7, 'schema' => 42],
					['identifier' => 'gemma-view-2', 'register' => 7, 'schema' => 42],
				]
			)
		);

		$this->assertContains('warning:OpenConnector: extended-view cascade lookup failed', $this->trace);
		$this->assertContains('deleteObject:ev-9@7/42', $this->trace);
	}//end testALookupFailureDoesNotStrandTheRestOfTheBatch()

	/**
	 * A delete failure on one row is contained to that row.
	 *
	 * @return void
	 */
	public function testADeleteFailureOnOneRowDoesNotStopTheOthers(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = 'warning:' . $message;
			}
		);

		$openRegister = $this->createMock(ObjectService::class);
		$openRegister->method('findAll')->willReturn([$this->row('ev-bad'), $this->row('ev-good')]);
		$openRegister->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null, string|int|null $register = null, string|int|null $schema = null): bool {
				if ($uuid === 'ev-bad') {
					throw new \RuntimeException('row is locked');
				}

				$this->trace[] = 'deleteObject:' . $uuid . '@' . $register . '/' . $schema;

				return true;
			}
		);

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($openRegister);

		$job = new DeferredViewCascadeJob(
			$this->createMock(ITimeFactory::class),
			$this->tracingSession(),
			$userManager,
			$this->createMock(OrganisationService::class),
			$logger,
			$sourceMapping
		);

		$this->runVia(
			$job,
			$this->argument('alice', [['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]])
		);

		$this->assertContains(
			'warning:OpenConnector: failed to delete an extended view during cascade',
			$this->trace
		);
		$this->assertContains('deleteObject:ev-good@7/42', $this->trace);
		// And the session was still restored despite the throw.
		$this->assertSame('setUser:null', end($this->trace));
	}//end testADeleteFailureOnOneRowDoesNotStopTheOthers()

	/**
	 * A row with no usable uuid is skipped rather than passed on as null.
	 *
	 * @return void
	 */
	public function testARowWithoutAUuidIsSkipped(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$job = $this->job(
			$this->tracingOpenRegister([$this->row(''), $this->row('ev-real')]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia(
			$job,
			$this->argument('alice', [['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]])
		);

		$deletes = array_values(array_filter(
			$this->trace,
			static fn (string $l): bool => str_starts_with($l, 'deleteObject:')
		));
		$this->assertSame(['deleteObject:ev-real@7/42'], $deletes);
	}//end testARowWithoutAUuidIsSkipped()

	/**
	 * A malformed entry is dropped without a lookup.
	 *
	 * @param array<string, mixed> $entry The malformed entry.
	 *
	 * @return void
	 *
	 * @dataProvider malformedEntries
	 */
	public function testMalformedEntriesNeverReachTheObjectService(array $entry): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$job = $this->job(
			$this->tracingOpenRegister([$this->row('ev-1')]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia($job, $this->argument('alice', [$entry]));

		$this->assertSame(
			[],
			array_values(array_filter(
				$this->trace,
				static fn (string $l): bool => str_starts_with($l, 'findAll:')
			))
		);
	}//end testMalformedEntriesNeverReachTheObjectService()

	/**
	 * Entry shapes that must not produce a query.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformedEntries(): array {
		return [
			'no identifier' => [['register' => 7, 'schema' => 42]],
			'empty identifier' => [['identifier' => '', 'register' => 7, 'schema' => 42]],
			'non-string identifier' => [['identifier' => 12, 'register' => 7, 'schema' => 42]],
			'no register' => [['identifier' => 'gemma-view-1', 'schema' => 42]],
			'no schema' => [['identifier' => 'gemma-view-1', 'register' => 7]],
		];
	}//end malformedEntries()

	/**
	 * A poisoned entry that survives the guards degrades to a logged no-op,
	 * it does not throw out of the job.
	 *
	 * `DeferredListenerContext` is deliberately tolerant of a malformed
	 * `oc_jobs.argument` "so a poisoned job row degrades to a logged no-op
	 * instead of a crash loop in cron". A non-scalar `register` passes the
	 * entry guard (it is not null) and reaches the delete call, so the
	 * containment has to hold there. Typing the batch helper `string|int`
	 * moves that failure to a TypeError BEFORE the try/catch and the job
	 * throws — which is what this test refuses.
	 *
	 * @return void
	 */
	public function testANonScalarRegisterIsContainedRatherThanThrown(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->user('alice'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->trace[] = 'warning:' . $message;
			}
		);

		$openRegister = $this->createMock(ObjectService::class);
		$openRegister->method('findAll')->willReturn([$this->row('ev-1')]);
		$openRegister->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null, string|int|null $register = null, string|int|null $schema = null): bool {
				$this->trace[] = 'deleteObject:' . $uuid;

				return true;
			}
		);

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($openRegister);

		$job = new DeferredViewCascadeJob(
			$this->createMock(ITimeFactory::class),
			$this->tracingSession(),
			$userManager,
			$this->createMock(OrganisationService::class),
			$logger,
			$sourceMapping
		);

		$this->runVia(
			$job,
			$this->argument(
				'alice',
				[['identifier' => 'gemma-view-1', 'register' => ['not', 'a', 'scalar'], 'schema' => 42]]
			)
		);

		$this->assertContains(
			'warning:OpenConnector: failed to delete an extended view during cascade',
			$this->trace
		);
		// And the session was still restored — the job completed normally.
		$this->assertSame('setUser:null', end($this->trace));
	}//end testANonScalarRegisterIsContainedRatherThanThrown()

	/**
	 * GUARD TEST — the captured actor no longer resolves, so the job REFUSES.
	 *
	 * This is the behaviour that only exists on the ActorForwardedJob path. A
	 * test entering at runDeferred() would delete these rows under the cron
	 * worker's identity and pass.
	 *
	 * @return void
	 */
	public function testAnUnresolvableActorDeletesNothing(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn(null);

		$job = $this->job(
			$this->tracingOpenRegister([$this->row('ev-1')]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia(
			$job,
			$this->argument('ghost', [['identifier' => 'gemma-view-1', 'register' => 7, 'schema' => 42]])
		);

		$this->assertSame([], $this->trace, 'A job whose actor is gone must do nothing at all.');
	}//end testAnUnresolvableActorDeletesNothing()

	/**
	 * GUARD TEST — an argument carrying no entries does no work.
	 *
	 * @return void
	 */
	public function testAnEmptyArgumentDoesNoWork(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('get');

		$job = $this->job(
			$this->tracingOpenRegister([$this->row('ev-1')]),
			$userManager,
			$this->tracingSession(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runVia($job, $this->argument('alice', []));

		$this->assertSame([], $this->trace);
	}//end testAnEmptyArgumentDoesNoWork()
}//end class
