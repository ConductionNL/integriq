<?php

/**
 * Integriq Open Formulieren Form Field Mapper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Resolves one Open Formulieren `openformulieren_form_mapping` record's
 * `fieldMapping` configuration against a submission's raw submitted values,
 * producing the normalised `mapped*` properties that
 * {@see \OCA\Integriq\Service\OpenFormulierenIntakeService} persists
 * onto an `openformulieren_submission` object. This is the ONLY layer that
 * translates arbitrary Open Formulieren field keys into fleet-neutral
 * fields; OpenRegister's own `x-openregister-handoff` dialect (declared on
 * the `openformulieren_submission` schema) then maps those already-
 * normalised properties onto the `ns#Case` contract — a distinct, OR-owned
 * layer this class never touches (see design.md §2.1).
 *
 * Deliberately the mirror image of the known `oc-mapping-literal-leak` bug
 * class: Integriq's `sourceTargetMapping` returns the literal dot-path
 * string when a bare-path source key is absent, rather than erroring. A
 * declared `from`/`template` entry whose referenced key is absent from the
 * submitted values ALWAYS throws {@see MappingResolutionException} here —
 * it never returns the unresolved literal as if it were data (design.md §2.2).
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

/**
 * Resolves per-form `fieldMapping` config against submitted values.
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */
class FormFieldMapper {

	/**
	 * Contract fields this app's mapping layer is allowed to target.
	 * `source` (engine-filled provenance) and `requester` (no OR-managed
	 * party register to resolve against — see design.md §1.2) are
	 * deliberately absent: neither is settable from this layer.
	 *
	 * @var string[]
	 */
	public const MANDATORY_FIELDS = ['title', 'summary', 'channel'];

	/**
	 * Contract fields this layer MAY target but need not.
	 *
	 * @var string[]
	 */
	public const OPTIONAL_FIELDS = ['priority'];

	/**
	 * Expression kinds this layer's `fieldMapping` entries support.
	 *
	 * @var string[]
	 */
	private const EXPRESSION_TYPES = ['from', 'const', 'template'];

	/**
	 * Placeholder pattern for `template` expressions: `{{key}}`.
	 *
	 * @var string
	 */
	private const TEMPLATE_PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

	/**
	 * Validate a `fieldMapping` config: every mandatory contract field MUST
	 * be a declared key, every declared key MUST be a known contract field,
	 * and every entry MUST be a well-formed expression of a known type.
	 *
	 * @param array<string, mixed> $fieldMapping The `openformulieren_form_mapping.fieldMapping` config.
	 *
	 * @return void
	 *
	 * @throws MappingResolutionException When the config is invalid.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	public function validateConfig(array $fieldMapping): void {
		foreach (self::MANDATORY_FIELDS as $mandatoryField) {
			if (isset($fieldMapping[$mandatoryField]) === false) {
				throw new MappingResolutionException(
					message: 'Form mapping is missing mandatory contract field "' . $mandatoryField . '".'
				);
			}
		}

		$known = array_merge(self::MANDATORY_FIELDS, self::OPTIONAL_FIELDS);

		foreach ($fieldMapping as $field => $expression) {
			if (in_array($field, $known, true) === false) {
				throw new MappingResolutionException(
					message: 'Form mapping declares unknown target field "' . $field . '" '
					. '(only title/summary/channel/priority may be mapped at this layer; '
					. '"source" is engine-filled and "requester" is unsupported — see design.md).'
				);
			}

			$this->validateExpressionShape(field: (string)$field, expression: $expression);
		}

	}//end validateConfig()

	/**
	 * Resolve every declared entry in `fieldMapping` against submitted
	 * values, producing the normalised `mapped*` properties.
	 *
	 * @param array<string, mixed> $fieldMapping The (already validated) mapping config.
	 * @param array<string, mixed> $submittedValues The raw Open Formulieren submitted values.
	 *
	 * @return array<string, string> `mapped<Field>` (capitalised) => resolved value, for
	 *                               every field declared in `$fieldMapping`.
	 *
	 * @throws MappingResolutionException When a declared entry cannot resolve.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	public function map(array $fieldMapping, array $submittedValues): array {
		$result = [];

		foreach ($fieldMapping as $field => $expression) {
			$value = $this->resolveExpression(
				expression: (array)$expression,
				resolution: new FieldResolution(field: (string)$field, submittedValues: $submittedValues)
			);

			$property = 'mapped' . ucfirst((string)$field);
			$result[$property] = $value;
		}

		return $result;
	}//end map()

	/**
	 * Validate one expression's shape (type known, value present).
	 *
	 * @param string $field The target field name (for error messages).
	 * @param mixed $expression The declared expression.
	 *
	 * @return void
	 *
	 * @throws MappingResolutionException When malformed.
	 */
	private function validateExpressionShape(string $field, mixed $expression): void {
		if (is_array($expression) === false) {
			throw new MappingResolutionException(
				message: 'Form mapping entry "' . $field . '" MUST be an object, got ' . gettype($expression) . '.'
			);
		}

		$type = ($expression['type'] ?? null);
		if (in_array($type, self::EXPRESSION_TYPES, true) === false) {
			throw new MappingResolutionException(
				message: 'Form mapping entry "' . $field . '" declares unknown expression type "'
				. (string)$type . '" (must be one of: from, const, template).'
			);
		}

		if (isset($expression['value']) === false || is_string($expression['value']) === false) {
			throw new MappingResolutionException(
				message: 'Form mapping entry "' . $field . '" MUST declare a string "value".'
			);
		}

	}//end validateExpressionShape()

