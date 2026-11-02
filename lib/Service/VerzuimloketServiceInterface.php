<?php

/**
 * Contract of the VerzuimloketService.
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

use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see VerzuimloketService} that other components call.
 */
interface VerzuimloketServiceInterface {
	/**
	 * Translate and dispatch one outbound Verzuimloket melding.
	 *
	 * @param string $meldingType One of `eerste-melding`|`herhaalmelding`|`langdurig-relatief-verzuim`.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, meldingType: string, status: string} The provider ref, echoed
	 *                                                                 meldingType, and outcome status.
	 *
	 * @throws VerzuimloketTranslationException When a required field is missing/empty.
	 * @throws VerzuimloketProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendMelding(string $meldingType, string $kenmerk, array $payload): array;

	/**
	 * Receive, verify-translate, and process one DUO acknowledgement/retour.
	 *
	 * Signature verification happens in the controller. This method NEVER
	 * throws out to the controller: any failure is logged, a
	 * `verzuim_message` record is persisted when enough context exists, and
	 * the method returns.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function receiveReturn(string $rawXml): void;

	/**
	 * Re-attempt every `verzuim_message` row with `status: failed` or
	 * `pending` through the currently configured transport — driven by
	 * `VerzuimloketRetryJob`. Per-message isolation.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active Verzuimloket source (`type=verzuimloket`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws VerzuimloketProviderException When no active Verzuimloket source is configured.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function resolveActiveSource(): ObjectEntity;
}
