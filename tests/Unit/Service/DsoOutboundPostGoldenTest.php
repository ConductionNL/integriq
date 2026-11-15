<?php

/**
 * Characterisation tests for the DSO outbound posts: DSOStatusService::pushStatusToDSO()
 * and DSOSamenwerkingService::sendAdviesverzoek().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\DSOSamenwerkingService;
use OCA\Integriq\Service\DSOStatusService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the requests, log lines, retries and results of both DSO outbound posts.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 */
class DsoOutboundPostGoldenTest extends TestCase {

	/**
	 * Log lines written, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Posts made, as [uri, options].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $posts = [];

	/**
	 * How often a client was asked for.
	 *
	 * @var int
	 */
	private int $clients = 0;

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return $logger;

	}//end logger()

	/**
	 * A client service whose posts answer, in turn, with each of the outcomes.
	 *
	 * @param list<int|\Exception> $outcomes A status code or an exception per post.
	 *
	 * @return IClientService
	 */
	private function clientService(array $outcomes): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $uri, array $options) use (&$outcomes): IResponse {
				$this->posts[] = [$uri, $options];
				$outcome = array_shift($outcomes);
				if ($outcome instanceof \Exception) {
					throw $outcome;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($outcome);
				return $response;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturnCallback(
			function () use ($client): IClient {
				$this->clients++;
				return $client;
			}
		);

		return $clientService;

	}//end clientService()

	/**
	 * A first-try 2xx push logs once and answers true, without a certificate option.
	 *
	 * @return void
	 */
	public function testStatusPushedOnFirstAttempt(): void {
		$service = new DSOStatusService(logger: $this->logger(), clientService: $this->clientService([204]));

		$this->assertTrue($service->pushStatusToDSO('vz-1', 'in_behandeling', 'https://dso.test'));

		$this->assertCount(1, $this->posts);
		[$uri, $options] = $this->posts[0];
		$this->assertSame('https://dso.test/statussen', $uri);
		$this->assertSame(['json', 'headers'], array_keys($options));
		$this->assertSame(['verzoekId', 'status', 'timestamp'], array_keys($options['json']));
		$this->assertSame('vz-1', $options['json']['verzoekId']);
		$this->assertSame('in behandeling', $options['json']['status']);
		$this->assertSame(['Content-Type' => 'application/json'], $options['headers']);
		$this->assertSame(
			[['info', 'DSO: status pushed successfully', ['verzoekId' => 'vz-1', 'dsoStatus' => 'in behandeling', 'attempt' => 1]]],
			$this->logs
		);
		$this->assertSame(1, $this->clients);

	}//end testStatusPushedOnFirstAttempt()

	/**
	 * A refused then failed attempt is retried, with a certificate, until the third succeeds.
	 *
	 * @return void
	 */
	public function testStatusPushRetriesUntilAccepted(): void {
		$service = new DSOStatusService(
			logger: $this->logger(),
			clientService: $this->clientService([500, new RuntimeException('down'), 200])
		);

		$this->assertTrue($service->pushStatusToDSO('vz-2', 'nope', 'https://dso.test', '/cert.pem'));

		$this->assertCount(3, $this->posts);
		$this->assertSame('/cert.pem', $this->posts[2][1]['cert']);
		$this->assertSame(['json', 'headers', 'cert'], array_keys($this->posts[0][1]));
		$this->assertSame('onbekend', $this->posts[0][1]['json']['status']);
		$this->assertSame(
			[
				['warning', 'DSO: status push returned non-2xx', ['verzoekId' => 'vz-2', 'statusCode' => 500, 'attempt' => 1]],
				['warning', 'DSO: status push attempt failed', ['verzoekId' => 'vz-2', 'attempt' => 2, 'error' => 'down']],
				['info', 'DSO: status pushed successfully', ['verzoekId' => 'vz-2', 'dsoStatus' => 'onbekend', 'attempt' => 3]],
			],
			$this->logs
		);
		$this->assertSame(3, $this->clients);

	}//end testStatusPushRetriesUntilAccepted()

	/**
	 * Three refusals answer false after an error line.
	 *
	 * @return void
	 */
	public function testStatusPushGivesUpAfterThreeAttempts(): void {
		$service = new DSOStatusService(logger: $this->logger(), clientService: $this->clientService([199, 300, 404, 200]));

		$this->assertFalse($service->pushStatusToDSO('vz-3', 'afgerond', 'https://dso.test'));

		$this->assertCount(3, $this->posts);
		$this->assertSame(
			[
				['warning', 'DSO: status push returned non-2xx', ['verzoekId' => 'vz-3', 'statusCode' => 199, 'attempt' => 1]],
				['warning', 'DSO: status push returned non-2xx', ['verzoekId' => 'vz-3', 'statusCode' => 300, 'attempt' => 2]],
				['warning', 'DSO: status push returned non-2xx', ['verzoekId' => 'vz-3', 'statusCode' => 404, 'attempt' => 3]],
				['error', 'DSO: status push failed after all retries', ['verzoekId' => 'vz-3', 'dsoStatus' => 'afgerond']],
			],
			$this->logs
		);

	}//end testStatusPushGivesUpAfterThreeAttempts()

	/**
	 * An accepted adviesverzoek posts the payload and answers success.
	 *
	 * @return void
	 */
	public function testAdviesverzoekAccepted(): void {
		$service = new DSOSamenwerkingService(logger: $this->logger(), clientService: $this->clientService([201]));

		$result = $service->sendAdviesverzoek(['id' => 'zaak-1', 'documenten' => ['d1']], 'OIN-9', '2026-12-01', 'https://swf.test');

		$this->assertCount(1, $this->posts);
		[$uri, $options] = $this->posts[0];
		$this->assertSame('https://swf.test/adviesverzoeken', $uri);
		$this->assertSame(['json', 'headers'], array_keys($options));
		$this->assertSame(['Content-Type' => 'application/json'], $options['headers']);
		$payload = $options['json'];
		$this->assertSame(['adviesverzoekId', 'caseId', 'partnerOin', 'termijn', 'zaakDocumenten', 'aangemaakt'], array_keys($payload));
		$this->assertSame(['zaak-1', 'OIN-9', '2026-12-01', ['d1']], [$payload['caseId'], $payload['partnerOin'], $payload['termijn'], $payload['zaakDocumenten']]);
		$this->assertStringStartsWith('avz-', $payload['adviesverzoekId']);
		$this->assertSame(
			[
				'success' => true,
				'adviesverzoekId' => $payload['adviesverzoekId'],
				'message' => 'Adviesverzoek succesvol verzonden naar OIN-9',
			],
			$result
		);
		$this->assertSame(
			[['info', 'DSO-SWF: adviesverzoek sent successfully', ['adviesverzoekId' => $payload['adviesverzoekId'], 'partnerOin' => 'OIN-9', 'caseId' => 'zaak-1']]],
			$this->logs
		);

	}//end testAdviesverzoekAccepted()

	/**
	 * A refused adviesverzoek, sent with a certificate and a case without id, answers the status.
	 *
	 * @return void
	 */
	public function testAdviesverzoekRefused(): void {
		$service = new DSOSamenwerkingService(logger: $this->logger(), clientService: $this->clientService([302]));

		$result = $service->sendAdviesverzoek([], 'OIN-9', '2026-12-01', 'https://swf.test', '/c.pem');

		$options = $this->posts[0][1];
		$this->assertSame('/c.pem', $options['cert']);
		$this->assertNull($options['json']['caseId']);
		$this->assertSame([], $options['json']['zaakDocumenten']);
		$id = $options['json']['adviesverzoekId'];
		$this->assertSame(['success' => false, 'adviesverzoekId' => $id, 'message' => 'DSO-SWF responded with HTTP 302'], $result);
		$this->assertSame(
			[['warning', 'DSO-SWF: adviesverzoek post returned non-2xx', ['adviesverzoekId' => $id, 'statusCode' => 302]]],
			$this->logs
		);

	}//end testAdviesverzoekRefused()

	/**
	 * A failing post answers the exception's message after an error line.
	 *
	 * @return void
	 */
	public function testAdviesverzoekFailed(): void {
		$service = new DSOSamenwerkingService(
			logger: $this->logger(),
			clientService: $this->clientService([new RuntimeException('timeout')])
		);

		$result = $service->sendAdviesverzoek(['id' => 7], 'OIN-1', 'P5D', 'https://swf.test');

		$id = $this->posts[0][1]['json']['adviesverzoekId'];
		$this->assertSame(['success' => false, 'adviesverzoekId' => $id, 'message' => 'Send failed: timeout'], $result);
		$this->assertSame(
			[['error', 'DSO-SWF: failed to send adviesverzoek', ['adviesverzoekId' => $id, 'partnerOin' => 'OIN-1', 'error' => 'timeout']]],
			$this->logs
		);

	}//end testAdviesverzoekFailed()
	/**
	 * The first missing or empty required field refuses the advies, logged once.
	 *
	 * @return void
	 */
	public function testAdviesWithAMissingFieldIsRefused(): void {
		$service = new DSOSamenwerkingService(logger: $this->logger(), clientService: $this->clientService([]));

		$result = $service->receiveAdvies(['adviesId' => 'a-1', 'organisatieOin' => ''], 'zaak-4');

		$this->assertSame(
			['stored' => false, 'adviesId' => null, 'caseId' => 'zaak-4', 'error' => 'required_field_missing: organisatieOin'],
			$result
		);
		$this->assertSame(
			[['warning', 'DSO-SWF: received advies missing required field', ['caseId' => 'zaak-4', 'field' => 'organisatieOin']]],
			$this->logs
		);

	}//end testAdviesWithAMissingFieldIsRefused()

	/**
	 * A complete advies is stored and logged with its organisation.
	 *
	 * @return void
	 */
	public function testCompleteAdviesIsStored(): void {
		$service = new DSOSamenwerkingService(logger: $this->logger(), clientService: $this->clientService([]));

		$result = $service->receiveAdvies(['adviesId' => 'a-2', 'organisatieOin' => 'OIN-3', 'advies' => 'positief'], 'zaak-5');

		$this->assertSame(['stored' => true, 'adviesId' => 'a-2', 'caseId' => 'zaak-5'], $result);
		$this->assertSame(
			[['info', 'DSO-SWF: advies received and stored', ['adviesId' => 'a-2', 'organisatieOin' => 'OIN-3', 'caseId' => 'zaak-5']]],
			$this->logs
		);

	}//end testCompleteAdviesIsStored()
}//end class
