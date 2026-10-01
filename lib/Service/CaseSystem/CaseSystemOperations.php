<?php

/**
 * Answers the five case-system operations for a source of type case-system.
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Http\Message\ResponseInterface;

/**
 * In-process dispatch for a case-system source (design D1).
 *
 * CallService hands a case-system source here instead of sending an HTTP
 * request, the way it hands a soap source to SOAPService, and writes the call
 * log, redaction and rate limit from the PSR-7 answer as for any source.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */
class CaseSystemOperations {

	/**
	 * The source type this class answers.
	 */
	public const SOURCE_TYPE = 'case-system';

	/**
	 * The five operations, as paths under /case-system/.
	 */
	public const OPERATIONS = ['read-case', 'list-documents', 'read-document', 'add-document', 'create-case'];

	/**
	 * Constructor.
	 *
	 * @param ZgwCaseSystem  $zgw  The ZGW mapping.
	 * @param CaseSystemMock $mock The fixtures for mock mode.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	public function __construct(
		private readonly ZgwCaseSystem $zgw,
		private readonly CaseSystemMock $mock,
	) {
	}//end __construct()

	/**
	 * Answer one call on a case-system source.
	 *
	 * @param ObjectEntity        $source   The case-system source.
	 * @param string              $endpoint The endpoint called, /case-system/<operation>.
	 * @param array<string,mixed> $config   The request config (body as a JSON string, or json).
	 *
	 * @return ResponseInterface A JSON answer; a refusal carries {message}.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	public function handle(ObjectEntity $source, string $endpoint, array $config): ResponseInterface {
		$operation = self::operationOf(endpoint: $endpoint);
		if (in_array($operation, self::OPERATIONS, true) === false) {
			return self::json(status: 404, answer: ['message' => self::unknownOperationMessage(operation: $operation)]);
		}

		$body = self::bodyOf(config: $config);
		if ($body === null) {
			return self::json(status: 400, answer: ['message' => $operation . ' needs a JSON object as its body.']);
		}

		$sourceData = $source->getObject();
		$configuration = (array)($sourceData['configuration'] ?? []);

		try {
			if (filter_var($configuration['mock'] ?? false, FILTER_VALIDATE_BOOLEAN) === true) {
				return self::json(status: 200, answer: $this->mock->run(operation: $operation, body: $body));
			}

			return self::json(
				status: 200,
				answer: $this->zgw->run(
					operation: $operation,
					body: $body,
					configuration: $configuration,
					sourceName: (string)($sourceData['name'] ?? '')
				)
			);
		} catch (CaseSystemRefusal $refusal) {
			return self::json(status: $refusal->getStatus(), answer: ['message' => $refusal->getMessage()]);
		}
	}//end handle()

	/**
	 * The message for an operation that does not exist.
	 *
	 * @param string $operation The operation asked for.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	public static function unknownOperationMessage(string $operation): string {
		return 'The case system has no operation "' . $operation . '". It answers: ' . implode(', ', self::OPERATIONS) . '.';
	}//end unknownOperationMessage()

	/**
	 * The operation named by an endpoint.
	 *
	 * @param string $endpoint The endpoint, /case-system/<operation>, optionally with a query.
	 *
	 * @return string The operation, or the endpoint itself when it is not under /case-system/.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function operationOf(string $endpoint): string {
		$path = trim((string)parse_url($endpoint, PHP_URL_PATH), '/');
		if (str_starts_with($path, 'case-system/') === true) {
			return substr($path, strlen('case-system/'));
		}

		return $path;
	}//end operationOf()

	/**
	 * The request body as an array, or null when it is not a JSON object.
	 *
	 * @param array<string,mixed> $config The request config.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function bodyOf(array $config): ?array {
		if (is_array($config['json'] ?? null) === true) {
			return $config['json'];
		}

		$decoded = json_decode((string)($config['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end bodyOf()

	/**
	 * A JSON answer.
	 *
	 * @param integer             $status The status.
	 * @param array<string,mixed> $answer The body.
	 *
	 * @return ResponseInterface
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
	 */
	private static function json(int $status, array $answer): ResponseInterface {
		return new Response(
			$status,
			['Content-Type' => 'application/json'],
			(string)json_encode($answer, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
		);
	}//end json()
}//end class
