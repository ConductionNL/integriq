<?php

/**
 * Integriq EndpointMessageGate.
 *
 * Checks an endpoint's request and its proxied answer against the message
 * schemas the endpoint declares, and says whether the message is refused.
 *
 * @category Service
 * @package  OCA\Integriq\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The endpoint side of message validation (design D2 and D3).
 *
 * An endpoint declares `validation.request` and `validation.response`, each a
 * `message_schema` uuid with an optional OpenAPI `operationId`, and a `mode`.
 * Mode `record` (the default) checks and writes the errors to the call log.
 * Mode `refuse` answers a failing request 400 and a failing proxied answer
 * 502, both as `application/problem+json` listing the first twenty errors.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ValidationOutcome::failure is the value object's named constructor.
 */
class EndpointMessageGate {

	/**
	 * Check the inbound request body.
	 */
	public const REQUEST = 'request';

	/**
	 * Check the answer a proxied source gave.
	 */
	public const RESPONSE = 'response';

	/**
	 * How many errors a refusal lists (design D3).
	 */
	private const REFUSAL_ERRORS = 20;

	/**
	 * Constructor.
	 *
	 * @param MessageValidationService $validator Runs the message schema.
	 * @param ORObjectService          $objects   Reads the message schema and writes the call log.
	 * @param LoggerInterface          $logger    Records what record mode let through.
	 */
	public function __construct(
		private readonly MessageValidationService $validator,
		private readonly ORObjectService $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check one message against the schema the endpoint declares for that direction.
	 *
	 * @param array  $endpointData The endpoint object.
	 * @param string $direction    self::REQUEST or self::RESPONSE.
	 * @param mixed  $body         The message: raw text, or an already decoded value.
	 * @param array  $context      For OpenAPI: `method`, `path`, `status`.
	 *
	 * @return ValidationOutcome|null Null when the endpoint declares nothing for this direction.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function check(array $endpointData, string $direction, mixed $body, array $context = []): ?ValidationOutcome {
		$declared = ($endpointData['validation'][$direction] ?? null);
		if (is_array($declared) === false || (string)($declared['messageSchema'] ?? '') === '') {
			return null;
		}

		$messageSchema = $this->messageSchema(uuid: (string)$declared['messageSchema']);
		if ($messageSchema === null) {
			return ValidationOutcome::failure(
				message: 'Message schema ' . (string)$declared['messageSchema'] . ' does not exist, so the message could not be checked'
			);
		}

		$payload = $body;
		if (($messageSchema['kind'] ?? '') !== 'xsd' && is_string($body) === true) {
			$payload = json_decode($body, false);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return ValidationOutcome::failure(message: 'The message is not JSON: ' . json_last_error_msg());
			}
		}

		$context['direction'] = $direction;
		if ((string)($declared['operationId'] ?? '') !== '') {
			$context['operationId'] = (string)$declared['operationId'];
		}

		return $this->validator->validate(messageSchema: $messageSchema, payload: $payload, context: $context);
	}//end check()

	/**
	 * Whether a failing message is refused rather than recorded.
	 *
	 * @param array $endpointData The endpoint object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function refuses(array $endpointData): bool {
		return ($endpointData['validation']['mode'] ?? 'record') === 'refuse';
	}//end refuses()

	/**
	 * The refusal: 400 for a request, 502 for a proxied answer, as problem+json.
	 *
	 * @param ValidationOutcome $outcome   The failed outcome.
	 * @param string            $direction self::REQUEST or self::RESPONSE.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function refusal(ValidationOutcome $outcome, string $direction): JSONResponse {
		$status = 400;
		$title = 'The request does not match its message schema';
		if ($direction === self::RESPONSE) {
			$status = 502;
			$title = 'The source answered with a message that does not match its message schema';
		}

		$response = new JSONResponse(
			[
				'type' => 'about:blank',
				'title' => $title,
				'status' => $status,
				'errors' => $outcome->firstErrors(self::REFUSAL_ERRORS),
			],
			$status
		);
		$response->addHeader('Content-Type', 'application/problem+json');

		return $response;
	}//end refusal()

	/**
	 * A finding as the call log keeps it.
	 *
	 * @param ValidationOutcome $outcome      The failed outcome.
	 * @param string            $direction    self::REQUEST or self::RESPONSE.
	 * @param array             $endpointData The endpoint object.
	 *
	 * @return array{direction: string, messageSchema: string, errors: array}
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function finding(ValidationOutcome $outcome, string $direction, array $endpointData): array {
		return [
			'direction' => $direction,
			'messageSchema' => (string)($endpointData['validation'][$direction]['messageSchema'] ?? ''),
			'errors' => $outcome->firstErrors(self::REFUSAL_ERRORS),
		];
	}//end finding()

	/**
	 * Write record mode's findings onto the call log of the proxied call.
	 *
	 * Without a call log (an endpoint on a register schema) the findings go
	 * to the server log only, so they are never dropped.
	 *
	 * @param ObjectEntity|null $callLog  The call log of the proxied call, if there is one.
	 * @param array             $findings The findings, from {@see finding()}.
	 * @param string            $endpoint The endpoint's uuid, for the server log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function record(?ObjectEntity $callLog, array $findings, string $endpoint): void {
		if ($findings === []) {
			return;
		}

		$this->logger->warning(
			'[integriq] endpoint ' . $endpoint . ' let a message through that does not match its message schema (mode record)',
			['findings' => $findings]
		);

		if ($callLog === null || (string)$callLog->getUuid() === '') {
			return;
		}

		try {
			$this->objects->saveObject(
				object: array_merge((array)$callLog->getObject(), ['validation' => $findings]),
				register: 'integriq',
				schema: 'call_log',
				uuid: (string)$callLog->getUuid(),
				_rbac: false,
				_multitenancy: false,
				silent: true,
				_validation: false
			);
		} catch (Throwable $failure) {
			$this->logger->error('[integriq] could not write validation findings to call log ' . $callLog->getUuid() . ': ' . $failure->getMessage());
		}
	}//end record()

	/**
	 * Read a message schema by uuid, in system context.
	 *
	 * @param string $uuid The message schema's uuid.
	 *
	 * @return array|null The message schema object, or null when it does not exist.
	 */
	private function messageSchema(string $uuid): ?array {
		try {
			$entity = $this->objects->find(
				id: $uuid,
				register: 'integriq',
				schema: 'message_schema',
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] message schema ' . $uuid . ' could not be read: ' . $failure->getMessage());
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->getObject();
	}//end messageSchema()
}//end class
