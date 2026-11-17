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
use OCA\Integriq\Service\Chat\TalkMessageSource;
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
	 * @param TalkMessageSource   $talk    The Nextcloud Talk source, searched as the requesting person.
	 */
	public function __construct(
		private readonly Microsoft365AdapterInterface $adapter,
		private readonly DocumentConnectionGuardInterface $guard,
		private readonly LoggerInterface $logger,
		private readonly TalkMessageSource $talk,
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

		$audit = $this->auditOf(event: $event);
		if ($this->guard->isLinked(sourceApp: $event->getSourceApp(), connectionKey: $event->getConnectionKey()) === false) {
			$this->refuse(event: $event, audit: $audit);
			return;
		}

		try {
			$this->answer(event: $event, audit: $audit);
		} catch (Throwable $e) {
			$this->logger->warning('Integriq: a document search failed', $audit + ['error' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * What every log line about the search names: the app, the connection, the user and the terms.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 *
	 * @return array<string,mixed> The audit context.
	 */
	private function auditOf(DocumentSearchRequestedEvent $event): array {
		return [
			'sourceApp' => $event->getSourceApp(),
			'connectionKey' => $event->getConnectionKey(),
			'userId' => $event->getUserId(),
			'terms' => $event->getTerms(),
		];
	}//end auditOf()

	/**
	 * Refuse a search from an app without a linked connection.
	 *
	 * The drive search covers the whole organisation (design D2): an app with
	 * no linked connection gets nothing and no Graph call is made.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 * @param array<string,mixed>          $audit The audit context.
	 *
	 * @return void
	 */
	private function refuse(DocumentSearchRequestedEvent $event, array $audit): void {
		$this->logger->warning('Integriq: a document search was refused, the app has no linked connection', $audit);
		$event->setResult(['hits' => [], 'moreCount' => 0, 'notices' => ['not-linked']]);
	}//end refuse()

	/**
	 * Answer a linked search from each platform asked for, merged up to the limit.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 * @param array<string,mixed>          $audit The audit context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	private function answer(DocumentSearchRequestedEvent $event, array $audit): void {
		$parts = [];
		if ($event->wantsPlatform(platform: DocumentSearchRequestedEvent::PLATFORM_MICROSOFT_365) === true) {
			$parts[] = $this->microsoft365(event: $event);
		}

		if ($event->wantsPlatform(platform: DocumentSearchRequestedEvent::PLATFORM_NEXTCLOUD_TALK) === true && $this->asksForChat(event: $event) === true) {
			$parts[] = $this->talk->search(search: $event);
		}

		$event->setResult($this->merge(parts: $parts, limit: $event->getLimit()));
		$this->logger->info('Integriq: a document search was answered', $audit);
	}//end answer()

	/**
	 * The Microsoft 365 part: the notice `not-connected` without a connection, else the adapter's hits.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 *
	 * @return array<string,mixed> `{hits, moreCount, notices}`.
	 */
	private function microsoft365(DocumentSearchRequestedEvent $event): array {
		if ($this->adapter->isConnected() === false) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => ['not-connected']];
		}

		return $this->search(event: $event);
	}//end microsoft365()

	/**
	 * Whether the search asks for chat messages: all types, or `chatMessage` among them.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 *
	 * @return bool True when Talk is in scope.
	 */
	private function asksForChat(DocumentSearchRequestedEvent $event): bool {
		return $event->getEntityTypes() === [] || in_array('chatMessage', $event->getEntityTypes(), true) === true;
	}//end asksForChat()

	/**
	 * Merge the platforms' answers: hits in order up to the limit, the rest counted, notices once each.
	 *
	 * @param array<int,array<string,mixed>> $parts The answers.
	 * @param int                            $limit At most this many hits.
	 *
	 * @return array{hits: array<int,mixed>, moreCount: int, notices: array<int,string>} The answer.
	 */
	private function merge(array $parts, int $limit): array {
		$hits = [];
		$more = 0;
		$notices = [];
		foreach ($parts as $part) {
			$hits = array_merge($hits, array_values((array)($part['hits'] ?? [])));
			$more += (int)($part['moreCount'] ?? 0);
			$notices = array_merge($notices, array_values((array)($part['notices'] ?? [])));
		}

		$limit = max(1, min(500, $limit));
		$more += max(0, count($hits) - $limit);

		return ['hits' => array_slice($hits, 0, $limit), 'moreCount' => $more, 'notices' => array_values(array_unique($notices))];
	}//end merge()

	/**
	 * The adapter's hits for the search.
	 *
	 * @param DocumentSearchRequestedEvent $event The search.
	 *
	 * @return array<string,mixed> `{hits, moreCount, notices}`.
	 */
	private function search(DocumentSearchRequestedEvent $event): array {
		return $this->adapter->search(
			terms: $event->getTerms(),
			from: $event->getFrom(),
			to: $event->getTo(),
			entityTypes: $event->getEntityTypes(),
			limit: $event->getLimit(),
			userId: $event->getUserId(),
		);
	}//end search()
}//end class
