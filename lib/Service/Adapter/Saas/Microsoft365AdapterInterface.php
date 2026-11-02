<?php

/**
 * Contract of the Microsoft365Adapter.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Public operations of {@see Microsoft365Adapter} that other components call.
 */
interface Microsoft365AdapterInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getId(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getLabel(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getIcon(): string;

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getRequiredApp(): ?string;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function getCapabilities(): array;

	/**
	 * List the signed-in user's calendar events.
	 *
	 * @return array<int,array<string,mixed>> Normalised event summaries.
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function listCalendarEvents(): array;

	/**
	 * List the signed-in user's mail METADATA (never message bodies).
	 *
	 * @return array<int,array<string,mixed>> Normalised mail-metadata rows.
	 *
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-4
	 */
	public function listMailMetadata(): array;

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
	public function list(string $register, string $schema, string $objectId, array $filters = []): array;

	/**
	 * Whether a Microsoft 365 credential is configured for this source.
	 *
	 * @return bool True when one is.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function isConnected(): bool;

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
	public function search(string $terms, ?string $from, ?string $to, array $entityTypes, int $limit, string $userId): array;

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
	public function fetch(string $handle, string $userId): ?array;
}
