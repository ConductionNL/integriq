<?php

/**
 * Unit tests for the HTTP transport to the Edurep SRU endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepHttpTransport;
use OCA\Integriq\Adapters\LearningMaterial\EdurepStatusStore;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A 200 answer is returned and recorded; anything else is the source being unavailable.
 */
#[CoversClass(EdurepHttpTransport::class)]
#[UsesClass(EdurepStatusStore::class)]
#[UsesClass(SourceUnavailableException::class)]
class EdurepHttpTransportTest extends TestCase {
	/**
	 * @var IClient&MockObject
	 */
	private IClient $client;

	private EdurepStatusStore $status;

	private EdurepHttpTransport $transport;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->client);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn(new ArrayCache());
		$this->status = new EdurepStatusStore(cacheFactory: $factory);
		$this->transport = new EdurepHttpTransport(clientService: $service, status: $this->status);
	}//end setUp()

	/**
	 * @param int    $code The status code.
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(int $code, string $body = ''): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($code);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * @return void
	 */
	public function testTheQueryIsEncodedAndTheBodyReturned(): void {
		$this->client->expects($this->once())->method('get')->with(
			'https://wszoeken.edurep.kennisnet.nl/edurep/sruns?query=betoog%20AND%20x%3Dy&maximumRecords=10',
			['timeout' => 10, 'http_errors' => false]
		)->willReturn($this->response(code: 200, body: '<xml/>'));

		$this->assertSame('<xml/>', $this->transport->fetch(['query' => 'betoog AND x=y', 'maximumRecords' => 10]));
		$this->assertNotNull($this->transport->lastSuccess());
		$this->assertNull($this->status->lastFailure());
	}//end testTheQueryIsEncodedAndTheBodyReturned()

	/**
	 * @return void
	 */
	public function testANon200AnswerIsUnavailableAndRecorded(): void {
		$this->client->method('get')->willReturn($this->response(code: 503));

		try {
			$this->transport->fetch(['query' => 'x']);
			$this->fail('A 503 must not be returned as a result');
		} catch (SourceUnavailableException $e) {
			$this->assertSame('edurep', $e->source);
			$this->assertNull($e->lastSuccess);
			$this->assertSame('The learning material source edurep did not answer.', $e->getMessage());
		}

		$this->assertSame('HTTP 503', $this->status->lastFailure()['reason'] ?? null);
		$this->assertNull($this->status->lastSuccess());
	}//end testANon200AnswerIsUnavailableAndRecorded()

	/**
	 * @return void
	 */
	public function testAConnectionErrorIsUnavailableAndCarriesTheLastSuccess(): void {
		$this->status->markSuccess();
		$this->client->method('get')->willThrowException(new RuntimeException('connection refused'));

		try {
			$this->transport->fetch(['query' => 'x']);
			$this->fail('A connection error must surface as unavailable');
		} catch (SourceUnavailableException $e) {
			$this->assertSame($this->status->lastSuccess(), $e->lastSuccess);
			$this->assertNotNull($e->lastSuccess);
		}

		$this->assertSame('connection refused', $this->status->lastFailure()['reason'] ?? null);
	}//end testAConnectionErrorIsUnavailableAndCarriesTheLastSuccess()
}//end class
