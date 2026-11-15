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
	 * Constructor.
	 *
	 * @param string $sourceApp     The asking app id.
	 * @param string $connectionKey The app's linked connection.
	 * @param string $userId        The person the fetch runs for.
	 * @param string $handle        The hit's fetch handle (`remoteId`).
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function __construct(
		string $sourceApp,
		string $connectionKey,
		string $userId,
		private readonly string $handle,
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
}//end class
