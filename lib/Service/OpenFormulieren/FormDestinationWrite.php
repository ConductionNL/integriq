<?php

/**
 * Integriq FormDestinationWrite.
 *
 * Turns one `openformulieren_form_mapping` record into the write OpenRegister's
 * form submit path takes, and a mapped submission into its payload.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

/**
 * The destination write of a form mapping: `{ register, schema, mapping }`.
 *
 * The mapping's contract fields (title, summary, channel, priority) are resolved
 * by FormFieldMapper; each one is written to the destination property its
 * `propertyMap` names, by default the dossiq case's own names. The submission id
 * always travels as `externalReference`, and `fixedValues` are written as given.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
 */
final class FormDestinationWrite {

	/**
	 * The destination register when the mapping names none.
	 */
	public const DEFAULT_REGISTER = 'dossiq';

	/**
	 * The destination schema when the mapping names none.
	 */
	public const DEFAULT_SCHEMA = 'case';

	/**
	 * The destination property the submission id is written to.
	 */
	public const REFERENCE_PROPERTY = 'externalReference';

	/**
	 * Contract field => destination property, when the mapping has no `propertyMap`.
	 * `priority` is not written by default: a dossiq case derives it.
	 */
	public const DEFAULT_PROPERTY_MAP = [
		'title' => 'title',
		'summary' => 'description',
		'channel' => 'intakeChannel',
	];

	/**
	 * The write for one mapping record.
	 *
	 * @param array<string, mixed> $mapping The `openformulieren_form_mapping` object.
	 *
	 * @return array{register: string, schema: string, mapping: array{fields: list<array{field: string, property: string}>, fixed: array<string, mixed>}}
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public static function writeFor(array $mapping): array {
		return [
			'register' => self::nonEmpty(value: ($mapping['destinationRegister'] ?? null), default: self::DEFAULT_REGISTER),
			'schema' => self::nonEmpty(value: ($mapping['destinationSchema'] ?? null), default: self::DEFAULT_SCHEMA),
			'mapping' => self::destinationMapping(mapping: $mapping),
		];
	}//end writeFor()

	/**
	 * The OpenRegister mapping (`fields` and `fixed`) of one mapping record.
	 *
	 * @param array<string, mixed> $mapping The `openformulieren_form_mapping` object.
	 *
	 * @return array{fields: list<array{field: string, property: string}>, fixed: array<string, mixed>}
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-mapping-must-be-valid-against-its-case-schema-before-it-is-active
	 */
	public static function destinationMapping(array $mapping): array {
		$fields = [];
		foreach (self::propertyMap(mapping: $mapping) as $field => $property) {
			if (array_key_exists($field, (array)($mapping['fieldMapping'] ?? [])) === true) {
				$fields[] = ['field' => $field, 'property' => $property];
			}
		}

		$fields[] = ['field' => self::REFERENCE_PROPERTY, 'property' => self::REFERENCE_PROPERTY];
		$fixed    = ($mapping['fixedValues'] ?? []);
		if (is_array($fixed) === false) {
			$fixed = [];
		}

		return ['fields' => $fields, 'fixed' => $fixed];
	}//end destinationMapping()

	/**
	 * The payload for the submit path: the resolved contract fields, keyed by
	 * contract field name, and the submission id as `externalReference`.
	 *
	 * @param array<string, mixed> $mapped       FormFieldMapper's result (`mappedTitle`, ...).
	 * @param string               $submissionId The Open Formulieren submission id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public static function payloadFor(array $mapped, string $submissionId): array {
		$payload = [];
		foreach ($mapped as $key => $value) {
			if (str_starts_with((string)$key, 'mapped') === true) {
				$payload[lcfirst(substr((string)$key, 6))] = $value;
			}
		}

		$payload[self::REFERENCE_PROPERTY] = $submissionId;

		return $payload;
	}//end payloadFor()

	/**
	 * Contract field => destination property for one mapping record.
	 *
	 * @param array<string, mixed> $mapping The `openformulieren_form_mapping` object.
	 *
	 * @return array<string, string>
	 */
	private static function propertyMap(array $mapping): array {
		$map = ($mapping['propertyMap'] ?? null);
		if (is_array($map) === false || $map === []) {
			return self::DEFAULT_PROPERTY_MAP;
		}

		$result = [];
		foreach ($map as $field => $property) {
			if (is_string($property) === true && $property !== '') {
				$result[(string)$field] = $property;
			}
		}

		return $result;
	}//end propertyMap()

	/**
	 * A non-empty string, else the default.
	 *
	 * @param mixed  $value   The candidate.
	 * @param string $default The fallback.
	 *
	 * @return string
	 */
	private static function nonEmpty(mixed $value, string $default): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $default;
	}//end nonEmpty()
}//end class
