<?php

/**
 * Integriq ConnectionRefreshRequested EventListener.
 *
 * Receives an app's {@see ConnectionRefreshRequestedEvent} after its settings
 * changed. It stamps `refreshedAt` on the affected rows, which retires older
 * reports and probes, and resolves them again. It never throws into the
 * sender, whose settings save must not fail because of a status.
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

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves an app's connections again on request.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */
class ConnectionRefreshRequestedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the registry service lazily.
	 * @param LoggerInterface $logger Logs a failed refresh.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a refresh request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function handle(Event $event): void {
		if (($event instanceof ConnectionRefreshRequestedEvent) === false) {
			return;
		}

		if ($event->app === '') {
			$this->logger->warning('Integriq ignored a connection refresh request without an app id.', ['app' => 'integriq']);
			return;
		}

		try {
			$this->container->get(ConnectionRegistryService::class)->refreshRequested(app: $event->app, key: $event->key);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Integriq could not refresh the connections of {declaringApp}: {reason}',
				['app' => 'integriq', 'declaringApp' => $event->app, 'reason' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end handle()
}//end class
