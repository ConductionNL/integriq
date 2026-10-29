<?php

/**
 * Contract of the RodService.
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

use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see RodService} that other components call.
 */
interface RodServiceInterface {
	/**
	 * Translate and dispatch one outbound ROD bericht.
	 *
	 * @param string $berichtsoort One of `inschrijving`|`uitschrijving`|`verblijfsgegevens`|`schooladvies`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, berichtsoort: string, status: string} The provider ref, echoed
	 *                                                                  berichtsoort, and outcome status.
	 *
	 * @throws RodTranslationException When a required field is missing/empty (no record persisted —
	 *                                 nothing was sent).
	 * @throws RodProviderException When no active source is configured, or the transport fails (a
	 *                              `status: failed` `rod_message` IS persisted first).
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendBericht(string $berichtsoort, string $kenmerk, array $payload): array;

	/**
	 * Receive, verify-translate, and process one DUO acknowledgement/retour.
	 *
	 * Signature verification happens in the controller (mirrors
	 * `IwmoIjwController::inbound()`) — by the time this method runs the
	 * caller has already established the request is authentic. This method
	 * NEVER throws out to the controller: any failure is logged, a
	 * `rod_message` record is persisted when enough context exists, and the
	 * method returns — the controller always acknowledges receipt.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function receiveReturn(string $rawXml): void;

	/**
	 * Re-attempt every `rod_message` row with `status: failed` or `pending`
	 * through the currently configured transport — driven by
	 * `RodRetryJob`. Per-message isolation: one row's retry exception is
	 * logged and skipped, never aborting the sweep.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active ROD source (`type=rod`, `isEnabled=true`).
	 *
	 * See {@see RawSourceResolver} for why a raw re-read by uuid is required
	 * (ocon#242 / openregister#459) — `findAll()` always renders, stripping
	 * the credentials `RodEdukoppelingClient` needs.
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws RodProviderException When no active ROD source is configured.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function resolveActiveSource(): ObjectEntity;
}
