<?php

/**
 * OpenConnector Status Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `status` resource. Structurally
 * identical field set between `1.0` and `1.6` (VNG: no breaking changes in
 * the stability line — see design.md's delta table); this translator's
 * value is required-field presence.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\ZgwVersion
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\ZgwVersion;

/**
 * Translates the `status` resource between `1.0` and `1.6`.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class StatusTranslator extends AbstractZgwResourceTranslator {

	/**
	 * Fields procest's own `LoadDefaultZgwMappings::getStatusMapping()`
	 * always emits and this translator treats as mandatory on both sides.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FIELDS = [
		'url',
		'uuid',
		'zaak',
		'statustype',
		'datumStatusGezet',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return string The resource slug.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function getResource(): string {
		return 'status';
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

		return $payload;
	}//end translateToV1x()
}//end class
