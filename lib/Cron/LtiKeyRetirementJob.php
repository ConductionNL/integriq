<?php

/**
 * Integriq LTI Key Retirement Job.
 *
 * Background job that sweeps every `lti_platform`/`lti_tool` registration and
 * flips `previous` signing keys whose 7-day rotation grace window has
 * elapsed to `retired`, dropping them from the published JWKS
 * (REQ-LTI-002). Without this job a rotated `previous` key would remain
 * published forever.
 *
 * @category Cron
 * @package  OCA\Integriq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Cron;

use OCA\Integriq\Service\Lti\LtiKeyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retires grace-window-expired LTI signing keys.
 *
 * Mirrors {@see \OCA\Integriq\Cron\EventRetryJob}'s cron-registration
 * pattern (design.md, task 2.1).
 *
 * @psalm-api
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */
class LtiKeyRetirementJob extends TimedJob {

	/**
	 * Sweep interval in seconds (1 hour — the grace window is 7 days, so
	 * hourly resolution is more than sufficient).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param LtiKeyService $keyService The LTI key lifecycle service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly LtiKeyService $keyService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the retirement sweep.
	 *
	 * A single poisoned registration must never wedge the cron pipeline, so
	 * any exception from the sweep is caught and logged rather than rethrown.
	 *
	 * @param mixed $argument Task arguments (not used).
	 *
	 * @return void
	 *
	 * @psalm-param   mixed $argument
	 * @phpstan-param mixed $argument
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	public function run(mixed $argument): void {
		try {
			$retired = $this->keyService->retireExpiredKeys();
			$this->logger->info('LtiKeyRetirementJob: retirement sweep complete', ['retired' => $retired]);
		} catch (Throwable $e) {
			$this->logger->error('LtiKeyRetirementJob: retirement sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
