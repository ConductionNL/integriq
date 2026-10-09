<?php

/**
 * Integriq OptOutLogRetentionJob.
 *
 * The opt-out decision log is kept seven years and then deleted (Ruben,
 * 2026-10-05). The opt-outs themselves are never deleted by age: an
 * objection holds until the person lifts it.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use DateTimeImmutable;
use OCA\Integriq\Db\OptOutLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes opt-out log entries older than seven years, once a day.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */
class OptOutLogRetentionJob extends TimedJob {

	/**
	 * How long a log entry is kept.
	 *
	 * @var string
	 */
	public const RETENTION = '-7 years';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param OptOutLogMapper $log The log table.
	 * @param LoggerInterface $logger Records what was deleted.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OptOutLogMapper $log,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);

	}//end __construct()

	/**
	 * The unix time before which entries go.
	 *
	 * @return int The cut-off.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function cutOff(): int {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->modify(self::RETENTION)->getTimestamp();

	}//end cutOff()

	/**
	 * Delete what is past retention.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's TimedJob::run() signature.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	protected function run($argument): void {
		try {
			$deleted = $this->log->deleteOlderThan(before: $this->cutOff());
		} catch (Throwable $exception) {
			$this->logger->warning('[OptOutLogRetentionJob] could not delete old entries: ' . $exception->getMessage());
			return;
		}

		if ($deleted > 0) {
			$this->logger->info('[OptOutLogRetentionJob] deleted ' . $deleted . ' opt-out log entries older than seven years');
		}

	}//end run()

}//end class
