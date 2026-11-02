<?php

/**
 * Contract of the DsoIngestService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\DsoTranslationException;
use OCA\Integriq\Service\Dso\DsoConnectorProviderInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see DsoIngestService} that other components call.
 */
interface DsoIngestServiceInterface {
	/**
	 * Persist one already-verified, already-parsed DSO Verzoek
	 * (`received`), then resolve + apply {@see DsoRequestTranslator}
	 * (`mapped`|`failed`, isolated to this verzoek).
	 *
	 * Runs inside `DsoConnection::runAs()`: every read and write here happens
	 * as the DSO connection's account, under its own RBAC. A repeated delivery
	 * of a verzoek that is already stored is matched by `verzoekId` (and
	 * `volgnummer`, when the payload has one): `mapped`, `failed` or
	 * `handed_off` answers with the stored record and writes nothing;
	 * `received` (a crash after the first save) is finished, not duplicated.
	 *
	 * @param array<string, mixed> $parsedRequest The {@see DSOParserService::parseRequest()} output.
	 * @param DsoIdentity|null     $identity      The account and connection the intake acts as;
	 *                                            recorded in `receivedVia` and handed to the bijlage job.
	 *
	 * @return ObjectEntity The persisted `dso_verzoek` record (any status).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function ingest(array $parsedRequest, ?DsoIdentity $identity = null): ObjectEntity;

	/**
	 * Read one dso_verzoek's current state.
	 *
	 * @param string $uuid The `dso_verzoek` uuid.
	 *
	 * @return array<string, mixed> The verzoek's object data, plus `id`.
	 *
	 * @throws DsoTranslationException When no verzoek exists for the uuid.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 */
	public function getRequest(string $uuid): array;

	/**
	 * List dso_verzoek records, optionally filtered by status (e.g. `mapped`
	 * is the set the case system turns into cases).
	 *
	 * @param string|null $status Optional status filter.
	 * @param integer $limit Maximum number of records to return.
	 *
	 * @return array<int, array<string, mixed>> The matching verzoeken (each with `id`).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	public function listVerzoeken(?string $status = null, int $limit = 100): array;

	/**
	 * Build and dispatch one outbound `status` (voortgangsinformatie) or
	 * `besluit` message for a previously received verzoek, persisting a
	 * `dso_message` audit row regardless of outcome.
	 *
	 * @param string $requestUuid The `dso_verzoek` uuid this message concerns.
	 * @param string $type The message kind: `status` or `besluit`.
	 * @param array<string, mixed> $fields Type-specific fields (`status` for `type=status`;
	 *                                     `besluit`/`gemotiveerd` for `type=besluit`).
	 *
	 * @return array{ref: string, type: string, status: string} The dispatch outcome.
	 *
	 * @throws DsoTranslationException When the verzoek is unknown, or `type` is not recognised.
	 * @throws DsoProviderException When no active DSO source is configured, or the transport
	 *                              fails (a `status: failed` `dso_message` IS persisted first).
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function postOutbound(string $requestUuid, string $type, array $fields = []): array;

	/**
	 * Resolve the single active `dso` outbound source
	 * (`type=dso`, `isEnabled=true`), under the active user's rights.
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws DsoProviderException When no active source is configured.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Resolve the active `dso` source as an engine read.
	 *
	 * The source is admin-only configuration (`99-source-lockdown.json`). The
	 * bijlage job runs as the DSO connection's account, which need not be an
	 * admin, so it reads the source as the engine: `_rbac: false` and
	 * `_render: false`, a read only. It mirrors RawSourceResolver, without the
	 * active user's RBAC.
	 *
	 * @return ObjectEntity The resolved source, read raw.
	 *
	 * @throws DsoProviderException When no active source is configured.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function resolveActiveSourceAsEngine(): ObjectEntity;

	/**
	 * Resolve the outbound provider binding named by
	 * `configuration.provider` (`log`|`rest`), defaulting to the sandbox
	 * `log` provider when unset or unrecognised — new/unconfigured
	 * deployments never accidentally dispatch a live DSO-LV call.
	 *
	 * @param array<string, mixed> $configuration The `dso` source's `configuration` object.
	 *
	 * @return DsoConnectorProviderInterface The resolved provider.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): DsoConnectorProviderInterface;
}
