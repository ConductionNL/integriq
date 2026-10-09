<?php

/**
 * Integriq MessageValidationService.
 *
 * Checks a message against a stored message schema.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use OCA\Integriq\Service\MessageValidation\XsdChecker;

/**
 * One entry point for every message check (design, "Where it fits").
 *
 * The endpoint request and answer, a synchronization's source objects and its
 * target bodies all call {@see validate()} with the `message_schema` object
 * they reference. The schema's `kind` picks the checker. A kind this service
 * does not check is refused by name rather than passed, so a declared
 * validation can never be silently skipped.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ValidationOutcome::failure is the value object's named constructor.
 */
class MessageValidationService {

	/**
	 * Constructor.
	 *
	 * @param JsonSchemaChecker $jsonSchema JSON Schema documents.
	 * @param XsdChecker        $xsd        XSD documents, offline.
	 * @param OpenApiChecker    $openApi    OpenAPI operations.
	 */
	public function __construct(
		private readonly JsonSchemaChecker $jsonSchema,
		private readonly XsdChecker $xsd,
		private readonly OpenApiChecker $openApi,
	) {

	}//end __construct()

	/**
	 * Check a message against a message schema.
	 *
	 * @param array $messageSchema The `message_schema` object: `kind` and `document`.
	 * @param mixed $payload       The message: decoded JSON, or XML text for `xsd`.
	 * @param array $context       For `openapi`: `operationId` or `method` + `path`, `direction`, `status`.
	 *
	 * @return ValidationOutcome
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
	 */
	public function validate(array $messageSchema, mixed $payload, array $context = []): ValidationOutcome {
		$kind = (string)($messageSchema['kind'] ?? '');
		$document = self::documentText(document: ($messageSchema['document'] ?? ''));

		return match ($kind) {
			'json-schema' => $this->jsonSchema->check(schema: $document, payload: $payload),
			'xsd' => $this->xsd->check(xsd: $document, payload: $payload),
			'openapi' => $this->openApi->check(document: $document, payload: $payload, context: $context),
			default => ValidationOutcome::failure(
				message: 'Message schema kind "' . $kind . '" is not checked by integriq; the message is refused rather than passed unchecked'
			),
		};
	}//end validate()

	/**
	 * Why a message schema's document cannot be stored, or null when it parses for its kind.
	 *
	 * A `register-schema` kind references a register schema instead of
	 * carrying a document, so there is nothing to parse.
	 *
	 * @param array $messageSchema The `message_schema` object: `kind` and `document`.
	 *
	 * @return string|null The parser's message.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
	 */
	public function documentProblem(array $messageSchema): ?string {
		$document = self::documentText(document: ($messageSchema['document'] ?? ''));

		return match ((string)($messageSchema['kind'] ?? '')) {
			'json-schema' => $this->jsonSchema->documentProblem(schema: $document),
			'xsd' => $this->xsd->documentProblem(xsd: $document),
			'openapi' => $this->openApi->documentProblem(document: $document),
			default => null,
		};
	}//end documentProblem()

	/**
	 * The document as text, whatever shape OpenRegister handed it back in.
	 *
	 * OpenRegister decodes a stored text that parses as JSON, so a JSON Schema
	 * or JSON OpenAPI document comes back from a read as an array, while an
	 * XSD or YAML document stays text. A cast turned the array into "Array",
	 * and every message was refused as "does not parse". An array or object is
	 * encoded back to JSON, which every checker parses (JSON is YAML too).
	 *
	 * @param mixed $document The stored document.
	 *
	 * @return string The document text.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	private static function documentText(mixed $document): string {
		if (is_array($document) === true || is_object($document) === true) {
			return (string)json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		}

		return (string)$document;
	}//end documentText()
}//end class
