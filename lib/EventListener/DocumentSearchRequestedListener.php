<?php

/**
 * Integriq DocumentSearchRequested listener.
 *
 * Answers a sibling app's document search through the Microsoft 365 adapter
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

use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuardInterface;
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
class DocumentSearchRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param Microsoft365AdapterInterface $adapter The Microsoft 365 source.
	 * @param DocumentConnectionGuardInterface $guard Refuses an app without a linked connection.
	 * @param LoggerInterface     $logger  Records who asked and for what terms, never what was found.
	 */
	public function __construct(
		private readonly Microsoft365AdapterInterface $adapter,
		private readonly DocumentConnectionGuardInterface $guard,
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
		if (($event instanceof DocumentSearchRequestedEvent) === false) {
			return;
		}

		$audit = [
			'sourceApp' => $event->getSourceApp(),
			'connectionKey' => $event->getConnectionKey(),
			'userId' => $event->getUserId(),
			'terms' => $event->getTerms(),
		];
		if ($this->guard->isLinked(sourceApp: $event->getSourceApp(), connectionKey: $event->getConnectionKey()) === false) {
			// The drive search covers the whole organisation (design D2): an app with no linked connection gets nothing and no Graph call is made.
			$this->logger->warning('Integriq: a document search was refused, the app has no linked connection', $audit);
			$event->setResult(['hits' => [], 'moreCount' => 0, 'notices' => ['not-linked']]);
			return;
		}

		try {
			if ($this->adapter->isConnected() === false) {
				$event->setResult(['hits' => [], 'moreCount' => 0, 'notices' => ['not-connected']]);
				return;
			}

			$event->setResult(
				$this->adapter->search(
					terms: $event->getTerms(),
					from: $event->getFrom(),
					to: $event->getTo(),
					entityTypes: $event->getEntityTypes(),
					limit: $event->getLimit(),
					userId: $event->getUserId(),
				)
			);
			$this->logger->info('Integriq: a document search was answered', $audit);
		} catch (Throwable $e) {
			$this->logger->warning('Integriq: a document search failed', $audit + ['error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
