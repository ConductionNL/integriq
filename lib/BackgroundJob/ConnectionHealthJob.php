<?php

/**
 * Integriq ConnectionHealthJob.
 *
 * Every hour (umbrella design D7):
 *
 *   1. syncs the declarations of apps whose version moved (D5);
 *   2. probes at most 25 linked sources, oldest probe first. An open circuit
 *      breaker is recorded as an error without a call;
 *   3. resolves every row again, linked or not, so a disabled app or a key
 *      set with `occ config:app:set` shows within the hour. This phase makes
 *      no outbound call and has no cap.
 *
 * Each phase is caught on its own, so a failing sync does not stop the probes,
 * and failing probes do not stop the resolve.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\ConnectionProbeService;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hourly connection health check.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */
class ConnectionHealthJob extends TimedJob {

	/**
	 * The job interval in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param ContainerInterface $container Resolves the services when the job runs, so
	 *                                      a missing OpenRegister cannot break job construction.
	 * @param LoggerInterface $logger Logs a failed phase.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the three phases.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's own
	 *   TimedJob::run() signature; this job takes no argument.
	 */
	protected function run($argument): void {
		$this->phase(
			name: 'declaration sync',
			operation: fn () => $this->container->get(ConnectionRegistryService::class)->syncChangedDeclarations()
		);
		$this->phase(
			name: 'source probes',
			operation: fn () => $this->container->get(ConnectionProbeService::class)->probeDue(limit: ConnectionProbeService::PROBE_LIMIT)
		);
		$this->phase(
			name: 'status refresh',
			operation: fn () => $this->container->get(ConnectionRegistryService::class)->refresh()
		);
	}//end run()

	/**
	 * Run one phase and log instead of throwing.
	 *
	 * @param string $name The phase name for the log.
	 * @param callable $operation The phase.
	 *
	 * @return void
	 */
	private function phase(string $name, callable $operation): void {
		try {
			$operation();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Integriq connection health job: the {phase} failed: {reason}',
				['app' => 'integriq', 'phase' => $name, 'reason' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end phase()
}//end class
