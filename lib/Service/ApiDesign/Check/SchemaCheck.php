<?php

/**
 * Integriq SchemaCheck.
 *
 * Passes when the value satisfies a small JSON Schema.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ApiDesign\Check
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ApiDesign\Check;

/**
 * `schema`: the value satisfies `schema`, read with the keywords the design
 * rules need: type, required, enum, pattern, minLength, properties and anyOf.
 * Any other keyword is refused when the rule is saved, so a rule cannot pass
 * by naming a keyword this check ignores. An absent field fails.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class SchemaCheck implements RuleCheckInterface {

	/**
	 * The keywords this check reads.
	 *
	 * @var list<string>
	 */
	private const KEYWORDS = ['type', 'required', 'enum', 'pattern', 'minLength', 'properties', 'anyOf'];

	/**
	 * The name a rule uses for this check, as in `then.function`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function name(): string {
		return 'schema';
	}//end name()

	/**
	 * Check one value.
	 *
	 * @param mixed                $value   The selected value, null when the field is absent.
	 * @param bool                 $present Whether the field is present at all.
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null Why the value fails, or null when it passes.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function check(mixed $value, bool $present, array $options): ?string {
		if ($present === false) {
			return 'is missing';
		}

		return self::validate(value: $value, schema: (array) ($options['schema'] ?? []), path: '');
	}//end check()

	/**
	 * Why a rule's options are unusable, or null when they are fine.
	 *
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function invalidOptions(array $options): ?string {
		if (is_array($options['schema'] ?? null) === false) {
			return 'schema needs a schema object';
		}

		return self::unsupported(schema: $options['schema']);
	}//end invalidOptions()

	/**
	 * The first keyword this check does not read, anywhere in a schema.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return string|null
	 */
	private static function unsupported(array $schema): ?string {
		foreach ($schema as $keyword => $value) {
			if (in_array($keyword, self::KEYWORDS, true) === false) {
				return sprintf('schema keyword "%s" is not supported', $keyword);
			}

			$children = match ($keyword) {
				'properties' => (array) $value,
				'anyOf' => (array) $value,
				default => [],
			};
			foreach ($children as $child) {
				$found = 'schema members are objects';
				if (is_array($child) === true) {
					$found = self::unsupported(schema: $child);
				}

				if ($found !== null) {
					return $found;
				}
			}
		}

		return null;
	}//end unsupported()

	/**
	 * Why a value fails a schema, or null.
	 *
	 * @param mixed                $value  The value.
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $path   Where the value sits under the checked node.
	 *
	 * @return string|null
	 */
	private static function validate(mixed $value, array $schema, string $path): ?string {
		$at = '';
		if ($path !== '') {
			$at = ' at '.$path;
		}

		if (isset($schema['type']) === true && self::isType(value: $value, type: (string) $schema['type']) === false) {
			return sprintf('is not of type %s%s', $schema['type'], $at);
		}

		if (isset($schema['enum']) === true && in_array($value, (array) $schema['enum'], true) === false) {
			return sprintf('is not an allowed value%s', $at);
		}

		if (is_string($value) === true) {
			if (isset($schema['minLength']) === true && mb_strlen($value) < (int) $schema['minLength']) {
				return sprintf('is too short%s', $at);
			}

			if (isset($schema['pattern']) === true && preg_match(PatternCheck::regex(pattern: (string) $schema['pattern']), $value) !== 1) {
				return sprintf('does not match %s%s', $schema['pattern'], $at);
			}
		}

		if (is_array($value) === true) {
			foreach ((array) ($schema['required'] ?? []) as $member) {
				if (array_key_exists($member, $value) === false) {
					return sprintf('lacks %s%s', $member, $at);
				}
			}

			foreach ((array) ($schema['properties'] ?? []) as $member => $memberSchema) {
				if (array_key_exists($member, $value) === true) {
					$failure = self::validate(value: $value[$member], schema: (array) $memberSchema, path: $path.'/'.$member);
					if ($failure !== null) {
						return $failure;
					}
				}
			}
		}

		if (isset($schema['anyOf']) === true) {
			$failures = [];
			foreach ((array) $schema['anyOf'] as $option) {
				$failure = self::validate(value: $value, schema: (array) $option, path: $path);
				if ($failure === null) {
					return null;
				}

				$failures[] = $failure;
			}

			return implode(' and ', $failures);
		}

		return null;
	}//end validate()

	/**
	 * Whether a decoded JSON value has a JSON Schema type.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The type.
	 *
	 * @return bool
	 */
	private static function isType(mixed $value, string $type): bool {
		return match ($type) {
			'object' => is_array($value) === true && ($value === [] || array_is_list($value) === false),
			'array' => is_array($value) === true && array_is_list($value) === true,
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => is_int($value) || is_float($value),
			'boolean' => is_bool($value),
			'null' => $value === null,
			default => false,
		};
	}//end isType()
}//end class
