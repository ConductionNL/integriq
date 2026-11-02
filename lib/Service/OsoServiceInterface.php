<?php

/**
 * Contract of the OsoService.
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

use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see OsoService} that other components call.
 */
interface OsoServiceInterface {
	/**
	 * Translate and dispatch one outbound OSO export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return array{ref: string, direction: string, status: string} The provider ref, direction, status.
	 *
	 * @throws OsoTranslationException When a required field is missing/empty.
	 * @throws OsoProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function sendExport(string $kenmerk, array $payload): array;

	/**
	 * Receive, verify-parse, and process one inbound OSO overstapdossier.
	 *
	 * Signature verification happens in the controller. This method NEVER
	 * throws out to the controller: any failure is logged, an
	 * `oso_message` record is persisted, and the method returns.
	 *
	 * @param string $rawXml The raw inbound dossier XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
	 */
	public function receiveImport(string $rawXml): void;

	/**
	 * Receive, verify-translate, and process one export acknowledgement/retour.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function receiveReturn(string $rawXml): void;

	/**
	 * Re-attempt every export `oso_message` row with `status: failed` or
	 * `pending` through the currently configured transport — driven by
	 * `OsoRetryJob`. Per-message isolation.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active OSO source (`type=oso`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws OsoProviderException When no active OSO source is configured.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function resolveActiveSource(): ObjectEntity;
}
