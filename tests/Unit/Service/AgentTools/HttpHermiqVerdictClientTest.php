<?php

/**
 * The Hermiq verdict client asks Hermiq for a verdict and refuses, by reason, when Hermiq is absent, unreachable or unreadable.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\AgentTools;

use OCA\Integriq\Service\AgentTools\AgentActionRefusedException;
use OCA\Integriq\Service\AgentTools\HttpHermiqVerdictClient;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\AgentTools\HttpHermiqVerdictClient
 * @uses   \OCA\Integriq\Service\AgentTools\AgentActionRefusedException
 */
class HttpHermiqVerdictClientTest extends TestCase {

	/**
	 * The HTTP client.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $http;

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * The client under test.
	 *
	 * @var HttpHermiqVerdictClient
	 */
	private HttpHermiqVerdictClient $client;

	/**
	 * Build the client over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->http   = $this->createMock(originalClassName: IClient::class);
		$clients      = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($this->http);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example'.$path);
		$this->apps   = $this->createMock(originalClassName: IAppManager::class);
		$this->client = new HttpHermiqVerdictClient(clientService: $clients, urlGenerator: $urls, appManager: $this->apps);
	}//end setUp()

	/**
	 * A response with the given body.
	 *
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(string $body): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * Assert the call is refused with this reason.
	 *
	 * @param string $reason The reason.
	 *
	 * @return void
	 */
	private function assertRefused(string $reason): void {
		try {
			$this->client->requestVerdict(request: ['actionId' => 'a-1']);
			$this->fail(message: 'The request must be refused.');
		} catch (AgentActionRefusedException $exception) {
			$this->assertSame(expected: $reason, actual: $exception->reason);
			$this->assertSame(expected: 'Refused: '.$reason, actual: $exception->getMessage());
		}
	}//end assertRefused()

	/**
	 * The request is posted as JSON to Hermiq's verify endpoint, and the decoded answer is returned.
	 *
	 * @return void
	 */
	public function testTheVerdictIsRequestedFromHermiq(): void {
		$this->apps->method('isInstalled')->with('hermiq')->willReturn(true);
		$this->http->expects($this->once())
			->method('post')
			->with(
				'https://nc.example/index.php/apps/hermiq/api/approvals/verify',
				['json' => ['actionId' => 'a-1'], 'timeout' => 10, 'http_errors' => false]
			)
			->willReturn($this->response(body: '{"verdict":{"approved":true},"signature":"c2ln"}'));

		$this->assertSame(
			expected: ['verdict' => ['approved' => true], 'signature' => 'c2ln'],
			actual: $this->client->requestVerdict(request: ['actionId' => 'a-1'])
		);
	}//end testTheVerdictIsRequestedFromHermiq()

	/**
	 * Without Hermiq installed nothing is sent.
	 *
	 * @return void
	 */
	public function testWithoutHermiqNothingIsSent(): void {
		$this->apps->method('isInstalled')->willReturn(false);
		$this->http->expects($this->never())->method('post');

		$this->assertRefused(reason: 'hermiq-unavailable');
	}//end testWithoutHermiqNothingIsSent()

	/**
	 * A transport failure is refused as unreachable.
	 *
	 * @return void
	 */
	public function testATransportFailureIsUnreachable(): void {
		$this->apps->method('isInstalled')->willReturn(true);
		$this->http->method('post')->willThrowException(new RuntimeException('connection refused'));

		$this->assertRefused(reason: 'hermiq-unreachable');
	}//end testATransportFailureIsUnreachable()

	/**
	 * An answer that is not a JSON object or list is refused as unreadable.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotJsonIsUnreadable(): void {
		$this->apps->method('isInstalled')->willReturn(true);
		$this->http->method('post')->willReturnOnConsecutiveCalls(
			$this->response(body: '<html>502</html>'),
			$this->response(body: '"approved"'),
			$this->response(body: '')
		);

		$this->assertRefused(reason: 'hermiq-unreadable');
		$this->assertRefused(reason: 'hermiq-unreadable');
		$this->assertRefused(reason: 'hermiq-unreadable');
	}//end testAnAnswerThatIsNotJsonIsUnreadable()
}//end class
