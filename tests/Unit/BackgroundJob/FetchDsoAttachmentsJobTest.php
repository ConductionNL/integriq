<?php

/**
 * Tests for FetchDsoAttachmentsJob.
 *
 * The job runs under cron, where nobody is logged in. It must act as the
 * account that stored the verzoek: the `actingUserId` it was queued with, or
 * for a job queued before that existed, the account of the request's
 * dso-stam consumer. Never as no user, never as the system.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\FetchDsoAttachmentsJob;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Tests\Helpers\DsoConnectionWorld;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job resolves its account, runs as it, and refuses without one.
 */
class FetchDsoAttachmentsJobTest extends TestCase {
	use DsoConnectionWorld;

	/**
	 * The uid active each time fetchPending() ran.
	 *
	 * @var list<string|null>
	 */
	private array $fetchedAs = [];

	/**
	 * Reasons alerted.
	 *
	 * @var list<string>
	 */
	private array $alerted = [];

	/**
	 * Set up a world with the account dso-intake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetWorld();
		$this->addAccount(uid: 'dso-intake');
		$this->fetchedAs = [];
		$this->alerted = [];
	}//end setUp()

	/**
	 * A logger that keeps each level and message.
	 *
	 * @return AbstractLogger
	 */
	private function levelLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * The records.
			 *
			 * @var list<array{level: string, message: string}>
			 */
			public array $records = [];

