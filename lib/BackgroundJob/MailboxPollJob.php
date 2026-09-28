<?php

/**
 * Integriq Mailbox Poll Job.
 *
 * Polls every enabled mailbox source every five minutes, so mail reaches
 * integriq without an administrator pressing "poll now". The scheduled half of
 * mail-intake-creates-cases REQ-MAIL-001: before this job, the only caller of
 * MailboxSourceHandler::poll() was MailIntakeController::poll().
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that polls the enabled mailbox sources.
 *
 * @psalm-api
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 */
class MailboxPollJob extends TimedJob {

	/**
	 * Poll interval in seconds (five minutes).
	 *
	 * @var integer
	 */
	private const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    Time factory for job scheduling.
	 * @param MailboxSourceHandler $handler Polls one or all mailbox sources.
	 * @param LoggerInterface      $logger  Logs the sweep outcome.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MailboxSourceHandler $handler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::INTERVAL);

		// Two sweeps at once would read the same cursor and fetch twice.
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Poll every enabled mailbox source.
	 *
	 * @param mixed $argument Not used.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
	 */
	public function run(mixed $argument): void {
		try {
			$summary = $this->handler->pollAll();
			$this->logger->info('MailboxPollJob: mailbox sweep complete', $summary);
		} catch (Throwable $exception) {
			$this->logger->error(
				'MailboxPollJob: mailbox sweep failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end run()
}//end class