	/**
	 * Resolve one mapping expression against the submission.
	 *
	 * @param array<string, mixed> $expression The expression (`type`, `value`).
	 * @param FieldResolution      $resolution The field and the submission.
	 *
	 * @return string The resolved value.
	 *
	 * @throws MappingResolutionException When the expression is malformed or reads an absent key.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	private function resolveExpression(array $expression, FieldResolution $resolution): string {
		$this->validateExpressionShape(field: $resolution->field, expression: $expression);

		$type = (string)$expression['type'];
		$value = (string)$expression['value'];

		if ($type === 'const') {
			return $value;
		}

		if ($type === 'from') {
			return $this->submitted(key: $value, resolution: $resolution);
		}

		// $type === 'template'.
		return $this->interpolateTemplate(template: $value, resolution: $resolution);
	}//end resolveExpression()

	/**
	 * The submitted value under a key; an absent key is refused rather than leaking the key name.
	 *
	 * @param string          $key        The submitted key.
	 * @param FieldResolution $resolution The field and the submission.
	 *
	 * @return string
	 *
	 * @throws MappingResolutionException When the key is absent.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	private function submitted(string $key, FieldResolution $resolution): string {
		if (array_key_exists($key, $resolution->submittedValues) === false) {
			throw new MappingResolutionException(
				message: 'Form mapping field "' . $resolution->field . '" references submitted key "' . $key
				. '" which is absent from this submission — refusing to leak the literal '
				. 'key name as the resolved value.'
			);
		}

		return (string)$resolution->submittedValues[$key];
	}//end submitted()

	/**
	 * Expand the `{{ key }}` placeholders; any absent key is refused rather than leaking the template.
	 *
	 * @param string          $template   The template.
	 * @param FieldResolution $resolution The field and the submission.
	 *
	 * @return string
	 *
	 * @throws MappingResolutionException When a placeholder is absent.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	private function interpolateTemplate(string $template, FieldResolution $resolution): string {
		$submittedValues = $resolution->submittedValues;
		$missing = self::missingPlaceholders(template: $template, submittedValues: $submittedValues);
		if ($missing !== []) {
			throw new MappingResolutionException(
				message: 'Form mapping field "' . $resolution->field . '" template references placeholder(s) "'
				. implode('", "', $missing) . '" which are absent from this submission — '
				. 'refusing to leak the unexpanded template as the resolved value.'
			);
		}

		return (string)preg_replace_callback(
			self::TEMPLATE_PLACEHOLDER_PATTERN,
			static fn (array $matches): string => (string)$submittedValues[$matches[1]],
			$template
		);
	}//end interpolateTemplate()

	/**
	 * The template's placeholder keys the submission lacks, in order (a repeated key repeats).
	 *
	 * @param string               $template        The template.
	 * @param array<string, mixed> $submittedValues The submission's values.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
	 */
	private static function missingPlaceholders(string $template, array $submittedValues): array {
		preg_match_all(self::TEMPLATE_PLACEHOLDER_PATTERN, $template, $matches);

		return array_values(
			array_filter($matches[1], static fn (string $key): bool => array_key_exists($key, $submittedValues) === false)
		);
	}//end missingPlaceholders()
}//end class