			/**
			 * Record a log call.
			 *
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = ['level' => (string)$level, 'message' => (string)$message . ' ' . json_encode($context)];
			}
		};
	}//end levelLogger()

	/**
	 * Run the protected run() method.
	 *
	 * @param FetchDsoAttachmentsJob $job      The job.
	 * @param mixed                  $argument The queued argument.
	 *
	 * @return void
	 */
	private function runJob(FetchDsoAttachmentsJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);

	}//end runJob()

	/**
	 * A fetcher that records who it ran as.
	 *
	 * @param \Throwable|null $throw Throw this from fetchPending().
	 *
	 * @return DsoAttachmentFetcher
	 */
	private function fetcher(?\Throwable $throw = null): DsoAttachmentFetcher {
		$fetcher = $this->getMockBuilder(DsoAttachmentFetcher::class)->disableOriginalConstructor()->getMock();
		$fetcher->method('fetchPending')->willReturnCallback(
			function (string $requestUuid) use ($throw): array {
				$this->fetchedAs[] = $this->worldSession->getUser()?->getUID();
				if ($throw !== null) {
					throw $throw;
				}

				return [];
			}
		);

		return $fetcher;
	}//end fetcher()

	/**
	 * The job over the world.
	 *
	 * @param DsoAttachmentFetcher $fetcher The fetcher.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return FetchDsoAttachmentsJob
	 */
	private function job(DsoAttachmentFetcher $fetcher, ?LoggerInterface $logger = null): FetchDsoAttachmentsJob {
		$alerts = $this->createMock(DsoConnectionAlerts::class);
		$alerts->method('notify')->willReturnCallback(
			function (string $reason): bool {
				$this->alerted[] = $reason;
				return true;
			}
		);

		return new FetchDsoAttachmentsJob(
			time: $this->createMock(ITimeFactory::class),
			fetcher: $fetcher,
			connection: $this->buildWorldConnection(objectService: $this->buildWorldObjectService()),
			alerts: $alerts,
			logger: ($logger ?? $this->levelLogger())
		);
	}//end job()

	/**
	 * The job fetches as the account it was queued with, and restores nobody after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-the-attachment-job-runs-as-the-account-that-stored-the-verzoek
	 */
	public function testRunFetchesAsTheQueuedAccount(): void {
		$job = $this->job(fetcher: $this->fetcher());

		$this->assertInstanceOf(QueuedJob::class, $job);
		$this->runJob($job, ['requestUuid' => 'verzoek-1', 'actingUserId' => 'dso-intake']);

		$this->assertSame(['dso-intake'], $this->fetchedAs);
		$this->assertNull($this->worldSession->getUser());

	}//end testRunFetchesAsTheQueuedAccount()

	/**
	 * The queued uid wins over the consumer's current account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function testTheQueuedAccountWinsOverTheConsumersCurrentOne(): void {
		$this->addAccount(uid: 'newer-account');
		$this->addDsoConsumer(userId: 'newer-account');

		$this->runJob($this->job(fetcher: $this->fetcher()), ['requestUuid' => 'verzoek-1', 'actingUserId' => 'dso-intake']);

		$this->assertSame(['dso-intake'], $this->fetchedAs);

	}//end testTheQueuedAccountWinsOverTheConsumersCurrentOne()

	/**
	 * A job queued before actingUserId existed resolves the uid from the consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function testALegacyJobActsAsTheConsumersAccount(): void {
		$this->addDsoConsumer(userId: 'dso-intake');

		$this->runJob($this->job(fetcher: $this->fetcher()), ['requestUuid' => 'verzoek-1']);

		$this->assertSame(['dso-intake'], $this->fetchedAs);

	}//end testALegacyJobActsAsTheConsumersAccount()

	/**
	 * A vanished or disabled account: nothing fetched, an error naming verzoek
	 * and account, the admins alerted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-the-attachment-job-refuses-a-vanished-account
	 */
	public function testAVanishedOrDisabledAccountFetchesNothing(): void {
		$this->addAccount(uid: 'disabled', enabled: false);
		$logger = $this->levelLogger();
		$job = $this->job(fetcher: $this->fetcher(), logger: $logger);

		$this->runJob($job, ['requestUuid' => 'verzoek-1', 'actingUserId' => 'deleted-account']);
		$this->runJob($job, ['requestUuid' => 'verzoek-2', 'actingUserId' => 'disabled']);

		$this->assertSame([], $this->fetchedAs);
		$this->assertSame([], $this->worldWrites);
		$this->assertSame(['job_account_unavailable', 'job_account_unavailable'], $this->alerted);
		$errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
		$this->assertCount(2, $errors);
		$this->assertStringContainsString('verzoek-1', $errors[0]['message']);
		$this->assertStringContainsString('deleted-account', $errors[0]['message']);

	}//end testAVanishedOrDisabledAccountFetchesNothing()

	/**
	 * A legacy job with no consumer to resolve from never runs as no user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function testALegacyJobWithoutAConsumerFetchesNothing(): void {
		$this->runJob($this->job(fetcher: $this->fetcher()), ['requestUuid' => 'verzoek-1']);

		$this->assertSame([], $this->fetchedAs);
		$this->assertSame(['job_account_unavailable'], $this->alerted);

	}//end testALegacyJobWithoutAConsumerFetchesNothing()

	/**
	 * A malformed argument is dropped with a warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
	 */
	public function testMalformedArgumentIsDropped(): void {
		$logger = $this->levelLogger();
		$job = $this->job(fetcher: $this->fetcher(), logger: $logger);

		$this->runJob($job, 'verzoek-1');
		$this->runJob($job, ['requestUuid' => '']);

		$this->assertSame([], $this->fetchedAs);
		$this->assertCount(2, array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning'));

	}//end testMalformedArgumentIsDropped()

	/**
	 * A fetch that throws is logged, not thrown, and the identity is restored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-3
	 */
	public function testFailureIsLoggedNotThrownAndTheIdentityRestored(): void {
		$logger = $this->levelLogger();
		$job = $this->job(fetcher: $this->fetcher(throw: new RuntimeException('store unavailable')), logger: $logger);

		$this->runJob($job, ['requestUuid' => 'verzoek-1', 'actingUserId' => 'dso-intake']);

		$this->assertSame(['dso-intake'], $this->fetchedAs);
		$this->assertNull($this->worldSession->getUser());
		$errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
		$this->assertStringContainsString('store unavailable', $errors[0]['message']);

	}//end testFailureIsLoggedNotThrownAndTheIdentityRestored()
}//end class
