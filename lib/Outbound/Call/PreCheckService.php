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
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Throwable;

/**
 * Runs a blocking pre-check against an outside system.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-blocking-pre-check-asks-an-outside-system-and-reports-the-answer-req-ocd-007
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
		$run = new PreCheckRun(configuration: $configuration, payload: $payload);
		$answer = $this->answerFor(run: $run);
		$this->record(run: $run, answer: $answer);

		return $answer;

	}//end ask()

	/**
	 * The answer of the fixture in mock mode, of no system when none is named, else of the system asked.
	 *
	 * @param PreCheckRun $run The pre-check.
	 *
	 * @return array{decision:string,reason:string,raw:array<string,mixed>} What it said.
	 */
	private function answerFor(PreCheckRun $run): array {
		if (($run->configuration['mock'] ?? false) === true) {
			$fixture = ($run->configuration['fixture'] ?? []);
			if (is_array($fixture) === false) {
				$fixture = [];
			}

			$run->statusCode = 200;
			return $this->interpret(body: $fixture);
		}

		$url = trim((string)($run->configuration['url'] ?? ''));
		if ($url === '') {
			return [
				'decision' => self::NO_ANSWER,
				'reason' => 'The pre-check names no system to ask.',
				'raw' => [],
			];
		}

		return $this->askRemote(run: $run, url: $url);

	}//end answerFor()

	/**
	 * Ask the outside system and read its answer; no answer when it cannot be reached.
	 *
	 * @param PreCheckRun $run The pre-check.
	 * @param string      $url The system to ask.
	 *
	 * @return array{decision:string,reason:string,raw:array<string,mixed>} What it said.
	 */
	private function askRemote(PreCheckRun $run, string $url): array {
		try {
			$response = $this->post(run: $run, url: $url);
			$decoded = json_decode((string)$response->getBody(), true);
			$run->statusCode = (int)$response->getStatusCode();
		} catch (Throwable $exception) {
			// A timeout, a refused connection and a torn-down TLS session all
			// land here, and none of them is permission.
			return [
				'decision' => self::NO_ANSWER,
				'reason' => 'No answer within ' . $run->timeout() . ' seconds: ' . $exception->getMessage(),
				'raw' => [],
			];
		}

		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return $this->interpret(body: $decoded);

	}//end askRemote()

	/**
	 * POST the payload as JSON with the configured headers and timeout.
	 *
	 * @param PreCheckRun $run The pre-check.
	 * @param string      $url The system to ask.
	 *
	 * @return IResponse
	 */
	private function post(PreCheckRun $run, string $url): IResponse {
		$extraHeaders = ($run->configuration['headers'] ?? null);
		if (is_array($extraHeaders) === false) {
			$extraHeaders = [];
		}

		return $this->clientService->newClient()->post(
			$url,
			[
				'headers' => array_merge(
					['Accept' => 'application/json'],
					$extraHeaders
				),
				'json' => $run->payload,
				'timeout' => $run->timeout(),
			]
		);

	}//end post()

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

		if ($reason === '') {
			$reason = 'The answer did not say allow or refuse.';
		}

		return [
			'decision' => self::NO_ANSWER,
			'reason' => $reason,
			'raw' => $body,
		];

	}//end interpret()

	/**
	 * Record the attempt, whatever came of it.
	 *
	 * @param PreCheckRun         $run    The pre-check, with the HTTP status (zero when there was none).
	 * @param array<string,mixed> $answer What came back.
	 *
	 * @return void
	 */
	private function record(PreCheckRun $run, array $answer): void {
		$statusMessage = $answer['decision'];
		if ($answer['reason'] !== '') {
			$statusMessage .= ': ' . $answer['reason'];
		}

		$this->recorder->record(
			[
				'target' => (string)($run->configuration['url'] ?? 'pre-check'),
				'request' => ['method' => 'POST', 'url' => (string)($run->configuration['url'] ?? ''), 'body' => $run->payload],
				'response' => $answer['raw'],
				'statusCode' => $run->statusCode,
				'statusMessage' => $statusMessage,
				'kind' => CallRecorder::KIND_TRIGGERED,
			]
		);

	}//end record()

}//end class
