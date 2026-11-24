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
use OCA\Integriq\Service\Credential\PersonalCredentialLookup;
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
	 * The app config key naming the broker provider a person's delegated grant is held at.
	 */
	public const DELEGATED_PROVIDER_KEY = 'microsoft365_delegated_provider';

	/**
	 * The provider a person connects their own Microsoft 365 account at (sources-per-user-oauth).
	 */
	public const DEFAULT_DELEGATED_PROVIDER = 'microsoft-entra';

	/**
	 * The pure half of the document search.
	 *
	 * @var GraphDriveSearch
	 */
	private readonly GraphDriveSearch $driveSearch;

	/**
	 * The mail and chat half of the document search.
	 *
	 * @var GraphDelegatedSearch
	 */
	private readonly GraphDelegatedSearch $delegatedSearch;

	/**
	 * Finds a person's delegated grant; without it mail and chat answer `delegated-grant-missing`.
	 *
	 * @var PersonalCredentialLookup|null
	 */
	private ?PersonalCredentialLookup $personalCredentials = null;

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
		$this->delegatedSearch = new GraphDelegatedSearch();
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
	 * Read each person's delegated grant through this lookup (sources-per-user-oauth).
	 *
	 * @param PersonalCredentialLookup $personalCredentials Finds a person's own credential at a provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function useDelegatedGrants(PersonalCredentialLookup $personalCredentials): void {
		$this->personalCredentials = $personalCredentials;
	}//end useDelegatedGrants()

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
	 * Graph `/search/query`. Mail and chat are searched with the person's own
	 * delegated grant (sources-per-user-oauth), in one more request; a person
	 * without one gets the notice `delegated-grant-missing` and no mail or chat
	 * call is made. A refusal by Microsoft 365 answers `not-permitted`.
	 *
	 * @param string            $terms       The search terms.
	 * @param string|null       $from        Start of the period, Y-m-d.
	 * @param string|null       $to          End of the period, Y-m-d.
	 * @param array<int,string> $entityTypes The types to search; empty for all three.
	 * @param int               $limit       At most this many hits.
	 * @param string            $userId      The person searching; their delegated grant searches mail and chat.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>} REQ-DCC-004 hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function search(string $terms, ?string $from, ?string $to, array $entityTypes, int $limit, string $userId): array {
		$plan  = $this->driveSearch->plan(terms: $terms, entityTypes: $entityTypes);
		$query = new DriveSearchQuery(
			terms: $terms,
			from: $from,
			to: $to,
			limit: $limit,
			region: $this->appConfig->getValueString('integriq', self::SEARCH_REGION_KEY, 'EUR')
		);

		$delegated = $this->delegatedAnswer(query: $query, entityTypes: $entityTypes, userId: $userId);
		$notices   = $plan['notices'];
		if ($delegated !== null) {
			$notices = array_values(array_diff($notices, ['delegated-grant-missing']));
		}

		$drive = ['hits' => [], 'moreCount' => 0, 'notices' => $notices];
		if ($plan['searchDrive'] === true) {
			$response = $this->brokeredRequest(
				method: 'POST',
				path: '/v1.0/search/query',
				headers: ['Content-Type' => 'application/json'],
				body: $this->driveSearch->requestBody(query: $query),
			);
			$drive    = $this->driveSearch->answer(response: $response, sourceSlug: $this->getId(), limit: $limit, notices: $notices);
		}

		if ($delegated === null) {
			return $drive;
		}

		return self::merged(drive: $drive, delegated: $delegated, limit: $limit);
	}//end search()

	/**
	 * The mail and chat search with the person's delegated grant, or null when they have none or none is asked.
	 *
	 * @param DriveSearchQuery  $query       The terms, dates and limit.
	 * @param array<int,string> $entityTypes The asked types; empty for all.
	 * @param string            $userId      The person searching.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, total: int, notice: string|null}|null
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	private function delegatedAnswer(DriveSearchQuery $query, array $entityTypes, string $userId): ?array {
		$types = $this->delegatedSearch->typesOf(entityTypes: $entityTypes);
		if ($types === []) {
			return null;
		}

		$credentialId = $this->delegatedCredentialId(userId: $userId);
		if ($credentialId === null) {
			return null;
		}

		if (trim($query->terms) === '') {
			return ['hits' => [], 'total' => 0, 'notice' => null];
		}

		$response = $this->brokeredRequest(
			method: 'POST',
			path: '/v1.0/search/query',
			headers: ['Content-Type' => 'application/json'],
			body: $this->delegatedSearch->requestBody(query: $query, types: $types),
			credentialId: $credentialId,
		);

		return $this->delegatedSearch->answer(response: $response, sourceSlug: $this->getId());
	}//end delegatedAnswer()

	/**
	 * Drive hits first, then mail and chat hits, at most the limit, with the count of the rest.
	 *
	 * @param array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>} $drive     The drive answer.
	 * @param array{hits: array<int, array<string, mixed>>, total: int, notice: string|null}             $delegated The mail and chat answer.
	 * @param int                                                                                       $limit     At most this many hits.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>}
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	private static function merged(array $drive, array $delegated, int $limit): array {
		$all     = array_merge($drive['hits'], $delegated['hits']);
		$kept    = array_slice($all, 0, max(1, min(500, $limit)));
		$total   = count($drive['hits']) + $drive['moreCount'] + max($delegated['total'], count($delegated['hits']));
		$notices = $drive['notices'];
		if ($delegated['notice'] !== null && in_array($delegated['notice'], $notices, true) === false) {
			$notices[] = $delegated['notice'];
		}

		return ['hits' => $kept, 'moreCount' => ($total - count($kept)), 'notices' => $notices];
	}//end merged()

	/**
	 * Fetch the content behind a search hit's handle. integriq keeps no copy.
	 *
	 * @param string $handle The hit's `remoteId`: `driveItem:{driveId}:{itemId}`.
	 * @param string $userId The person fetching (unused: a drive item is read with the application grant).
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null Null for an unknown handle,
	 *         a mail hit (its .eml fetch is not built yet), or a refusal. A chat hit is fetched through fetchThread().
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is for the mail fetch, which reads with the person's grant.
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
			get: $this->delegatedGet(credentialId: $credentialId),
		);
		if ($read === null || isset($read['thread']) === false) {
			return $read;
		}

		return $this->transcript->answer(thread: $read['thread'], fetchedAt: gmdate(DATE_ATOM));
	}//end fetchThread()

	/**
	 * A Graph GET transport that runs with one broker credential.
	 *
	 * @param string $credentialId The broker credential.
	 *
	 * @return callable `fn (string $path): ?array{status: int, body: string}`.
	 */
	private function delegatedGet(string $credentialId): callable {
		return fn (string $path): ?array => $this->brokeredRequest(method: 'GET', path: $path, credentialId: $credentialId);
	}//end delegatedGet()

	/**
	 * The broker credential holding this person's delegated Microsoft 365 grant.
	 *
	 * The person's own personal credential at the configured provider
	 * (`microsoft-entra` unless app config names another). None, or more than
	 * one, answers null, so a mail or chat read is refused rather than run with
	 * the application grant.
	 *
	 * @param string $userId The person.
	 *
	 * @return string|null The credential id, or null when the person has none.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	protected function delegatedCredentialId(string $userId): ?string {
		if ($this->personalCredentials === null) {
			return null;
		}

		return $this->personalCredentials->credentialIdFor(
			provider: $this->appConfig->getValueString('integriq', self::DELEGATED_PROVIDER_KEY, self::DEFAULT_DELEGATED_PROVIDER),
			userId: $userId
		);
	}//end delegatedCredentialId()
}//end class
