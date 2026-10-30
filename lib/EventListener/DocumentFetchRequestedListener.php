<?php

/**
 * Integriq DocumentFetchRequested listener.
 *
 * Answers a sibling app's document fetch through the Microsoft 365 adapter
 * (connectors-graph-document-search, design D3). The answer goes into the
 * event's result slot; integriq stores nothing (REQ-DCC-006).
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuard;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365AdapterInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the request through the Microsoft 365 adapter and writes the answer,
 * for an app that has a linked connection and for no other (design D3).
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */
class DocumentFetchRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param Microsoft365AdapterInterface $adapter The Microsoft 365 source.
	 * @param DocumentConnectionGuard $guard Refuses an app without a linked connection.
	 * @param LoggerInterface     $logger  Records who asked and for which handle, never what was found.
	 */
	public function __construct(
		private readonly Microsoft365AdapterInterface $adapter,
		private readonly DocumentConnectionGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function handle(Event $event): void {
		if (($event instanceof DocumentFetchRequestedEvent) === false) {
			return;
		}

		$audit = [
			'sourceApp' => $event->getSourceApp(),
			'connectionKey' => $event->getConnectionKey(),
			'userId' => $event->getUserId(),
			'handle' => $event->getHandle(),
		];
		if ($this->guard->isLinked(sourceApp: $event->getSourceApp(), connectionKey: $event->getConnectionKey()) === false) {
			// Any drive item of the organisation can be named by a hand-built handle: an app with no linked connection fetches nothing.
			$this->logger->warning('Integriq: a document fetch was refused, the app has no linked connection', $audit);
			return;
		}

		try {
			$fetched = $this->adapter->fetch(handle: $event->getHandle(), userId: $event->getUserId());
			if ($fetched === null) {
				return;
			}

			$event->setResult($fetched);
			$this->logger->info('Integriq: a document was fetched for a sibling app', $audit);
		} catch (Throwable $e) {
			$this->logger->warning('Integriq: a document fetch failed', $audit + ['error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
