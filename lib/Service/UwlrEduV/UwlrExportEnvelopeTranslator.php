<?php

/**
 * Integriq UWLR Export Envelope Translator.
 *
 * Translates a `uwlr` export payload (`eckId`, `schoolBrin`) for exactly
 * one of three subtypes — `pupil`, `group`, `teacher` — supplied by
 * learniq's `DataExchangeRunHandler` for the `uwlr` job type, into a
 * UWLR-style XML envelope. See design.md "Open Questions" — the exact
 * UWLR wire format was not in the corpus; the shape follows the same
 * Edukoppeling/StUF convention as the other three adapters in this lane,
 * isolated behind this one class.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field, or an unknown
 * subtype, raises UwlrEduVTranslationException BEFORE any XML is built.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\ExportEnvelope;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * UWLR export payload -> UWLR XML envelope.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */
class UwlrExportEnvelopeTranslator {

	/**
	 * The three UWLR export subtypes.
	 *
	 * @var array<int, string>
	 */
	public const SUBTYPES = ['pupil', 'group', 'teacher'];

	/**
	 * Required fields for every UWLR export payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = ['eckId', 'schoolBrin'];

	/**
	 * Constructor.
	 *
	 * @param StufLiteralLeakGuard $leakGuard Shared literal-leak scan.
	 */
	public function __construct(
		private readonly StufLiteralLeakGuard $leakGuard = new StufLiteralLeakGuard(),
	) {

	}//end __construct()

	/**
	 * Translate a UWLR export payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $subtype One of `pupil`, `group`, `teacher`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When the subtype is unknown, a required field is
	 *                                      missing/empty, or the rendered envelope still carries
	 *                                      an unresolved template marker.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-pupil-export-payload-translates-to-a-valid-envelope
	 */
	public function translate(string $kenmerk, string $subtype, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'A UWLR export requires a non-empty kenmerk (correlation id).');
		}

		if (in_array($subtype, self::SUBTYPES, true) === false) {
			throw new UwlrEduVTranslationException(
				message: 'Unknown UWLR subtype "' . $subtype . '" — expected one of: ' . implode(', ', self::SUBTYPES) . '.'
			);
		}

		$guard = new ExportPayloadGuard(leakGuard: $this->leakGuard, envelopeLabel: 'a UWLR export');
		$guard->assertRequiredFields(fields: self::REQUIRED_FIELDS, payload: $payload);

		$envelope = new ExportEnvelope(rootName: 'UwlrExport', kenmerk: $kenmerk);
		$envelope->header()->addText(name: 'subtype', value: $subtype);
		$envelope->stampMessageTime();
		$envelope->addBody(fields: self::REQUIRED_FIELDS, payload: $payload);
		$xml = $envelope->toXml();
		$guard->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()
}//end class
