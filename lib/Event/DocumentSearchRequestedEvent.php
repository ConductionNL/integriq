<?php

/**
 * Integriq DocumentSearchRequested Event.
 *
 * ADR-041 typed command for sibling apps (connectors-graph-document-search,
 * design D3). Integriq answers it synchronously inside dispatchTyped() and
 * keeps no copy of what it hands over (REQ-DCC-006).
 *
 * A sibling app asks integriq to search a linked Microsoft 365 connection for
 * terms in a period, for a named person. integriq answers synchronously in
 * the result slot: `{hits, moreCount, notices}`, each hit a REQ-DCC-004
 * envelope with `entityType` and `remoteId` as the fetch handle.
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

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "search this connection for these terms".
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 event contract is a flat
 * readonly envelope the consumer stubs mirror verbatim.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */
class DocumentSearchRequestedEvent extends Event {

	/**
	 * Whether integriq answered.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The answer: `{hits, moreCount, notices}`.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string            $sourceApp     The asking app id.
	 * @param string            $connectionKey The app's linked connection.
	 * @param string            $userId        The person the search runs for.
	 * @param string            $terms         The search terms.
	 * @param string|null       $from          Start of the period, Y-m-d, or null.
	 * @param string|null       $to            End of the period, Y-m-d, or null.
	 * @param array<int,string> $entityTypes   `driveItem`, `message`, `chatMessage`; empty for all.
	 * @param int               $limit         At most this many hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $connectionKey,
		private readonly string $userId,
		private readonly string $terms,
		private readonly ?string $from = null,
		private readonly ?string $to = null,
		private readonly array $entityTypes = [],
		private readonly int $limit = 50,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app id.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The linked connection.
	 *
	 * @return string The connection key.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getConnectionKey(): string {
		return $this->connectionKey;
	}//end getConnectionKey()

	/**
	 * The person the search runs for.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * The search terms.
	 *
	 * @return string The terms.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getTerms(): string {
		return $this->terms;
	}//end getTerms()

	/**
	 * Start of the period.
	 *
	 * @return string|null The date.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getFrom(): ?string {
		return $this->from;
	}//end getFrom()

	/**
	 * End of the period.
	 *
	 * @return string|null The date.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getTo(): ?string {
		return $this->to;
	}//end getTo()

	/**
	 * The entity types to search.
	 *
	 * @return array<int,string> The types.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getEntityTypes(): array {
		return $this->entityTypes;
	}//end getEntityTypes()

	/**
	 * The hit limit.
	 *
	 * @return int The limit.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getLimit(): int {
		return $this->limit;
	}//end getLimit()

	/**
	 * Record the answer and mark the event handled.
	 *
	 * @param array<string,mixed> $result `{hits, moreCount, notices}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function setResult(array $result): void {
		$this->result = $result;
		$this->handled = true;
	}//end setResult()

	/**
	 * Whether integriq answered.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The answer, once handled.
	 *
	 * @return array<string,mixed>|null The answer.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()
}//end class
