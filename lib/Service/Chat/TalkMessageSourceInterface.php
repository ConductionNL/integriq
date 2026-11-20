<?php

/**
 * Contract of the TalkMessageSource.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Chat;

use OCA\Integriq\Event\DocumentSearchRequestedEvent;

/**
 * Public operations of {@see TalkMessageSource} that other components call.
 */
interface TalkMessageSourceInterface {
	/**
	 * Whether a handle names a Talk message.
	 *
	 * @param string $handle The handle.
	 *
	 * @return bool True for a Talk handle.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function isTalkHandle(string $handle): bool;

	/**
	 * Search Talk messages as the person who asks, in the asked period.
	 *
	 * @param DocumentSearchRequestedEvent $search The search: person, terms, period and limit.
	 *
	 * @return array{hits: array<int,array<string,mixed>>, moreCount: int, notices: array<int,string>}
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function search(DocumentSearchRequestedEvent $search): array;

	/**
	 * Fetch a Talk hit with its conversation, as the person.
	 *
	 * @param string $handle  A `chatMessage:talk:{token}:{messageId}` handle.
	 * @param string $userId  The person fetching.
	 * @param int    $context Messages to read on each side of the hit.
	 *
	 * @return array<string,mixed>|null `{fileName, mimeType, content, thread}`, `{error}`, or null.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function fetch(string $handle, string $userId, int $context): ?array;
}//end interface
