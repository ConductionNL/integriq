<?php

/**
 * Integriq PreCheckService.
 *
 * Asks a configured outside system whether a declared act may proceed, and
 * reports what it said: allow, refuse, or no answer. A timeout reports no
 * answer and never allow, because a system that did not answer did not agree.
 * What a refusal means to the caller's act is the caller's decision; integriq
 * reports, it does not decide.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Runs a blocking pre-check against an outside system.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-blocking-pre-check-asks-an-outside-system-and-reports-the-answer-req-ocd-007
 */
class PreCheckService {

	/**
	 * The outside system agreed.
	 *
	 * @var string
	 */
	public const ALLOW = 'allow';

	/**
	 * The outside system refused.
	 *
	 * @var string
	 */
	public const REFUSE = 'refuse';

	/**
	 * The outside system did not answer, which is not the same as agreeing.
	 *
	 * @var string
	 */
	public const NO_ANSWER = 'no answer';

	/**
	 * How long to wait when the configuration names no timeout.
	 *
	 * @var int
	 */
	public const DEFAULT_TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 * @param CallRecorder $recorder Records the attempt, answered or not.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly CallRecorder $recorder,
	) {

	}//end __construct()

	/**
	 * Ask the outside system.
	 *
	 * @param array<string,mixed> $configuration The pre-check: `url`, `timeoutSeconds`, `headers`,
	 *                                           and `mock` with a `fixture` for mock mode.
	 * @param array<string,mixed> $payload What the act is about.
	 *
	 * @return array{decision:string,reason:string,raw:array<string,mixed>} What it said.
	 */
	public function ask(array $configuration, array $payload): array {
		if (($configuration['mock'] ?? false) === true) {
			$fixture = ($configuration['fixture'] ?? []);
			$answer = $this->interpret(is_array($fixture) === true ? $fixture : []);
			$this->record($configuration, $payload, $answer, 200);
			return $answer;
		}

		$url = trim((string)($configuration['url'] ?? ''));
		if ($url === '') {
			$answer = [
				'decision' => self::NO_ANSWER,
				'reason' => 'The pre-check names no system to ask.',
				'raw' => [],
			];
			$this->record($configuration, $payload, $answer, 0);
			return $answer;
		}

		$timeout = (int)($configuration['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'headers' => array_merge(
						['Accept' => 'application/json'],
						(is_array(($configuration['headers'] ?? null)) === true ? $configuration['headers'] : [])
					),
					'json' => $payload,
					'timeout' => $timeout,
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);
			$statusCode = (int)$response->getStatusCode();
		} catch (Throwable $exception) {
			// A timeout, a refused connection and a torn-down TLS session all
			// land here, and none of them is permission.
			$answer = [
				'decision' => self::NO_ANSWER,
				'reason' => 'No answer within ' . $timeout . ' seconds: ' . $exception->getMessage(),
				'raw' => [],
			];
			$this->record($configuration, $payload, $answer, 0);
			return $answer;
		}

		$answer = $this->interpret(is_array($decoded) === true ? $decoded : []);
		$this->record($configuration, $payload, $answer, $statusCode);

		return $answer;

	}//end ask()

	/**
	 * Read an answer out of what the outside system sent.
	 *
	 * Anything this does not recognise is no answer. Reading an unknown shape
	 * as permission is how a pre-check becomes decoration.
	 *
	 * @param array<string,mixed> $body What it sent.
	 *
	 * @return array{decision:string,reason:string,raw:array<string,mixed>} The answer.
	 */
	private function interpret(array $body): array {
		$decision = strtolower(trim((string)($body['decision'] ?? $body['state'] ?? '')));
		$reason = (string)($body['reason'] ?? '');

		if ($decision === self::ALLOW || $decision === 'allowed' || $decision === 'pass') {
			return ['decision' => self::ALLOW, 'reason' => $reason, 'raw' => $body];
		}

		if ($decision === self::REFUSE || $decision === 'refused' || $decision === 'fail') {
			return ['decision' => self::REFUSE, 'reason' => $reason, 'raw' => $body];
		}

		return [
			'decision' => self::NO_ANSWER,
			'reason' => ($reason === '' ? 'The answer did not say allow or refuse.' : $reason),
			'raw' => $body,
		];

	}//end interpret()

	/**
	 * Record the attempt, whatever came of it.
	 *
	 * @param array<string,mixed> $configuration The pre-check configuration.
	 * @param array<string,mixed> $payload What was asked.
	 * @param array<string,mixed> $answer What came back.
	 * @param int $statusCode The HTTP status, zero when there was none.
	 *
	 * @return void
	 */
	private function record(array $configuration, array $payload, array $answer, int $statusCode): void {
		$this->recorder->record(
			[
				'target' => (string)($configuration['url'] ?? 'pre-check'),
				'request' => ['method' => 'POST', 'url' => (string)($configuration['url'] ?? ''), 'body' => $payload],
				'response' => $answer['raw'],
				'statusCode' => $statusCode,
				'statusMessage' => $answer['decision'] . ($answer['reason'] === '' ? '' : ': ' . $answer['reason']),
				'kind' => CallRecorder::KIND_TRIGGERED,
			]
		);

	}//end record()

}//end class
