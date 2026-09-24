<?php

/**
 * Integriq SourceTestService.
 *
 * The one test call against a source. `SourcesController::test` used to hold
 * this body inline. The connection health job needs the same call, so it
 * lives here and both use it: a probe and a "Test connection" click can then
 * never disagree about what testing a source means.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Runs a test call against a source without writing a call log.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */
class SourceTestService {

	/**
	 * The call failed before a response came back.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * The call returned without response data.
	 *
	 * @var string
	 */
	public const OUTCOME_NO_RESPONSE = 'no-response';

	/**
	 * The call returned a response.
	 *
	 * @var string
	 */
	public const OUTCOME_RESPONSE = 'response';

	/**
	 * Constructor.
	 *
	 * @param CallService $callService The HTTP call engine.
	 * @param LoggerInterface $logger Logs a failed call.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fire a test call with `persistLog: false`.
	 *
	 * An interactive test and a scheduled probe return the live response but
	 * must not write a CallLog: persisting one runs a full OpenRegister save and
	 * mutates the source's rate-limit state. Any engine failure is caught, so a
	 * caller always gets a readable outcome instead of an exception.
	 *
	 * @param ObjectEntity $source The source object.
	 * @param string $endpoint The endpoint path, empty for the source root.
	 * @param string $method The HTTP method.
	 * @param array<string,mixed> $config Guzzle options: headers, query, body.
	 *
	 * @return array{outcome:string,result:?array,statusCode:?int,statusMessage:string,error:string}
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function run(ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = []): array {
		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $method,
				config: $config,
				persistLog: false
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Source test failed: ' . $e->getMessage(),
				['app' => 'integriq', 'sourceId' => $source->getUuid(), 'exception' => $e]
			);
			return $this->outcome(outcome: self::OUTCOME_FAILED, result: null, error: $e->getMessage());
		}

		$result = $callLog->getObject();
		if (is_array($result) === false || isset($result['response']) === false) {
			// An early-exit CallLog (disabled source, exhausted rate limit)
			// carries its code and message at the top level, not in `response`.
			if (is_array($result) === false) {
				$result = null;
			}

			return $this->outcome(outcome: self::OUTCOME_NO_RESPONSE, result: $result, error: '');
		}

		return $this->outcome(outcome: self::OUTCOME_RESPONSE, result: $result, error: '');
	}//end run()

	/**
	 * Build the outcome array.
	 *
	 * @param string $outcome One of the OUTCOME_* constants.
	 * @param array<string,mixed>|null $result The call log data, when there is one.
	 * @param string $error The exception message for a failed call.
	 *
	 * @return array{outcome:string,result:?array,statusCode:?int,statusMessage:string,error:string}
	 */
	private function outcome(string $outcome, ?array $result, string $error): array {
		$statusCode = $result['response']['statusCode'] ?? $result['statusCode'] ?? null;
		$statusMessage = $result['response']['statusMessage'] ?? $result['statusMessage'] ?? '';

		$code = null;
		if (is_numeric($statusCode) === true) {
			$code = (int)$statusCode;
		}

		return [
			'outcome' => $outcome,
			'result' => $result,
			'statusCode' => $code,
			'statusMessage' => (string)$statusMessage,
			'error' => $error,
		];
	}//end outcome()
}//end class
