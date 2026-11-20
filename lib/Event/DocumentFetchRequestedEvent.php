<?php

/**
 * Integriq DocumentFetchRequested Event.
 *
 * ADR-041 typed command for sibling apps (connectors-graph-document-search,
 * design D3). Integriq answers it synchronously inside dispatchTyped() and
 * keeps no copy of what it hands over (REQ-DCC-006).
 *
 * A sibling app asks integriq for the content of one search hit by its
 * handle. integriq answers `{fileName, mimeType, content}` and keeps no copy.
 * A chat hit's answer also carries its `thread` (REQ-DCC-010).
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

/**
 * Typed cross-app question: "give me the content behind this handle".
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */
class DocumentFetchRequestedEvent extends DocumentConnectionRequestEvent {

	/**
	 * Messages read on each side of a chat hit when the caller names none.
	 */
	public const DEFAULT_CONTEXT = 5;

	/**
	 * The most messages a caller may ask for on each side of a chat hit.
	 */
	public const MAX_CONTEXT = 25;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp     The asking app id.
	 * @param string $connectionKey The app's linked connection.
	 * @param string $userId        The person the fetch runs for.
	 * @param string $handle        The hit's fetch handle (`remoteId`).
	 * @param int    $context       For a chat hit: messages to read on each side of it, 0 to MAX_CONTEXT.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function __construct(
		string $sourceApp,
		string $connectionKey,
		string $userId,
		private readonly string $handle,
		private readonly int $context = self::DEFAULT_CONTEXT,
	) {
		parent::__construct(sourceApp: $sourceApp, connectionKey: $connectionKey, userId: $userId);
	}//end __construct()

	/**
	 * The fetch handle.
	 *
	 * @return string The handle.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getHandle(): string {
		return $this->handle;
	}//end getHandle()

	/**
	 * For a chat hit: messages to read on each side of it.
	 *
	 * @return int The context size, as the caller asked it.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function getContext(): int {
		return $this->context;
	}//end getContext()
}//end class
