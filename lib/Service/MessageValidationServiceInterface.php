<?php

/**
 * Contract of the MessageValidationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\MessageValidation\ValidationOutcome;

/**
 * Public operations of {@see MessageValidationService} that other components call.
 */
interface MessageValidationServiceInterface {
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
	public function validate(array $messageSchema, mixed $payload, array $context = []): ValidationOutcome;

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
	public function documentProblem(array $messageSchema): ?string;
}
