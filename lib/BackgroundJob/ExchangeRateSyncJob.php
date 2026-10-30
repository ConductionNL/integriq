<?php

/**
 * Integriq ExchangeRateSyncJob.
 *
 * Stores the ECB euro reference rates as `exchange_rate` objects.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\ExchangeRate\ExchangeRateImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the ECB rate import every six hours.
 *
 * The ECB publishes once per working day around 16:00 CET. Running four
 * times a day picks the new file up the same day; a run before publication
 * finds every rate already stored and stores nothing.
 *
 * @psalm-api
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class ExchangeRateSyncJob extends TimedJob {

	/**
	 * Run interval in seconds (six hours).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 21600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param ExchangeRateImportService $importService The rate import.
	 * @param LoggerInterface $logger Logger for run outcomes.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExchangeRateImportService $importService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run one import; a failure is logged and keeps the stored rates.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's job signature; this job takes none.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
	 */
	protected function run($argument): void {
		try {
			$result = $this->importService->importFromSource();
		} catch (Throwable $exception) {
			$this->logger->error(
				'[ExchangeRateSyncJob] ECB rate import failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return;
		}

		$this->logger->info(
			'[ExchangeRateSyncJob] ECB rate import ' . $result['status'] . ': ' . $result['stored'] . ' rates stored',
			['dates' => $result['dates']]
		);

	}//end run()
}//end class
