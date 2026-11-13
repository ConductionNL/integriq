<?php

/**
 * Unit tests for GraphMailboxTransport's refusals.
 *
 * The golden test pins the happy path; this one pins what the transport says
 * when the mailbox is not configured, Graph refuses, or Graph answers non-JSON.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail\Transport;

use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Graph transport refusals over a mocked HTTP client.
 *
 * @covers \OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport
 * @uses   \OCA\Integriq\Exception\MailboxTransportException
 */
class GraphMailboxTransportTest extends TestCase {

	/**
	 * A configured mailbox.
	 *
	 * @var array<string, string>
	 */
	private const CONFIGURED = ['mailbox' => 'inbox@example.test', 'accessToken' => 'token'];

	/**
	 * Build the transport over a client whose get() behaves as given.
	 *
	 * @param callable $get The get() behaviour.
	 *
	 * @return GraphMailboxTransport
	 */
	private function transport(callable $get): GraphMailboxTransport {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback($get);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return new GraphMailboxTransport($service);

	}//end transport()

	/**
	 * The transport names itself "graph" and is always usable.
	 *
	 * @return void
	 */
	public function testProtocolIsGraphAndAlwaysUsable(): void {
		$transport = new GraphMailboxTransport($this->createMock(IClientService::class));

		$this->assertSame('graph', $transport->getProtocol());
		$this->assertTrue($transport->isUsable());

	}//end testProtocolIsGraphAndAlwaysUsable()

	/**
	 * A blank mailbox or token is refused before any request is made.
	 *
	 * @return void
	 */
	public function testMissingMailboxOrTokenIsRefusedWithoutARequest(): void {
		$service = $this->createMock(IClientService::class);
		$service->expects($this->never())->method('newClient');
		$transport = new GraphMailboxTransport($service);

		foreach ([['accessToken' => 'token'], ['mailbox' => 'inbox@example.test', 'accessToken' => '  ']] as $configuration) {
			try {
				$transport->fetch($configuration, null);
				$this->fail('A Graph mailbox without mailbox or token must be refused.');
			} catch (MailboxTransportException $exception) {
				$this->assertSame('A Graph mailbox needs both a mailbox address and an access token.', $exception->getMessage());
			}
		}

	}//end testMissingMailboxOrTokenIsRefusedWithoutARequest()

	/**
	 * A failing HTTP call is reported as a Graph refusal carrying the cause.
	 *
	 * @return void
	 */
	public function testHttpFailureIsReportedAsARefusal(): void {
		$transport = $this->transport(
			static function (): IResponse {
				throw new RuntimeException('401 Unauthorized');
			}
		);

		$this->expectException(MailboxTransportException::class);
		$this->expectExceptionMessage('Graph refused the mailbox read: 401 Unauthorized');

		$transport->fetch(self::CONFIGURED, null);

	}//end testHttpFailureIsReportedAsARefusal()

	/**
	 * A body that does not decode to a JSON object is refused.
	 *
	 * @return void
	 */
	public function testNonJsonAnswerIsRefused(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('<html>maintenance</html>');
		$transport = $this->transport(static fn (): IResponse => $response);

		$this->expectException(MailboxTransportException::class);
		$this->expectExceptionMessage('Graph answered something that is not JSON.');

		$transport->fetch(self::CONFIGURED, null);

	}//end testNonJsonAnswerIsRefused()
}//end class
