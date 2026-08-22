<?php

/**
 * Unit tests for RestCardfeedProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Cardfeed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-2
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Cardfeed;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Exception\CardfeedProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Cardfeed\RestCardfeedProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST card-feed provider (brokered dispatch, fail-closed on missing key).
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
class RestCardfeedProviderTest extends TestCase {

	/**
	 * @var BrokeredCallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $brokeredCallService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var RestCardfeedProvider
	 */
	private RestCardfeedProvider $provider;

	/**
	 * The valid configuration used by every "happy path" test.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://api.card-program.example/v1',
		'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->brokeredCallService = $this->createMock(BrokeredCallService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new RestCardfeedProvider(
			$this->brokeredCallService,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * A source with no credentialRef fails closed before any dispatch, with an
	 * actionable message and NO plaintext fallback — REQ-005.
	 *
	 * @return void
	 */
	public function testListCardsWithoutCredentialRefFailsClosed(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(false);
		$this->brokeredCallService->expects($this->never())->method('dispatch');

		$this->expectException(CardfeedProviderException::class);
		$this->expectExceptionMessage('credentialRef');

		$this->provider->listCards(sourceConfiguration: ['baseUrl' => 'https://api.card-program.example/v1']);

	}//end testListCardsWithoutCredentialRefFailsClosed()

	/**
	 * A brokered configuration error is mapped to a secret-free CardfeedProviderException.
	 *
	 * @return void
	 */
	public function testBrokerConfigurationErrorIsMapped(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')
			->willThrowException(new BrokeredCallConfigurationException(message: 'credential not found'));

		$this->expectException(CardfeedProviderException::class);
		$this->expectExceptionMessage('credential not found');

		$this->provider->listCards(sourceConfiguration: $this->configuration);

	}//end testBrokerConfigurationErrorIsMapped()

	/**
	 * A successful card list routes through the broker and parses the card rows (REQ-001).
	 *
	 * @return void
	 */
	public function testListCardsRoutesThroughBrokerAndParsesResponse(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->expects($this->once())
			->method('dispatch')
			->with('cred-uuid', null, 'GET', $this->stringContains('/cards'), $this->anything())
			->willReturn(
				new Response(
					200,
					[],
					json_encode(
						['cards' => [['id' => 'CARD-9', 'last4' => '1111', 'cardholderName' => 'B. Test', 'currency' => 'EUR']]]
					)
				)
			);

		$cards = $this->provider->listCards(sourceConfiguration: $this->configuration);

		$this->assertCount(1, $cards);
		$this->assertSame('CARD-9', $cards[0]['cardId']);
		$this->assertSame('1111', $cards[0]['last4']);
		$this->assertSame('B. Test', $cards[0]['cardholderName']);

	}//end testListCardsRoutesThroughBrokerAndParsesResponse()

	/**
	 * listTransactions parses the provider transactions array through the broker.
	 *
	 * @return void
	 */
	public function testListTransactionsParsesRows(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->method('dispatch')->willReturn(
			new Response(
				200,
				[],
				json_encode(['transactions' => [['transactionId' => 'CTX-1', 'bookingDate' => '2026-07-01']]])
			)
		);

		$rows = $this->provider->listTransactions(
			sourceConfiguration: $this->configuration,
			cardId: 'CARD-9',
			since: '2026-06-30T00:00:00+00:00',
			until: '2026-07-01T00:00:00+00:00'
		);

		$this->assertCount(1, $rows);
		$this->assertSame('CTX-1', $rows[0]['transactionId']);

	}//end testListTransactionsParsesRows()

	/**
	 * A non-2xx provider response is a descriptive CardfeedProviderException, never a crash.
	 *
	 * @return void
	 */
	public function testNonSuccessStatusIsMappedNotCrash(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->method('dispatch')->willReturn(new Response(503, [], 'upstream down'));

		$this->expectException(CardfeedProviderException::class);

		$this->provider->listCards(sourceConfiguration: $this->configuration);

	}//end testNonSuccessStatusIsMappedNotCrash()
}//end class
