<?php

/**
 * OpenConnector Shared StUF Literal-Leak Guard.
 *
 * Defense-in-depth scan for leftover unresolved template markers in a
 * rendered outbound envelope, shared by every StUF-family bridge in this
 * app. Extracted from
 * `IwmoIjw\OutboundMessageTranslator::assertNoUnresolvedPlaceholder()`
 * (iwmo-ijw-adapter, 2026-07-14) verbatim — same regex, same "scan the
 * fully rendered string, after the required-fields pre-check has already
 * run" contract. Each translator's pre-check (which required fields are
 * missing/empty) stays domain-specific and un-extracted; only this final
 * defence-in-depth string scan is genuinely shared, identical logic.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-literal-leak-guard-req-000
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Stuf;

/**
 * Scans a rendered XML string for leftover unresolved template markers.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-literal-leak-guard-req-000
 */
class StufLiteralLeakGuard {
	/**
	 * Whether the given rendered XML still carries an unresolved template marker
	 * (`{{...}}` or the literal `%%UNRESOLVED%%`).
	 *
	 * @param string $xml The fully rendered envelope XML.
	 *
	 * @return boolean True when a marker survives — the caller MUST refuse to send.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-literal-leak-guard-req-000
	 */
	public function hasUnresolvedPlaceholder(string $xml): bool {
		return (preg_match('/\{\{.*?\}\}|%%UNRESOLVED%%/', $xml) === 1);
	}//end hasUnresolvedPlaceholder()
}//end class
