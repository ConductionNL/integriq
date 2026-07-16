<?php

/**
 * OpenConnector EnkelvoudigInformatieObject Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `enkelvoudiginformatieobject`
 * resource. Structurally identical field set between `1.0` and `1.6`
 * (VNG: no breaking changes in the stability line — see design.md's delta
 * table); this translator guards `vertrouwelijkheidaanduiding` and
 * `status` enum conformance. `bestandsdelen` (chunked binary upload) is
 * NOT handled — procest's document service never implements it either
 * (`inhoud` is always a `_downloadUrl`), so there is nothing to translate
 * (see design.md "Out of Scope").
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
 * Translates the `enkelvoudiginformatieobject` resource between `1.0` and `1.6`.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class InformatieObjectTranslator extends AbstractZgwResourceTranslator
{

    /**
     * The 4 documented `status` enum values, verified from procest's own
     * `LoadDefaultZgwMappings` `valueMapping`.
     *
     * @var string[]
     */
    private const STATUS_VALUES = [
        'in_bewerking',
        'ter_vaststelling',
        'definitief',
        'gearchiveerd',
    ];

    /**
     * Fields procest's own `LoadDefaultZgwMappings::getEnkelvoudigInformatieObjectMapping()`
     * always emits and this translator treats as mandatory on both sides.
     *
     * @var string[]
     */
    private const REQUIRED_FIELDS = [
        'url',
        'uuid',
        'identificatie',
        'bronorganisatie',
        'titel',
        'informatieobjecttype',
    ];

    /**
     * {@inheritDoc}
     *
     * @return string The resource slug.
     *
     * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function getResource(): string
    {
        return 'enkelvoudiginformatieobject';

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
    public function translateToV16(array $payload): array
    {
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
    public function translateToV1x(array $payload): array
    {
        return $this->guardedPassthrough(payload: $payload);

    }//end translateToV1x()

    /**
     * Shared guard-then-passthrough body for both directions.
     *
     * @param array<string, mixed> $payload The payload under translation.
     *
     * @return array<string, mixed> The guarded payload, unchanged.
     */
    private function guardedPassthrough(array $payload): array
    {
        $this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);
        $this->guardEnum(payload: $payload, field: 'vertrouwelijkheidaanduiding', allowed: self::VERTROUWELIJKHEID_VALUES);
        $this->guardEnum(payload: $payload, field: 'status', allowed: self::STATUS_VALUES);

        return $payload;

    }//end guardedPassthrough()
}//end class
