<?php

/**
 * Integriq ConnectionAppLifecycle EventListener.
 *
 * Keeps connection rows in step with the apps that declare them. Enabling an
 * app syncs its `connections.json` (umbrella design D5). Disabling one resolves
 * its rows again, so they show "The {app} app is disabled." (D4 rule 1) at
 * once instead of after the next health job run.
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
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs on app enable, resolves on app disable.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */
class ConnectionAppLifecycleListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the registry service lazily.
	 * @param LoggerInterface $logger Logs a failed sync.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an app enable or disable.
	 *
	 * Enabling integriq itself syncs every enabled app, because the other apps'
	 * declarations were written while nothing was listening.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof AppEnableEvent) {
			$appId = $event->getAppId();
			$this->run(appId: $appId, operation: static function (ConnectionRegistryService $registry) use ($appId): void {
				if ($appId === Application::APP_ID) {
					$registry->sync();
					return;
				}

				$registry->sync(app: $appId);
			});
			return;
		}

		if ($event instanceof AppDisableEvent) {
			$appId = $event->getAppId();
			$this->run(appId: $appId, operation: static function (ConnectionRegistryService $registry) use ($appId): void {
				$registry->refresh(app: $appId);
			});
		}
	}//end handle()

	/**
	 * Run an operation against the registry without letting it throw.
	 *
	 * @param string $appId The app the event is about.
	 * @param callable $operation Receives the registry service.
	 *
	 * @return void
	 */
	private function run(string $appId, callable $operation): void {
		try {
			$operation($this->container->get(ConnectionRegistryService::class));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Integriq could not update the connections of {declaringApp}: {reason}',
				['app' => 'integriq', 'declaringApp' => $appId, 'reason' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end run()
}//end class
