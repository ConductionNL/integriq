<?php

/**
 * Microsoft 365 SaaS-productivity adapter.
 *
 * Reference adapter proving REQ-SPC-001 for the saas-productivity-connectors
 * category.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Chat\ThreadTranscript;
use OCP\IL10N;

/**
 * Reference `saas-productivity-connectors` adapter: Microsoft 365, via
 * Microsoft Graph's `me` calendar/mail surface.
 *
 * Scoped, per this change's proposal, to calendar + mail METADATA read
 * (`calendar-read`, `mail-metadata-read`) — no send/write capability.
 *
 * Graph endpoints called (via {@see brokeredRequest()}):
 *   `GET /v1.0/me/events`
 *   `GET /v1.0/me/messages?$select=id,subject,from,receivedDateTime,hasAttachments`
 *
 * The `$select` restricting the mail read to metadata fields is deliberate —
 * this adapter never reads message bodies.
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
 */
class Microsoft365Adapter extends AbstractCategoryAdapterProvider implements DocumentSearchSource, Microsoft365AdapterInterface {

	/**
	 * Graph `$select` fields for the mail-metadata-only read.
	 *
	 * @var string
	 */
	private const MAIL_METADATA_SELECT = 'id,subject,from,receivedDateTime,hasAttachments';

	/**
	 * The app config key naming the Graph search region an application grant needs.
	 */
	public const SEARCH_REGION_KEY = 'microsoft365_search_region';

	/**
	 * The pure half of the document search.
	 *
	 * @var GraphDriveSearch
	 */
	private readonly GraphDriveSearch $driveSearch;

	/**
	 * Reads the conversation around a Teams chat hit.
	 *
	 * @var TeamsThreadReader
	 */
	private readonly TeamsThreadReader $threads;

	/**
	 * Renders a thread as the fetch answer.
	 *
	 * @var ThreadTranscript
	 */
	private readonly ThreadTranscript $transcript;

	/**
	 * Constructor.
	 *
	 * @param \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker OR's credential broker.
	 * @param \OCP\IAppConfig $appConfig App config.
	 * @param \Psr\Log\LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translator for labels.
	 */
	public function __construct(
		\OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker,
		\OCP\IAppConfig $appConfig,
		\Psr\Log\LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
		$this->driveSearch = new GraphDriveSearch();
		$this->threads = new TeamsThreadReader();
		$this->transcript = new ThreadTranscript();

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getId(): string {
		return 'microsoft-365';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getLabel(): string {
		return $this->l10n->t('Microsoft 365');
	}//end getLabel()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getIcon(): string {
		return 'Microsoft';
	}//end getIcon()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getCapabilities(): array {
		return ['calendar-read', 'mail-metadata-read', 'search-federation', 'document-fetch'];
	}//end getCapabilities()

