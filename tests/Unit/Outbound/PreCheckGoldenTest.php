<?php

/**
 * Golden tests for PreCheckService::ask(): every way the outside system's
 * answer is read, what is posted, and what is recorded.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\PreCheckService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the allow, refuse and no-answer outcomes of the pre-check.
 */
class PreCheckGoldenTest extends TestCase {

	/**
	 * What was recorded.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $recorded = [];

	/**
	 * What was posted, as [url, options].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $posted = [];

	/**
	 * The service whose client answers with a body and status, or throws.
	 *
	 * @param string|\Exception $answer The body, or the exception.
	 * @param int               $status The status.
	 *
	 * @return PreCheckService
	 */
	private function service(string|\Exception $answer = '', int $status = 200): PreCheckService {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($answer, $status): IResponse {
				$this->posted[] = [$url, $options];
				if ($answer instanceof \Exception) {
					throw $answer;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($answer);
				$response->method('getStatusCode')->willReturn($status);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$recorder = $this->createMock(CallRecorder::class);
		$recorder->method('record')->willReturnCallback(
			function (array $entry): ObjectEntity {
				$this->recorded[] = $entry;
				return new ObjectEntity();
			}
		);

		return new PreCheckService($clients, $recorder);

	}//end service()

	/**
	 * Each configuration, answer and outcome.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string|\Exception,2:int,3:array<string,mixed>,4:int,5:string}>
	 */
	public static function cases(): array {
		return [
			'mock allow' => [['mock' => true, 'fixture' => ['state' => ' Pass ', 'reason' => 'ok']], '', 200, ['decision' => 'allow', 'reason' => 'ok', 'raw' => ['state' => ' Pass ', 'reason' => 'ok']], 200, 'allow: ok'],
			'mock refused' => [['mock' => true, 'fixture' => ['decision' => 'REFUSED']], '', 200, ['decision' => 'refuse', 'reason' => '', 'raw' => ['decision' => 'REFUSED']], 200, 'refuse'],
			'mock fixture no array' => [['mock' => true, 'fixture' => 'x', 'url' => 'https://u'], '', 200, ['decision' => 'no answer', 'reason' => 'The answer did not say allow or refuse.', 'raw' => []], 200, 'no answer: The answer did not say allow or refuse.'],
			'mock not true' => [['mock' => 1, 'url' => ' '], '', 200, ['decision' => 'no answer', 'reason' => 'The pre-check names no system to ask.', 'raw' => []], 0, 'no answer: The pre-check names no system to ask.'],
			'no url' => [[], '', 200, ['decision' => 'no answer', 'reason' => 'The pre-check names no system to ask.', 'raw' => []], 0, 'no answer: The pre-check names no system to ask.'],
			'remote allow' => [['url' => ' https://c/ask ', 'timeoutSeconds' => '3', 'headers' => ['X-K' => 'v', 'Accept' => 'text/plain']], '{"decision":"allowed","reason":"fine"}', 201, ['decision' => 'allow', 'reason' => 'fine', 'raw' => ['decision' => 'allowed', 'reason' => 'fine']], 201, 'allow: fine'],
			'remote fail' => [['url' => 'https://c/ask'], '{"decision":"fail"}', 409, ['decision' => 'refuse', 'reason' => '', 'raw' => ['decision' => 'fail']], 409, 'refuse'],
			'remote other' => [['url' => 'https://c/ask', 'headers' => 'x'], '{"decision":"maybe","reason":"later"}', 200, ['decision' => 'no answer', 'reason' => 'later', 'raw' => ['decision' => 'maybe', 'reason' => 'later']], 200, 'no answer: later'],
			'remote not json' => [['url' => 'https://c/ask'], 'yes', 500, ['decision' => 'no answer', 'reason' => 'The answer did not say allow or refuse.', 'raw' => []], 500, 'no answer: The answer did not say allow or refuse.'],
			'remote down' => [['url' => 'https://c/ask', 'timeoutSeconds' => 2], new RuntimeException('timed out'), 0, ['decision' => 'no answer', 'reason' => 'No answer within 2 seconds: timed out', 'raw' => []], 0, 'no answer: No answer within 2 seconds: timed out'],
		];

	}//end cases()

	/**
	 * Each pre-check answers and records as before.
	 *
	 * @param array<string,mixed> $configuration The pre-check.
	 * @param string|\Exception   $answer        What the system sends, or throws.
	 * @param int                 $status        Its status.
	 * @param array<string,mixed> $expected      The answer.
	 * @param int                 $recordedStatus The recorded status.
	 * @param string              $message       The recorded status message.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAnswer(array $configuration, string|\Exception $answer, int $status, array $expected, int $recordedStatus, string $message): void {
		$payload = ['zaak' => 'z-1'];

		$this->assertSame($expected, $this->service($answer, $status)->ask($configuration, $payload));

		$url = (string)($configuration['url'] ?? '');
		$this->assertSame(
			[
				[
					'target' => (string)($configuration['url'] ?? 'pre-check'),
					'request' => ['method' => 'POST', 'url' => $url, 'body' => $payload],
					'response' => $expected['raw'],
					'statusCode' => $recordedStatus,
					'statusMessage' => $message,
					'kind' => CallRecorder::KIND_TRIGGERED,
				],
			],
			$this->recorded
		);

	}//end testAnswer()

	/**
	 * The post carries the merged headers, the payload and the timeout.
	 *
	 * @return void
	 */
	public function testPostOptions(): void {
		$this->service('{}')->ask(['url' => ' https://c/ask ', 'headers' => ['X-K' => 'v', 'Accept' => 'text/plain']], ['a' => 1]);
		$this->service('{}')->ask(['url' => 'https://c/ask', 'timeoutSeconds' => '7', 'headers' => 'nope'], []);

		$this->assertSame(
			[
				['https://c/ask', ['headers' => ['Accept' => 'text/plain', 'X-K' => 'v'], 'json' => ['a' => 1], 'timeout' => 10]],
				['https://c/ask', ['headers' => ['Accept' => 'application/json'], 'json' => [], 'timeout' => 7]],
			],
			$this->posted
		);

	}//end testPostOptions()
}//end class
