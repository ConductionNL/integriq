<?php

/**
 * Integriq UWLR/Edu-V export payload guard.
 *
 * The two literal-leak checks every UWLR/Edu-V envelope translator
 * (Basispoort sync, Entree content sync, Edu-V export, UWLR export) runs:
 * every required payload field is present before the envelope is built,
 * and no unresolved template marker survives in the rendered XML. Both
 * refuse with UwlrEduVTranslationException, naming the envelope kind.
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\RequiredPayloadFields;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Refuses an export payload or rendered envelope that would leak unresolved data.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
final class ExportPayloadGuard {

	/**
	 * Constructor.
	 *
	 * @param StufLiteralLeakGuard $leakGuard     Shared literal-leak scan.
	 * @param string               $envelopeLabel The envelope kind with its article, as refusals name it (`a Basispoort sync`).
	 */
	public function __construct(
		private readonly StufLiteralLeakGuard $leakGuard,
		private readonly string $envelopeLabel,
	) {

	}//end __construct()

	/**
	 * Assert every required field is present and non-empty — the
	 * literal-leak guard's first line of defence.
	 *
	 * @param array<int, string> $fields  The required field names, in check order.
	 * @param array              $payload The field payload.
	 *
	 * @return void
	 *
	 * @throws UwlrEduVTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function assertRequiredFields(array $fields, array $payload): void {
		$missing = RequiredPayloadFields::firstMissing(fields: $fields, payload: $payload);
		if ($missing !== null) {
			throw new UwlrEduVTranslationException(
				message: 'Required field "' . $missing . '" is missing or empty for ' . $this->envelopeLabel
					. ' — refusing to build an envelope with unresolved data.'
			);
		}

	}//end assertRequiredFields()

	/**
	 * Scan the rendered envelope for leftover unresolved template markers.
	 *
	 * @param string $xml The fully rendered envelope XML.
	 *
	 * @return void
	 *
	 * @throws UwlrEduVTranslationException When any marker survives.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new UwlrEduVTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