	/**
	 * List the signed-in user's calendar events.
	 *
	 * @return array<int,array<string,mixed>> Normalised event summaries.
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function listCalendarEvents(): array {
		$response = $this->brokeredRequest(method: 'GET', path: '/v1.0/me/events');
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return [];
		}

		$decoded = json_decode($response['body'], true);
		if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
			return [];
		}

		return array_map(
			static function (array $event): array {
				return [
					'id' => ($event['id'] ?? null),
					'subject' => ($event['subject'] ?? null),
					'start' => ($event['start']['dateTime'] ?? null),
					'end' => ($event['end']['dateTime'] ?? null),
					'organizer' => ($event['organizer']['emailAddress']['address'] ?? null),
				];
			},
			$decoded['value']
		);

	}//end listCalendarEvents()

	/**
	 * List the signed-in user's mail METADATA (never message bodies).
	 *
	 * @return array<int,array<string,mixed>> Normalised mail-metadata rows.
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function listMailMetadata(): array {
		$path = '/v1.0/me/messages?$select=' . self::MAIL_METADATA_SELECT;
		$response = $this->brokeredRequest(method: 'GET', path: $path);
		if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
			return [];
		}

		$decoded = json_decode($response['body'], true);
		if (is_array($decoded) === false || isset($decoded['value']) === false || is_array($decoded['value']) === false) {
			return [];
		}

		return array_map(
			static function (array $message): array {
				return [
					'id' => ($message['id'] ?? null),
					'subject' => ($message['subject'] ?? null),
					'from' => ($message['from']['emailAddress']['address'] ?? null),
					'receivedDateTime' => ($message['receivedDateTime'] ?? null),
					'hasAttachments' => ($message['hasAttachments'] ?? false),
				];
			},
			$decoded['value']
		);

	}//end listMailMetadata()

	/**
	 * {@inheritDoc}
	 *
	 * `register`/`schema`/`objectId` are ignored — this adapter is
	 * instance-scoped (the signed-in user's own calendar/mail, per the
	 * credential's owning user). `$filters['resource'] === 'mail'` switches
	 * from calendar (default) to mail-metadata.
	 *
	 * @param string $register Ignored (instance-scoped adapter).
	 * @param string $schema Ignored (instance-scoped adapter).
	 * @param string $objectId Ignored (instance-scoped adapter).
	 * @param array<string,mixed> $filters `resource` (`'calendar'` default, or `'mail'`).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) register/schema/objectId are mandated by
	 *   IntegrationProvider but this adapter is instance-scoped, not object-scoped.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if (($filters['resource'] ?? 'calendar') === 'mail') {
			return $this->listMailMetadata();
		}

		return $this->listCalendarEvents();
	}//end list()
	/**
	 * Whether a Microsoft 365 credential is configured for this source.
	 *
	 * @return bool True when one is.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function isConnected(): bool {
		return $this->getCredentialId() !== null;
	}//end isConnected()

	/**
	 * Search drive items, mail and chat for terms in a period, as one person.
	 *
	 * Drive items are searched with the source's application grant through
	 * Graph `/search/query`. Mail and chat need the person's own delegated
	 * grant (sources-per-user-oauth), which integriq does not hold yet, so they
	 * answer the notice `delegated-grant-missing` instead of an empty list.
	 * A refusal by Microsoft 365 answers `not-permitted`.
	 *
	 * @param string            $terms       The search terms.
	 * @param string|null       $from        Start of the period, Y-m-d.
	 * @param string|null       $to          End of the period, Y-m-d.
	 * @param array<int,string> $entityTypes The types to search; empty for all three.
	 * @param int               $limit       At most this many hits.
	 * @param string            $userId      The person searching (unused until sources-per-user-oauth lands).
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>} REQ-DCC-004 hits.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId selects the delegated grant once sources-per-user-oauth lands.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function search(string $terms, ?string $from, ?string $to, array $entityTypes, int $limit, string $userId): array {
		$plan = $this->driveSearch->plan(terms: $terms, entityTypes: $entityTypes);
		if ($plan['searchDrive'] === false) {
			return ['hits' => [], 'moreCount' => 0, 'notices' => $plan['notices']];
		}

		$region = $this->appConfig->getValueString('integriq', self::SEARCH_REGION_KEY, 'EUR');
		$response = $this->brokeredRequest(
			method: 'POST',
			path: '/v1.0/search/query',
			headers: ['Content-Type' => 'application/json'],
			body: $this->driveSearch->requestBody(
				query: new DriveSearchQuery(terms: $terms, from: $from, to: $to, limit: $limit, region: $region)
			),
		);

		return $this->driveSearch->answer(response: $response, sourceSlug: $this->getId(), limit: $limit, notices: $plan['notices']);
	}//end search()

	/**
	 * Fetch the content behind a search hit's handle. integriq keeps no copy.
	 *
	 * @param string $handle The hit's `remoteId`: `driveItem:{driveId}:{itemId}`.
	 * @param string $userId The person fetching (unused until sources-per-user-oauth lands).
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null Null for an unknown handle,
	 *         a mail or chat hit (no delegated grant yet), or a refusal.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId selects the delegated grant once sources-per-user-oauth lands.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function fetch(string $handle, string $userId): ?array {
		$item = $this->driveSearch->itemPath(handle: $handle);
		if ($item === null) {
			return null;
		}

		return $this->driveSearch->file(
			meta: $this->brokeredRequest(method: 'GET', path: $item . '?$select=name,file'),
			content: $this->brokeredRequest(method: 'GET', path: $item . '/content'),
		);
	}//end fetch()

	/**
	 * Fetch a Teams chat or channel message with its conversation.
	 *
	 * The thread is read with the requester's delegated grant, the same grant
	 * a chat hit is found with, never with the source's application grant: an
	 * application grant reads every chat of the organisation. Until the
	 * person has a delegated grant (sources-per-user-oauth) the answer is the
	 * error `delegated-grant-missing` and no Graph call is made.
	 *
	 * @param string $handle  A `chatMessage:teams:…` handle.
	 * @param string $userId  The person fetching.
	 * @param int    $context Messages to read on each side of the hit; the caller validated it.
	 *
	 * @return array<string,mixed>|null `{fileName, mimeType, content, thread}`, `{error}`, or null
	 *         for a handle that names no Teams message.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	public function fetchThread(string $handle, string $userId, int $context): ?array {
		if ($this->threads->isTeamsHandle(handle: $handle) === false) {
			return null;
		}

		$credentialId = $this->delegatedCredentialId(userId: $userId);
		if ($credentialId === null) {
			return ['error' => 'delegated-grant-missing'];
		}

		$read = $this->threads->read(
			handle: $handle,
			context: $context,
			get: fn (string $path): ?array => $this->brokeredRequest(method: 'GET', path: $path, credentialId: $credentialId),
		);
		if ($read === null || isset($read['thread']) === false) {
			return $read;
		}

		return $this->transcript->answer(thread: $read['thread'], fetchedAt: gmdate(DATE_ATOM));
	}//end fetchThread()

	/**
	 * The broker credential holding this person's delegated Microsoft 365 grant.
	 *
	 * Integriq does not hold per-person grants for the adapter yet
	 * (sources-per-user-oauth), so this answers null and every chat read is
	 * refused rather than run with the application grant.
	 *
	 * @param string $userId The person.
	 *
	 * @return string|null The credential id, or null when the person has none.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId selects the delegated grant once sources-per-user-oauth lands.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
	 */
	protected function delegatedCredentialId(string $userId): ?string {
		return null;
	}//end delegatedCredentialId()
}//end class
