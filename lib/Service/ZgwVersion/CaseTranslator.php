<?php

/**
 * Integriq Zaak Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `zaak` resource. Per VNG's own
 * public characterisation of the `1.6` stability line ("no breaking
 * changes to the resource model" — see design.md's delta table), the
 * `zaak` field set is structurally identical between `1.0` and `1.6`; this
 * translator's value is the literal-leak guard (required-field presence +
 * `vertrouwelijkheidaanduiding` enum conformance), not a field rename.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ZgwVersion
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ZgwVersion;

/**
 * Translates the `zaak` resource between `1.0` and `1.6`.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class CaseTranslator extends AbstractZgwResourceTranslator {

	/**
	 * Fields procest's own `LoadDefaultZgwMappings::getZaakMapping()`
	 * always emits and this translator treats as mandatory on both sides.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FIELDS = [
		'url',
		'uuid',
		'identificatie',
		'bronorganisatie',
		'omschrijving',
		'zaaktype',
		'registratiedatum',
		'startdatum',
		'vertrouwelijkheidaanduiding',
		'verantwoordelijkeOrganisatie',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return string The resource slug.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function getResource(): string {
		return 'zaak';
	}//end getResource()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload The `1.0`-shaped resource payload.
	 *
	 * @return array<string, mixed> The `1.6`-shaped payload.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function translateToV16(array $payload): array {
		$this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);
		$this->guardEnum(payload: $payload, field: 'vertrouwelijkheidaanduiding', allowed: self::VERTROUWELIJKHEID_VALUES);

		// Structurally identical to 1.0 (VNG: no breaking resource-model
		// change in 1.6) — see design.md's delta table. The `?expand=`
		// query mechanism is a negotiation-layer concern, not a payload
		// field, handled by ZgwVersionNegotiationService::stripUnsupportedExpandHint().
		return $payload;
	}//end translateToV16()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload The `1.6`-shaped resource payload.
	 *
	 * @return array<string, mixed> The `1.0`-shaped payload.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function translateToV1x(array $payload): array {
		$this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);
		$this->guardEnum(payload: $payload, field: 'vertrouwelijkheidaanduiding', allowed: self::VERTROUWELIJKHEID_VALUES);

		return $payload;
	}//end translateToV1x()
}//end class
