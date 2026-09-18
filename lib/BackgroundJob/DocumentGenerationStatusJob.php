<?php

/**
 * Integriq Document Generation Status Job.
 *
 * Asks the vendor again about every render that is not settled, so a job the
 * vendor went quiet on does not sit open forever.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Poll every unsettled render.
 *
 * This is what makes `unreachable` a state rather than a dead end: the job
 * stays open, this sweep asks again, and the render settles as soon as the
 * vendor is willing to say what happened to it.
 *
 * @psalm-api
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */
class DocumentGenerationStatusJob extends TimedJob {

	/**
	 * Sweep interval in seconds.
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 300;

	/**
	 * How many jobs one sweep polls.
	 *
	 * @var integer
	 */
	private const BATCH = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param ORObjectService $objectService Reads the unsettled jobs.
	 * @param DocumentGenerationService $service Polls one job.
	 * @param LoggerInterface $logger Logger for sweep outcomes.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ORObjectService $objectService,
		private readonly DocumentGenerationService $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

	}//end __construct()

	/**
	 * Poll every unsettled render.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is Nextcloud's TimedJob::run()
	 * signature; this job is scheduled, never given an argument.
	 */
	protected function run($argument): void {
		foreach (['queued', 'unreachable'] as $status) {
			$this->pollStatus(status: $status);
		}

	}//end run()

	/**
	 * Poll one batch of jobs in one status.
	 *
	 * @param string $status The status to sweep.
	 *
	 * @return void
	 */
	private function pollStatus(string $status): void {
		try {
			$matches = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => DocumentGenerationService::REGISTER,
						'schema' => DocumentGenerationService::SCHEMA_JOB,
						'status' => $status,
					],
					'limit' => self::BATCH,
				]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[integriq] the document generation sweep could not read its jobs',
				['status' => $status, 'exception' => $exception->getMessage()]
			);
			return;
		}//end try

		foreach (($matches['results'] ?? $matches) as $job) {
			try {
				$this->service->pollJob(job: $job);
			} catch (Throwable $exception) {
				// One vendor being difficult does not stop the sweep.
				$this->logger->warning(
					'[integriq] a document generation job could not be polled',
					['exception' => $exception->getMessage()]
				);
			}
		}

	}//end pollStatus()
}//end class
