<?php

/**
 * Integriq ConnectionStatusReported EventListener.
 *
 * Receives an app's {@see ConnectionStatusReportedEvent}, writes it to the
 * connection row's `lastReport` and resolves the row. It never throws into the
 * sender: a failed status write must not turn a passing connection test in the
 * sending app into a 500.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes a reported status to its connection row.
 *
 * The registry service is resolved when an event arrives, not when the
 * listener is built: it needs OpenRegister, and a missing OpenRegister must
 * cost a log line here rather than an exception in the sender.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */
class ConnectionStatusReportedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the registry service lazily.
	 * @param LoggerInterface $logger Logs a failed write.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a status report.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-unknown-key-is-refused-without-an-exception
	 */
	public function handle(Event $event): void {
		if (($event instanceof ConnectionStatusReportedEvent) === false) {
			return;
		}

		try {
			$this->container->get(ConnectionRegistryService::class)->report(
				app: $event->app,
				key: $event->key,
				status: $event->status,
				message: $event->message
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Integriq could not record the connection report from {reportingApp} for {key}: {reason}',
				['app' => 'integriq', 'reportingApp' => $event->app, 'key' => $event->key, 'reason' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end handle()
}//end class
