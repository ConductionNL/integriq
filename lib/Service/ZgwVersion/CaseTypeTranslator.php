<?php

/**
 * Integriq ZaakType Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `zaaktype` resource. Structurally
 * identical field set between `1.0` and `1.6` (VNG: no breaking changes in
 * the stability line — see design.md's delta table); this translator adds
 * a `vertrouwelijkheidaanduiding` enum guard AND an array-structure guard
 * on `besluittypen`/`informatieobjecttypen` — the exact class of bug
 * procest's own `LoadDefaultZgwMappings::getZaakTypeMapping()`
 * `'besluittypen' => 'decisionTypes'` literal-value propertyMapping quirk
 * could produce if that raw internal fieldname ever leaked through
 * unresolved instead of being replaced by an actual array of URLs.
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
 * Translates the `zaaktype` resource between `1.0` and `1.6`.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class CaseTypeTranslator extends AbstractZgwResourceTranslator {

	/**
	 * Fields procest's own `LoadDefaultZgwMappings::getZaakTypeMapping()`
	 * always emits and this translator treats as mandatory on both sides.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FIELDS = [
		'url',
		'uuid',
		'identificatie',
		'omschrijving',
		'catalogus',
	];

	/**
	 * Fields that MUST be arrays-of-URLs, never a leaked bare scalar.
	 *
	 * @var string[]
	 */
	private const ARRAY_FIELDS = [
		'besluittypen',
		'informatieobjecttypen',
		'gerelateerdeZaaktypen',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return string The resource slug.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function getResource(): string {
		return 'zaaktype';
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
		return $this->guardedPassthrough(payload: $payload);
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
		return $this->guardedPassthrough(payload: $payload);
	}//end translateToV1x()

	/**
	 * Shared guard-then-passthrough body for both directions — `zaaktype`
	 * is structurally identical between `1.0` and `1.6` (VNG: no breaking
	 * resource-model change).
	 *
	 * @param array<string, mixed> $payload The payload under translation.
	 *
	 * @return array<string, mixed> The guarded payload, unchanged.
	 */
	private function guardedPassthrough(array $payload): array {
		$this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);
		$this->guardEnum(payload: $payload, field: 'vertrouwelijkheidaanduiding', allowed: self::VERTROUWELIJKHEID_VALUES);

		foreach (self::ARRAY_FIELDS as $field) {
			$this->guardArrayField(payload: $payload, field: $field);
		}

		return $payload;
	}//end guardedPassthrough()
}//end class
