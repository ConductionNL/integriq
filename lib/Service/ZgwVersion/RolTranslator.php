<?php

/**
 * OpenConnector Rol Translator.
 *
 * `1.0` ↔ `1.6` translator for the ZGW `rol` resource. This is the shim's
 * one DOCUMENTED LOSSY translator: procest's own
 * `LoadDefaultZgwMappings::getRolMapping()` stores `betrokkeneIdentificatie`
 * as a single opaque scalar and never emits `betrokkeneType` — the real
 * ZGW `Rol` resource (both `1.0`-canonical per the official OAS, and
 * `1.6`) requires `betrokkeneType` as the discriminator enum
 * (`natuurlijk_persoon`|`niet_natuurlijk_persoon`|`vestiging`|
 * `organisatorische_eenheid`|`medewerker`) alongside it. This is a
 * pre-existing FLEET gap, not a `1.0`↔`1.6` version delta — see design.md
 * "Rol: documented lossy translation".
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
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\ZgwVersion;

/**
 * Translates the `rol` resource between `1.0` and `1.6`.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class RolTranslator extends AbstractZgwResourceTranslator
{

    /**
     * Fields procest's own `LoadDefaultZgwMappings::getRolMapping()`
     * always emits and this translator treats as mandatory on both sides.
     *
     * @var string[]
     */
    private const REQUIRED_FIELDS = [
        'url',
        'uuid',
        'zaak',
        'roltype',
        'betrokkeneIdentificatie',
    ];

    /**
     * The real ZGW `betrokkeneType` discriminator field, absent from the fleet's shape.
     *
     * @var string
     */
    private const BETROKKENE_TYPE_FIELD = 'betrokkeneType';

    /**
     * The documented, best-effort default this translator applies when
     * translating UP to `1.6` — NOT a verified fact about any specific
     * `rol` payload's real participant type, see class docblock.
     *
     * @var string
     */
    private const BETROKKENE_TYPE_DEFAULT = 'natuurlijk_persoon';

    /**
     * {@inheritDoc}
     *
     * @return string The resource slug.
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function getResource(): string
    {
        return 'rol';

    }//end getResource()

    /**
     * {@inheritDoc}
     *
     * Adds `betrokkeneType` with a documented best-effort default when
     * absent — LOSSY: the fleet's own source data does not carry the real
     * discriminator, see class docblock.
     *
     * @param array<string, mixed> $payload The `1.0`-shaped resource payload.
     *
     * @return array<string, mixed> The `1.6`-shaped payload.
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function translateToV16(array $payload): array
    {
        $this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);

        if (array_key_exists(self::BETROKKENE_TYPE_FIELD, $payload) === false
            || $payload[self::BETROKKENE_TYPE_FIELD] === null
        ) {
            $payload[self::BETROKKENE_TYPE_FIELD] = self::BETROKKENE_TYPE_DEFAULT;
        }

        return $payload;

    }//end translateToV16()

    /**
     * {@inheritDoc}
     *
     * Strips `betrokkeneType` — the fleet's own shape never carries it, so
     * this direction is lossless with respect to what procest itself reads.
     *
     * @param array<string, mixed> $payload The `1.6`-shaped resource payload.
     *
     * @return array<string, mixed> The `1.0`-shaped payload.
     *
     * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
     */
    public function translateToV1x(array $payload): array
    {
        $this->requireFields(payload: $payload, required: self::REQUIRED_FIELDS);

        unset($payload[self::BETROKKENE_TYPE_FIELD]);

        return $payload;

    }//end translateToV1x()
}//end class
