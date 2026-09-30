<?php

/**
 * RegisterSchemaValidator: validate a payload the way OpenRegister would.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Repair\InitializeRegister;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use ReflectionMethod;

/**
 * Builds the integriq register as InitializeRegister imports it (the base
 * descriptor with every register.d fragment merged in, sorted) and validates
 * a payload against one of its schemas with opis/json-schema, applying the
 * two transforms OpenRegister's ValidateObject applies first: a `$ref` on a
 * string property is a relation marker, not a JSON Schema reference, so it is
 * dropped; and a top-level empty string or empty array on a property that is
 * not required is removed before validation.
 *
 * A unit test that only checks what was handed to saveObject() cannot see a
 * payload the register refuses. This can.
 */
class RegisterSchemaValidator {

	/**
	 * The merged descriptor, built once per process.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $descriptor = null;

	/**
	 * Validate one payload against one schema of the integriq register.
	 *
	 * @param string $schemaSlug The schema slug, e.g. `call_log`.
	 * @param array<string,mixed> $object The payload as handed to saveObject().
	 *
	 * @return array<string,mixed> Formatted errors, empty when the register accepts it.
	 */
	public static function errors(string $schemaSlug, array $object): array {
		$schema = (self::descriptor()['components']['schemas'][$schemaSlug] ?? null);
		if (is_array($schema) === false) {
			return ['/' => ['No schema "' . $schemaSlug . '" in the integriq register.']];
		}

		$schema = self::stripRelationRefs(schema: $schema);
		$required = (array)($schema['required'] ?? []);
		foreach ($object as $key => $value) {
			if (in_array($key, $required, true) === true) {
				continue;
			}

			if ($value === '' || $value === []) {
				unset($object[$key]);
			}
		}

		$result = (new Validator())->validate(
			json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());

	}//end errors()

	/**
	 * The merged register descriptor.
	 *
	 * @return array<string,mixed> The descriptor.
	 */
	public static function descriptor(): array {
		if (self::$descriptor !== null) {
			return self::$descriptor;
		}

		$root = dirname(__DIR__, 2);
		$descriptor = json_decode(
			(string)file_get_contents($root . '/lib/Settings/integriq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $merge->invoke(null, $descriptor, $fragment);
			}
		}

		self::$descriptor = $descriptor;
		return $descriptor;

	}//end descriptor()

	/**
	 * Drop the relation `$ref` from string properties, recursively.
	 *
	 * @param array<string,mixed> $schema The schema or sub-schema.
	 *
	 * @return array<string,mixed> The schema without relation refs.
	 */
	private static function stripRelationRefs(array $schema): array {
		if (isset($schema['$ref']) === true && (($schema['type'] ?? null) === 'string' || isset($schema['type']) === false)) {
			unset($schema['$ref']);
		}

		foreach (['properties'] as $container) {
			if (is_array(($schema[$container] ?? null)) === true) {
				foreach ($schema[$container] as $name => $sub) {
					if (is_array($sub) === true) {
						$schema[$container][$name] = self::stripRelationRefs(schema: $sub);
					}
				}
			}
		}

		if (is_array(($schema['items'] ?? null)) === true) {
			$schema['items'] = self::stripRelationRefs(schema: $schema['items']);
		}

		return $schema;

	}//end stripRelationRefs()

}//end class
