<?php

/**
 * Integriq Connection Handover Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category EventListener
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes over a connection another app hands to integriq, and answers per part.
 *
 * The handover events belong to the apps that send them, so they are named by
 * string and read by shape: the settings come from `getSettings()` (or a public
 * `settings` array), the target source from `getSourceSlug()` (or `sourceSlug`,
 * else the default for that event), and the answer goes back through
 * `setAnswer()`.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 */
class ConnectionHandoverListener implements IEventListener {

	/**
	 * The handover events integriq takes, with the source each fills by default.
	 *
	 * @var array<string, string>
	 */
	public const EVENTS = [
		'OCA\\Pipelinq\\Event\\BrpConnectionHandoverEvent' => 'brp-haalcentraal',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the handover service lazily.
	 * @param LoggerInterface    $logger    Logs a handover that could not run.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a handover event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-21-brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
	 */
	public function handle(Event $event): void {
		(new ConnectionHandoverEventHandler(container: $this->container, logger: $this->logger))->handle(event: $event);
	}//end handle()
}//end class
