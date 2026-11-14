<?php

/**
 * Integriq ConnectorExtensionCollector.
 *
 * Asks the installed apps once for their connector adapters, and keeps the
 * record of what each contributed and what was refused.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Extension
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
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Extension;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Dispatches {@see RegisterConnectorExtensionsEvent} at most once per process
 * and hands every registry the same answer, so an extension's listener runs
 * once however many registries are built.
 *
 * Each registry reports back what it took and what it refused. That record is
 * what `occ integriq:extensions` lists.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class ConnectorExtensionCollector {

	/**
	 * The seam names, as the record and the command show them.
	 */
	public const SEAM_INTAKE_CHANNEL = 'intake channel';
	public const SEAM_DIGITAL_POST = 'digital post provider';
	public const SEAM_PAYMENT = 'payment provider';
	public const SEAM_TEMPLATES = 'connector templates';

	/**
	 * The answered event, once dispatched.
	 *
	 * @var RegisterConnectorExtensionsEvent|null
	 */
	private ?RegisterConnectorExtensionsEvent $event = null;

	/**
	 * What each registry did with each contribution.
	 *
	 * @var array<int,array{seam: string, id: string, appId: string, class: string, refused: bool, reason: string}>
	 */
	private array $record = [];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the collect event.
	 * @param LoggerInterface  $logger     Records a refused contribution.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The event as the installed apps answered it, dispatched on the first call.
	 *
	 * @return RegisterConnectorExtensionsEvent
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function collect(): RegisterConnectorExtensionsEvent {
		if ($this->event === null) {
			$this->event = new RegisterConnectorExtensionsEvent();
			$this->dispatcher->dispatchTyped($this->event);
		}

		return $this->event;
	}//end collect()

	/**
	 * Record a contribution a registry took.
	 *
	 * @param string $seam  The seam (one of the SEAM_ constants).
	 * @param string $id    The id it registered under.
	 * @param string $appId The contributing app.
	 * @param object $item  The adapter or provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function accepted(string $seam, string $id, string $appId, object $item): void {
		$this->record[] = [
			'seam' => $seam,
			'id' => $id,
			'appId' => $appId,
			'class' => $item::class,
			'refused' => false,
			'reason' => '',
		];
	}//end accepted()

	/**
	 * Record and log a contribution a registry refused because its id was taken.
	 *
	 * @param string $seam    The seam (one of the SEAM_ constants).
	 * @param string $id      The id it claimed.
	 * @param string $appId   The contributing app.
	 * @param object $item    The refused adapter or provider.
	 * @param string $keptApp The app whose entry keeps the id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	public function refused(string $seam, string $id, string $appId, object $item, string $keptApp): void {
		$reason = sprintf('the id "%s" is already registered by %s', $id, $keptApp);
		$this->record[] = [
			'seam' => $seam,
			'id' => $id,
			'appId' => $appId,
			'class' => $item::class,
			'refused' => true,
			'reason' => $reason,
		];
		$this->logger->warning(
			'Integriq extensions: a contributed {seam} was refused: {reason}.',
			[
				'seam' => $seam,
				'reason' => $reason,
				'app' => $appId,
				'class' => $item::class,
			]
		);
	}//end refused()

	/**
	 * Everything contributed so far, taken or refused, in registration order.
	 *
	 * @return array<int,array{seam: string, id: string, appId: string, class: string, refused: bool, reason: string}>
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function record(): array {
		return $this->record;
	}//end record()
}//end class
