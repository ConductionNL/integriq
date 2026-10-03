<?php

/**
 * Integriq JsonSchemaChecker.
 *
 * Checks a message against a JSON Schema document with Opis JSON Schema.
 *
 * @category Service
 * @package  OCA\Integriq\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * JSON Schema checks with Opis, which OpenRegister ships (design, "Where it fits").
 *
 * Every error is reported under the path of the value it is about. A missing
 * required property is reported under its own path (`/bsn`), not under the
 * object that lacks it, so a refusal names the field a partner forgot.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ValidationOutcome's named constructors build a value object; there is nothing to inject.
 */
class JsonSchemaChecker {

	/**
	 * How many errors Opis collects before it stops.
	 *
	 * @var int
	 */
	private const MAX_ERRORS = 100;

	/**
	 * Check a payload against a JSON Schema.
	 *
	 * @param string|array|object $schema  The schema: JSON text, or a decoded document.
	 * @param mixed               $payload The message, as decoded JSON (arrays or objects).
	 *
	 * @return ValidationOutcome
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function check(string|array|object $schema, mixed $payload): ValidationOutcome {
		$document = self::toJsonValue(value: $schema);
		if (is_string($schema) === true) {
			$document = json_decode($schema, false);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return ValidationOutcome::failure(message: 'The JSON Schema document does not parse: ' . json_last_error_msg());
			}
		}

		if (is_object($document) === false && is_bool($document) === false) {
			return ValidationOutcome::failure(message: 'The JSON Schema document is not an object');
		}

		$validator = new Validator(max_errors: self::MAX_ERRORS, stop_at_first_error: false);

		try {
			$result = $validator->validate(self::toJsonValue(value: $payload), $document);
		} catch (\Throwable $e) {
			return ValidationOutcome::failure(message: 'The JSON Schema document cannot be used: ' . $e->getMessage());
		}

		$error = $result->error();
		if ($error === null) {
			return ValidationOutcome::valid();
		}

		return ValidationOutcome::failed(errors: $this->flatten(error: $error, formatter: new ErrorFormatter()));
	}//end check()

	/**
	 * The leaf errors of an Opis error tree, each under its data path.
	 *
	 * @param ValidationError $error     The error.
	 * @param ErrorFormatter  $formatter Interpolates the message.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private function flatten(ValidationError $error, ErrorFormatter $formatter): array {
		$subErrors = $error->subErrors();
		if ($subErrors !== []) {
			$errors = [];
			foreach ($subErrors as $subError) {
				$errors = array_merge($errors, $this->flatten(error: $subError, formatter: $formatter));
			}

			return $errors;
		}

		$path = self::pointer(segments: $error->data()->fullPath());
		$message = $formatter->formatErrorMessage($error);

		if ($error->keyword() === 'required') {
			$errors = [];
			foreach ((array)($error->args()['missing'] ?? []) as $property) {
				$errors[] = [
					'path' => rtrim($path, '/') . '/' . self::escape(segment: (string)$property),
					'message' => 'The required property "' . (string)$property . '" is missing',
				];
			}

			if ($errors !== []) {
				return $errors;
			}
		}

		return [['path' => $path, 'message' => $message]];
	}//end flatten()

	/**
	 * A JSON pointer from path segments.
	 *
	 * @param array<int, string|int> $segments The segments.
	 *
	 * @return string
	 */
	private static function pointer(array $segments): string {
		if ($segments === []) {
			return '/';
		}

		return '/' . implode('/', array_map(static fn ($segment): string => self::escape(segment: (string)$segment), $segments));
	}//end pointer()

	/**
	 * Escape one JSON pointer segment (RFC 6901).
	 *
	 * @param string $segment The segment.
	 *
	 * @return string
	 */
	private static function escape(string $segment): string {
		return str_replace(['~', '/'], ['~0', '~1'], $segment);
	}//end escape()

	/**
	 * A PHP value in the shape Opis reads JSON in: objects as stdClass.
	 *
	 * An associative array becomes an object and a list stays a list, the
	 * way json_decode() without `assoc` would have read the message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function toJsonValue(mixed $value): mixed {
		if (is_array($value) === false && is_object($value) === false) {
			return $value;
		}

		return json_decode((string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION), false);
	}//end toJsonValue()
}//end class
