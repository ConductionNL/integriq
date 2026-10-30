<?php

/**
 * Contract of the StufZknSyncService.
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

use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\StufZknProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see StufZknSyncService} that other components call.
 */
interface StufZknSyncServiceInterface {
	/**
	 * Receive, translate, upsert, and acknowledge one inbound StUF-ZKN SOAP envelope.
	 *
	 * Never throws out to the controller — every failure path (malformed XML, translation
	 * guard, OR write failure) is captured and shaped into a `Fo03` reply instead (mirrors
	 * `IwmoIjwSyncService::receiveReturn()`'s "the controller always gets something to send
	 * back" contract, adapted to StUF's own ack/fault berichten instead of a bare HTTP status).
	 *
	 * @param string $soapXml The raw inbound SOAP envelope XML, exactly as received on the wire.
	 *
	 * @return string The rendered `Bv03Bericht` (success) or `Fo03Bericht` (fault) reply XML.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-soap-endpoint-with-bv03-fo03-shaping-req-005
	 */
	public function receiveInbound(string $soapXml): string;

	/**
	 * Translate and dispatch one outbound `zakLk01` kennisgeving for an OR/ZGW zaak change.
	 *
	 * @param array $case The OR/ZGW zaak object fields — see design.md's outbound field table.
	 * @param string $processingKind `T` (create), `W` (update/status change), or `V` (vervallen).
	 *
	 * @return array{referentienummer: string, ref: string} The kennisgeving's own referentienummer
	 *                                                      and the transport-assigned/derived reference.
	 *
	 * @throws StufZknTranslationException When a required field is missing/empty (no record persisted —
	 *                                     nothing was sent).
	 * @throws StufZknProviderException When no active source is configured, or the transport fails
	 *                                  (a `status: failed` `stuf_message` IS persisted first).
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
	 */
	public function sendNotification(array $case, string $processingKind): array;

	/**
	 * Re-attempt every `stuf_message` row with `status: failed` (direction=outbound) through the
	 * currently configured provider. Per-message isolation: one row's retry exception is logged
	 * and skipped, never aborting the sweep — mirrors `IwmoIjwSyncService::retryFailed()`.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active StUF-ZKN source (`type=stuf-zkn`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws StufZknProviderException When no active StUF-ZKN source is configured.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The stuf-zkn source's `configuration` object.
	 *
	 * @return StufZknProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): StufZknProviderInterface;
}
