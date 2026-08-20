<?php

/**
 * OpenConnector EUDI Status List Refresh Job.
 *
 * Background job that sweeps every `eudi_status_list` row and re-signs any
 * published OAuth Status List Token nearing its own `exp`, so
 * `GET /api/eudi/status-lists/{id}` never serves a token whose signature has
 * expired even though the bitstring contents rarely change.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\EudiStatusListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically re-signs near-expiry EUDI status list tokens.
 *
 * Mirrors {@see \OCA\OpenConnector\Cron\EventRetryJob}'s `TimedJob` shape
 * (design.md D-REVOKE / tasks.md "Status list refresh cron").
 *
 * @psalm-api
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
 */
class EudiStatusListRefreshJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (15 minutes) — the token's total
	 * validity window is 24h and the refresh threshold is 25% of it (6h),
	 * so a 15-minute sweep interval comfortably re-signs well before expiry.
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 900;

	/**
	 * EudiStatusListRefreshJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param EudiStatusListService $statusListService The status-list refresh sweep.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EudiStatusListService $statusListService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);

		// Status-list refresh is not strictly time-sensitive (tokens are
		// refreshed well ahead of their own exp).
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only one sweep at a time to avoid double-signing the same row.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the refresh sweep.
	 *
	 * A single poisoned row must never wedge the cron pipeline, so any
	 * exception from the sweep is caught and logged rather than rethrown
	 * (mirrors {@see \OCA\OpenConnector\Cron\EventRetryJob::run()}).
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
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-refresh-keeps-the-published-token-ahead-of-its-own-expiry-req-eudi-008b
	 */
	public function run(mixed $argument): void {
		try {
			$refreshed = $this->statusListService->refreshNearExpiry();
			$this->logger->info(
				'EudiStatusListRefreshJob: refresh sweep complete',
				['refreshed' => $refreshed]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'EudiStatusListRefreshJob: refresh sweep failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
