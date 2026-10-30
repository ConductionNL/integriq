<?php

/**
 * Contract of the IwmoIjwSyncService.
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

use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\IwmoIjw\IwmoIjwProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see IwmoIjwSyncService} that other components call.
 */
interface IwmoIjwSyncServiceInterface {
	/**
	 * Translate and dispatch one outbound bericht (toewijzing or declaratie).
	 *
	 * @param array $input The push payload: `kind` (`toewijzing`|`declaratie`), `domain`
	 *                     (`wmo`|`jw`), the berichttype fields (see design.md's outbound field
	 *                     table), plus optional `caseReference`/`caseRegister`/`caseSchema` for
	 *                     later retour write-back correlation.
	 *
	 * @return array{ref: string, berichttype: string} The correlation reference and berichtcode.
	 *
	 * @throws IwmoIjwTranslationException When a required field is missing/empty (no record persisted —
	 *                                     nothing was sent).
	 * @throws IwmoIjwProviderException When no active source is configured, or the transport fails
	 *                                  (a `status: failed` `iwmo_ijw_message` IS persisted first).
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
	 */
	public function sendMessage(array $input): array;

	/**
	 * Receive, verify-translate, and process one retour envelope.
	 *
	 * Signature verification happens in the controller (mirrors
	 * `PeppolController::inbound()`) — by the time this method runs the
	 * caller has already established the request is authentic. This method
	 * NEVER throws out to the controller: any failure is logged, an
	 * `iwmo_ijw_message` record is persisted when enough context exists,
	 * and the method returns — the controller always acknowledges receipt.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003
	 */
	public function receiveReturn(string $rawXml): void;

	/**
	 * Re-attempt every `iwmo_ijw_message` row with `status: failed` or
	 * `pending` through the same transport used by `sendBericht()` — driven
	 * by `IwmoIjwRetryJob`. Per-message isolation: one row's retry
	 * exception is logged and skipped, never aborting the sweep.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active iWMO/iJW source (`type=iwmo-ijw`, `isEnabled=true`).
	 *
	 * `findAll()` LOCATES the source but ALWAYS renders it — it has no `_render`
	 * parameter and calls `renderEntities()` unconditionally — so the credentials
	 * {@see IStandardsClient} needs are stripped by the write-only boundary
	 * (ocon#242 / openregister#459). {@see RawSourceResolver::resolveRaw()} re-reads
	 * the located uuid with `_render: false`, the ONLY read that survives it.
	 * `_rbac: false` does NOT — the strip is schema-gated (the ocon#212/#226 lesson).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws IwmoIjwProviderException When no active iWMO/iJW source is configured.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The iWMO/iJW source's `configuration` object.
	 *
	 * @return IwmoIjwProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): IwmoIjwProviderInterface;
}
