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
use OCA\Integriq\Service\PersonalCredentialLookup;
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
	 * The app config key naming the credential broker provider of each person's own Microsoft account.
	 */
	public const DELEGATED_PROVIDER_KEY = 'microsoft365_delegated_provider';

	/**
	 * The provider of each person's own Microsoft account when none is configured.
	 */
	public const DEFAULT_DELEGATED_PROVIDER = 'microsoft-entra';

	/**
	 * The pure half of the document search.
	 *
	 * @var GraphDriveSearch
	 */
	private readonly GraphDriveSearch $driveSearch;

	/**
	 * The pure half of the mail and chat search.
	 *
	 * @var GraphDelegatedSearch
	 */
	private readonly GraphDelegatedSearch $delegatedSearch;

	/**
	 * Constructor.
	 *
	 * @param \OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker OR's credential broker.
	 * @param \OCP\IAppConfig $appConfig App config.
	 * @param \Psr\Log\LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translator for labels.
	 * @param PersonalCredentialLookup|null $personalCredentials Each person's own connected account, for
	 *                                                           mail and chat; without it they answer
	 *                                                           `delegated-grant-missing`.
	 */
	public function __construct(
		\OCA\OpenRegister\Service\Credential\CredentialBrokerService $credentialBroker,
		\OCP\IAppConfig $appConfig,
		\Psr\Log\LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly ?PersonalCredentialLookup $personalCredentials = null,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
		$this->driveSearch = new GraphDriveSearch();
		$this->delegatedSearch = new GraphDelegatedSearch();

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
	 * Graph `/search/query`. Mail and chat are searched with the person's own
	 * delegated grant: their one connected account at the configured provider
	 * (sources-per-user-oauth). Without one they answer the notice
	 * `delegated-grant-missing`, with more than one `delegated-grant-ambiguous`
	 * (it is never guessed), and file hits still come back. A refusal by
	 * Microsoft 365 answers `not-permitted`.
	 *
	 * @param string            $terms       The search terms.
	 * @param string|null       $from        Start of the period, Y-m-d.
	 * @param string|null       $to          End of the period, Y-m-d.
	 * @param array<int,string> $entityTypes The types to search; empty for all three.
	 * @param int               $limit       At most this many hits.
	 * @param string            $userId      The person searching, whose own grant searches mail and chat.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>} REQ-DCC-004 hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	public function search(string $terms, ?string $from, ?string $to, array $entityTypes, int $limit, string $userId): array {
		$delegatedTypes = $this->delegatedSearch->types(entityTypes: $entityTypes);
		$grant = ['credentialId' => null, 'notice' => null];
		if ($delegatedTypes !== []) {
			$grant = $this->delegatedGrant(userId: $userId);
		}

		$plan = $this->driveSearch->plan(terms: $terms, entityTypes: $entityTypes, delegatedNotice: $grant['notice']);
		$notices = $plan['notices'];
		$hits = [];
		$total = 0;

		if ($plan['searchDrive'] === true) {
			$region = $this->appConfig->getValueString('integriq', self::SEARCH_REGION_KEY, 'EUR');
			$response = $this->brokeredRequest(
				method: 'POST',
				path: '/v1.0/search/query',
				headers: ['Content-Type' => 'application/json'],
				body: $this->driveSearch->requestBody(
					query: new DriveSearchQuery(terms: $terms, from: $from, to: $to, limit: $limit, region: $region)
				),
			);
			$drive = $this->driveSearch->answer(response: $response, sourceSlug: $this->getId(), limit: $limit, notices: []);
			$hits = $drive['hits'];
			$total = (count($drive['hits']) + $drive['moreCount']);
			$notices = array_merge($notices, $drive['notices']);
		}

		if ($grant['credentialId'] !== null && trim($terms) !== '') {
			$query = new DriveSearchQuery(terms: $terms, from: $from, to: $to, limit: $limit, region: '');
			foreach ($delegatedTypes as $entityType) {
				$answer = $this->delegatedSearch->answer(
					response: $this->brokeredRequest(
						method: 'POST',
						path: '/v1.0/search/query',
						headers: ['Content-Type' => 'application/json'],
						body: $this->delegatedSearch->requestBody(entityType: $entityType, query: $query),
						credentialId: $grant['credentialId'],
					),
					entityType: $entityType,
					sourceSlug: $this->getId(),
				);
				$hits = array_merge($hits, $answer['hits']);
				$total += $answer['total'];
				if ($answer['notice'] !== null) {
					$notices[] = $answer['notice'];
				}
			}
		}

		$kept = array_slice($hits, 0, max(1, min(500, $limit)));

		return [
			'hits' => $kept,
			'moreCount' => max(0, ($total - count($kept))),
			'notices' => array_values(array_unique($notices)),
		];
	}//end search()

	/**
	 * Fetch the content behind a search hit's handle. integriq keeps no copy.
	 *
	 * A drive item is fetched with the source's application grant; a mail or
	 * chat message with the person's own grant, as the `.eml` source of the
	 * mail or the chat message's Graph JSON.
	 *
	 * @param string $handle The hit's `remoteId`: `driveItem:{driveId}:{itemId}`, `message:{id}` or
	 *                       `chatMessage:{chatId}:{id}`.
	 * @param string $userId The person fetching, whose own grant fetches mail and chat.
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null Null for an unknown handle,
	 *         a mail or chat hit without the person's own grant, or a refusal.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function fetch(string $handle, string $userId): ?array {
		$item = $this->driveSearch->itemPath(handle: $handle);
		if ($item !== null) {
			return $this->driveSearch->file(
				meta: $this->brokeredRequest(method: 'GET', path: $item . '?$select=name,file'),
				content: $this->brokeredRequest(method: 'GET', path: $item . '/content'),
			);
		}

		$paths = $this->delegatedSearch->paths(handle: $handle);
		if ($paths === null) {
			return null;
		}

		$credentialId = $this->delegatedGrant(userId: $userId)['credentialId'];
		if ($credentialId === null) {
			return null;
		}

		return $this->delegatedSearch->file(
			entityType: $paths['entityType'],
			meta: $this->brokeredRequest(method: 'GET', path: $paths['meta'], credentialId: $credentialId),
			content: $this->brokeredRequest(method: 'GET', path: $paths['content'], credentialId: $credentialId),
		);
	}//end fetch()

	/**
	 * The person's own connected Microsoft account, or the notice why there is none to use.
	 *
	 * @param string $userId The person.
	 *
	 * @return array{credentialId: string|null, notice: string|null} Exactly one of the two is set.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
	 */
	private function delegatedGrant(string $userId): array {
		if ($this->personalCredentials === null) {
			return ['credentialId' => null, 'notice' => 'delegated-grant-missing'];
		}

		$provider = $this->appConfig->getValueString('integriq', self::DELEGATED_PROVIDER_KEY, self::DEFAULT_DELEGATED_PROVIDER);
		try {
			$ids = $this->personalCredentials->credentialIds(provider: $provider, userId: $userId);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				sprintf('%s: the connected accounts could not be read — %s', $this->getId(), $exception->getMessage()),
				['adapter' => $this->getId()]
			);
			return ['credentialId' => null, 'notice' => 'search-failed'];
		}

		if ($ids === []) {
			return ['credentialId' => null, 'notice' => 'delegated-grant-missing'];
		}

		if (count($ids) > 1) {
			return ['credentialId' => null, 'notice' => 'delegated-grant-ambiguous'];
		}

		return ['credentialId' => $ids[0], 'notice' => null];
	}//end delegatedGrant()
}//end class
