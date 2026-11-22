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

/**
 * Typed cross-app question: "search this connection for these terms".
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 event contract is a flat
 * readonly envelope the consumer stubs mirror verbatim.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */
class DocumentSearchRequestedEvent extends DocumentConnectionRequestEvent {

	/**
	 * The Microsoft 365 source: files, mail and Teams.
	 */
	public const PLATFORM_MICROSOFT_365 = 'microsoft-365';

	/**
	 * Accepted as a name for the Microsoft 365 source.
	 */
	public const PLATFORM_MICROSOFT_TEAMS = 'microsoft-teams';

	/**
	 * Nextcloud Talk, searched as the requesting person.
	 */
	public const PLATFORM_NEXTCLOUD_TALK = 'nextcloud-talk';

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
	 * @param array<int,string> $platforms     `microsoft-365` (or `microsoft-teams`), `nextcloud-talk`; empty for both.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function __construct(
		string $sourceApp,
		string $connectionKey,
		string $userId,
		private readonly string $terms,
		private readonly ?string $from = null,
		private readonly ?string $to = null,
		private readonly array $entityTypes = [],
		private readonly int $limit = 50,
		private readonly array $platforms = [],
	) {
		parent::__construct(sourceApp: $sourceApp, connectionKey: $connectionKey, userId: $userId);
	}//end __construct()

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
	 * The platforms asked for.
	 *
	 * @return array<int,string> The platforms; empty for both.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getPlatforms(): array {
		return $this->platforms;
	}//end getPlatforms()

	/**
	 * Whether a platform is to be searched: every platform when none is named.
	 *
	 * @param string $platform PLATFORM_MICROSOFT_365 or PLATFORM_NEXTCLOUD_TALK.
	 *
	 * @return bool True when it is searched.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function wantsPlatform(string $platform): bool {
		if ($this->platforms === []) {
			return true;
		}

		$asked = $this->platforms;
		if (in_array(self::PLATFORM_MICROSOFT_TEAMS, $asked, true) === true) {
			$asked[] = self::PLATFORM_MICROSOFT_365;
		}

		return in_array($platform, $asked, true);
	}//end wantsPlatform()
}//end class
