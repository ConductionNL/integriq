<?php

/**
 * Integriq Edu-V Export Envelope Translator.
 *
 * Translates an `edu-v` export payload (`eckId`, `schoolBrin`) for
 * exactly one of three qualified data services —
 * `onderwijsdeelnemers`, `onderwijsgroepen`, `onderwijsmedewerkers` — into
 * an Edu-V-style XML envelope naming its own `targetSchema`
 * (`EduV:Onderwijsdeelnemers`, `EduV:Onderwijsgroepen`,
 * `EduV:Onderwijsmedewerkers` respectively), per
 * `uwlr-eduv-basispoort-contract`'s three distinct seeds. Edu-V keurmerk
 * certification is per data service, not once per connection (M3(c)); the
 * three subtypes stay independently translatable so certifying one never
 * depends on another.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field, or an unqualified
 * data service, raises UwlrEduVTranslationException BEFORE any XML is
 * built.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-003-edu-v-export-envelope-translation-across-three-qualified-data-services
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\ExportEnvelope;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Edu-V export payload -> Edu-V XML envelope.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-003-edu-v-export-envelope-translation-across-three-qualified-data-services
 */
class EduVExportEnvelopeTranslator {

	/**
	 * The three Edu-V qualified data services, mapped to their own targetSchema.
	 *
	 * @var array<string, string>
	 */
	public const DATA_SERVICE_SCHEMAS = [
		'onderwijsdeelnemers' => 'EduV:Onderwijsdeelnemers',
		'onderwijsgroepen' => 'EduV:Onderwijsgroepen',
		'onderwijsmedewerkers' => 'EduV:Onderwijsmedewerkers',
	];

	/**
	 * Required fields for every Edu-V export payload.
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
	 * Translate an Edu-V export payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $dataService One of `onderwijsdeelnemers`, `onderwijsgroepen`, `onderwijsmedewerkers`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When the data service is unqualified, a required field
	 *                                      is missing/empty, or the rendered envelope still carries
	 *                                      an unresolved template marker.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-each-edu-v-subtype-names-its-own-targetschema
	 */
	public function translate(string $kenmerk, string $dataService, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'An Edu-V export requires a non-empty kenmerk (correlation id).');
		}

		if (isset(self::DATA_SERVICE_SCHEMAS[$dataService]) === false) {
			throw new UwlrEduVTranslationException(
				message: 'Unknown Edu-V data service "' . $dataService . '" — expected one of: '
					. implode(', ', array_keys(self::DATA_SERVICE_SCHEMAS)) . '.'
			);
		}

		$guard = new ExportPayloadGuard(leakGuard: $this->leakGuard, envelopeLabel: 'an Edu-V export');
		$guard->assertRequiredFields(fields: self::REQUIRED_FIELDS, payload: $payload);

		$targetSchema = self::DATA_SERVICE_SCHEMAS[$dataService];

		$envelope = new ExportEnvelope(rootName: 'EduVExport', kenmerk: $kenmerk);
		$envelope->header()->addText(name: 'targetSchema', value: $targetSchema);
		$envelope->stampMessageTime();
		$envelope->addBody(fields: self::REQUIRED_FIELDS, payload: $payload);
		$xml = $envelope->toXml();
		$guard->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()
}//end class
