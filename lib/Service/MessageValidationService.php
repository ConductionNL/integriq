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
		$document = (string)($messageSchema['document'] ?? '');

		return match ($kind) {
			'json-schema' => $this->jsonSchema->check(schema: $document, payload: $payload),
			'xsd' => $this->xsd->check(xsd: $document, payload: $payload),
			'openapi' => $this->openApi->check(document: $document, payload: $payload, context: $context),
			default => ValidationOutcome::failure(
				message: 'Message schema kind "' . $kind . '" is not checked by integriq; the message is refused rather than passed unchecked'
			),
		};
	}//end validate()
}//end class
