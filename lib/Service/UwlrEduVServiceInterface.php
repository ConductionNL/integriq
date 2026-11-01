<?php

/**
 * Contract of the UwlrEduVService.
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

use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see UwlrEduVService} that other components call.
 */
interface UwlrEduVServiceInterface {
	/**
	 * Translate and dispatch one outbound UWLR export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $subtype One of `pupil`, `group`, `teacher`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty or the subtype is unknown.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
	 */
	public function sendUwlrExport(string $kenmerk, string $subtype, array $payload): array;

	/**
	 * Translate and dispatch one outbound Edu-V export.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $dataService One of `onderwijsdeelnemers`, `onderwijsgroepen`, `onderwijsmedewerkers`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty or the data service is unqualified.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-003-edu-v-export-envelope-translation-across-three-qualified-data-services
	 */
	public function sendEduVExport(string $kenmerk, string $dataService, array $payload): array;

	/**
	 * Translate and dispatch one Basispoort sync.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function syncBasispoort(string $kenmerk, array $payload): array;

	/**
	 * Translate and dispatch one Entree content sync.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return array{ref: string, target: string, status: string}
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty.
	 * @throws UwlrEduVProviderException When no active source is configured, or the transport fails.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
	 */
	public function syncEntreeContent(string $kenmerk, array $payload): array;

	/**
	 * Receive, verify-translate, and process one acknowledgement/retour,
	 * shared across all four targets.
	 *
	 * @param string $rawXml The raw retour envelope XML.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
	 */
	public function receiveReturn(string $rawXml): void;

	/**
	 * Re-attempt every `uwlr_eduv_message` row with `status: failed` through
	 * the currently configured transport — driven by `UwlrEduVRetryJob`.
	 * Per-message isolation, across every target.
	 *
	 * @return integer The number of rows successfully retried.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-a-failed-send-persists-and-is-retried-in-isolation
	 */
	public function retryFailed(): int;

	/**
	 * Resolve the single active UWLR/Edu-V source (`type=uwlr-eduv`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source, raw (credentials intact).
	 *
	 * @throws UwlrEduVProviderException When no active source is configured.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint
	 */
	public function resolveActiveSource(): ObjectEntity;
}
