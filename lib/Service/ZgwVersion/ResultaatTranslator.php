<?php

/**
 * OpenConnector Resultaat Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `resultaat` resource. Ships this
 * change's one VERIFIED field-level `1.6` delta (see design.md's delta
 * table): VNG's own `zaken-api` `CHANGELOG.rst` (1.5.1, issue #2157)
 * records `resultaattoelichting` as a removed, deprecated duplicate of
 * `toelichting`. procest never emitted the duplicate, so `translateToV16()`
 * is a guard-only no-op; `translateToV1x()` (translating DOWN to a legacy
 * pre-1.5.1 consumer that may still expect the duplicate) mirrors
 * `toelichting` into `resultaattoelichting` for back-compat.
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
 * Translates the `resultaat` resource between `1.0` and `1.6`.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class ResultaatTranslator extends AbstractZgwResourceTranslator
{

    /**
     * Fields procest's own `LoadDefaultZgwMappings::getResultaatMapping()`
     * always emits and this translator treats as mandatory on both sides.
     *
     * @var string[]
     */
    private const REQUIRED_FIELDS = [
        'url',
        'uuid',
        'zaak',
        'resultaattype',
    ];

    /**
     * The legacy, pre-1.5.1 duplicate field name — VERIFIED removed by VNG
     * `zaken-api` `CHANGELOG.rst` 1.5.1 (issue #2157).
     *
     * @var string
     */
    private const LEGACY_DUPLICATE_FIELD = 'resultaattoelichting';

    /**
     * The canonical field the legacy duplicate mirrored.
     *
     * @var string
     */
    private const CANONICAL_FIELD = 'toelichting';

    /**
     * {@inheritDoc}
     *
     * @return string The resource slug.
     *
     * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function getResource(): string
    {
        return 'resultaat';

    }//end getResource()

    /**
     * {@inheritDoc}
     *
     * Drops the legacy `resultaattoelichting` duplicate — a no-op for
     * procest's own payloads (which never emit it), but defensive against
     * a caller that still supplies the legacy shape.
     *
     * @param array<string, mixed> $payload The `1.0`-shaped resource payload.
     *
     * @return array<string, mixed> The `1.6`-shaped payload.
     *
     * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function translateToV16(array $payload): array
    {
        $this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);

        unset($payload[self::LEGACY_DUPLICATE_FIELD]);

        return $payload;

    }//end translateToV16()

    /**
     * {@inheritDoc}
     *
     * Re-adds `resultaattoelichting` mirroring `toelichting`, for a legacy
     * consumer that still expects the pre-1.5.1 duplicate field.
     *
     * @param array<string, mixed> $payload The `1.6`-shaped resource payload.
     *
     * @return array<string, mixed> The `1.0`-shaped payload.
     *
     * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function translateToV1x(array $payload): array
    {
        $this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);

        if (array_key_exists(self::CANONICAL_FIELD, $payload) === true) {
            $payload[self::LEGACY_DUPLICATE_FIELD] = $payload[self::CANONICAL_FIELD];
        }

        return $payload;

    }//end translateToV1x()
}//end class
