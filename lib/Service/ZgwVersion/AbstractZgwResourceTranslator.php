<?php

/**
 * OpenConnector Abstract ZGW Resource Translator.
 *
 * Shared literal-leak guard helpers for every {@see ZgwResourceTranslatorInterface}
 * implementation: required-field presence, enum-value conformance, and
 * array-vs-scalar structural checks. Concrete translators call these from
 * their `translateToV16()`/`translateToV1x()` bodies rather than
 * duplicating the same defensive checks 7 times — see design.md's delta
 * table for which guard applies to which resource/field.
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

use OCA\OpenConnector\Exception\ZgwLiteralLeakException;

/**
 * Literal-leak guard helpers shared by every resource translator.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
abstract class AbstractZgwResourceTranslator implements ZgwResourceTranslatorInterface
{

    /**
     * The 8 documented `vertrouwelijkheidaanduiding` enum values, verified
     * from procest's own `LoadDefaultZgwMappings` `valueMapping`.
     *
     * @var string[]
     */
    protected const VERTROUWELIJKHEID_VALUES = [
        'openbaar',
        'beperkt_openbaar',
        'intern',
        'zaakvertrouwelijk',
        'vertrouwelijk',
        'confidentieel',
        'geheim',
        'zeer_geheim',
    ];

    /**
     * Assert every field in `$required` is present in `$payload` and not null.
     *
     * @param array<string, mixed> $payload  The payload under translation.
     * @param string[]             $required The required field names.
     *
     * @return void
     *
     * @throws ZgwLiteralLeakException Naming the first missing field.
     */
    protected function requireFields(array $payload, array $required): void
    {
        foreach ($required as $field) {
            if (array_key_exists($field, $payload) === false || $payload[$field] === null) {
                throw new ZgwLiteralLeakException(
                    message: 'Resource "'.$this->getResource().'" is missing required field "'.$field.'".'
                );
            }
        }

    }//end requireFields()

    /**
     * Assert `$payload[$field]`, when present, is one of `$allowed`.
     *
     * Absent/null values are NOT rejected here — pair with
     * {@see requireFields()} when the field is also mandatory.
     *
     * @param array<string, mixed> $payload The payload under translation.
     * @param string               $field   The field to check.
     * @param string[]             $allowed The documented allowed values.
     *
     * @return void
     *
     * @throws ZgwLiteralLeakException When the value is set but outside `$allowed`.
     */
    protected function guardEnum(array $payload, string $field, array $allowed): void
    {
        if (array_key_exists($field, $payload) === false || $payload[$field] === null) {
            return;
        }

        if (in_array($payload[$field], $allowed, true) === false) {
            throw new ZgwLiteralLeakException(
                message: 'Resource "'.$this->getResource().'" field "'.$field.'" carries an out-of-set '
                    .'enum value "'.((string) $payload[$field]).'" — refusing to forward an unrecognised value.'
            );
        }

    }//end guardEnum()

    /**
     * Assert `$payload[$field]`, when present, is an array — never a bare
     * scalar leaking through unresolved (the exact class of bug procest's
     * own `LoadDefaultZgwMappings::getZaakTypeMapping()` `besluittypen`
     * literal-string quirk could produce if ever fed through unmapped).
     *
     * @param array<string, mixed> $payload The payload under translation.
     * @param string               $field   The field to check.
     *
     * @return void
     *
     * @throws ZgwLiteralLeakException When the value is set but not an array.
     */
    protected function guardArrayField(array $payload, string $field): void
    {
        if (array_key_exists($field, $payload) === false || $payload[$field] === null) {
            return;
        }

        if (is_array($payload[$field]) === false) {
            throw new ZgwLiteralLeakException(
                message: 'Resource "'.$this->getResource().'" field "'.$field.'" MUST be an array, got a bare '
                    .'scalar ("'.((string) $payload[$field]).'") — refusing to forward an unresolved literal.'
            );
        }

    }//end guardArrayField()
}//end class
