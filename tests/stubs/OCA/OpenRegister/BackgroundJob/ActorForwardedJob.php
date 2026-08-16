<?php

/**
 * Stub for OCA\OpenRegister\BackgroundJob\ActorForwardedJob.
 *
 * THE BODY OF run() IS MIRRORED FROM THE REAL CLASS, NOT SUMMARISED.
 * (openregister/lib/BackgroundJob/ActorForwardedJob.php on origin/development.)
 *
 * This is the whole point of the stub. A deferred job's guarantees do not live
 * in its own runDeferred() — they live here: the empty-entries short circuit,
 * the "captured user no longer resolves, so SKIP rather than run under the
 * worker's identity" refusal, the impersonation, and the `finally` that
 * restores the previous session user even when the deferred work throws. A test
 * that reaches runDeferred() directly runs the work with none of that held,
 * which is the one condition production never has.
 *
 * KNOWN, DELIBERATE DEGRADATION: logOrganisationDrift() calls
 * OrganisationService::getActiveOrganisation(). The stub for that service
 * returns `?object` rather than the real `?Organisation`, because the
 * Organisation entity is not stubbed in this repository. The drift log is
 * observability only — it can never change what the job does — and it is
 * OpenRegister's behaviour, not OpenConnector's, so it is not under test here.
 * Its own `catch (\Throwable) { return; }` is mirrored faithfully.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Base class for deferred listener jobs that must run as the original actor.
 */
abstract class ActorForwardedJob extends QueuedJob {

	/**
	 * Wire the identity plumbing shared by all actor-forwarded jobs.
	 *
	 * @param ITimeFactory        $time         Time factory for the parent job class.
	 * @param IUserSession        $userSession  Session to impersonate on / restore.
	 * @param IUserManager        $userManager  Resolver for the captured user id.
	 * @param OrganisationService $organisation Active-organisation resolver (drift detection).
	 * @param LoggerInterface     $logger       PSR logger (shared with subclasses).
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly OrganisationService $organisation,
		protected readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}

	/**
	 * Re-establish the captured actor, run the deferred work, restore.
	 *
	 * @param array<string, mixed> $argument Serialized DeferredListenerContext.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		$context = DeferredListenerContext::fromJobArguments($argument);
		if (count($context->getEntries()) === 0) {
			$this->logger->info(
				message: '[ActorForwardedJob] Job carried no entries — nothing to do',
				context: ['file' => __FILE__, 'line' => __LINE__, 'job' => static::class]
			);
			return;
		}

		$userId = $context->getUserId();
		$user = null;
		if ($userId !== null) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				// The captured actor no longer exists. Running anyway would
				// misattribute the work to whatever identity the worker has,
				// so the job skips.
				$this->logger->warning(
					message: '[ActorForwardedJob] Captured user no longer resolves — skipping deferred work',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'job' => static::class,
						'userId' => $userId,
					]
				);
				return;
			}
		}

		$previousUser = $this->userSession->getUser();
		if ($user !== null) {
			$this->userSession->setUser($user);
		}

		try {
			$this->logOrganisationDrift(context: $context);
			$this->runDeferred(context: $context);
		} finally {
			// ALWAYS restore — also on exceptions — so the cron process never
			// carries this job's identity into the next job.
			$this->userSession->setUser($previousUser);
		}
	}

	/**
	 * The deferred listener work, executed under the re-established actor.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 */
	abstract protected function runDeferred(DeferredListenerContext $context): void;

	/**
	 * Log when the user's active organisation changed since capture.
	 *
	 * @param DeferredListenerContext $context The captured context.
	 *
	 * @return void
	 */
	private function logOrganisationDrift(DeferredListenerContext $context): void {
		$captured = $context->getOrganisationUuid();
		if ($captured === null || $context->getUserId() === null) {
			return;
		}

		try {
			$current = $this->organisation->getActiveOrganisation()?->getUuid();
		} catch (\Throwable $e) {
			return;
		}

		if ($current !== $captured) {
			$this->logger->info(
				message: '[ActorForwardedJob] Active organisation drifted since capture — running under current authority',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'job' => static::class,
					'captured' => $captured,
					'current' => $current,
				]
			);
		}
	}
}
