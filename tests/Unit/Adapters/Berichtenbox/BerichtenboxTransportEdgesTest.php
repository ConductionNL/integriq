<?php

/**
 * Unit tests for the Berichtenbox transport paths the delivery tests leave out.
 *
 * The live client hands each step to the ebMS adapter or the subscription
 * service and keeps one read of results and events per source. The adapter
 * client must refuse a host the egress allow-list does not name, an
 * unreachable adapter and an undecryptable token as Berichtenbox errors with
 * a reason code, and must read odd adapter answers as "nothing" rather than
 * crash.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxBatch;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxValidatieClient;
use OCA\Integriq\Adapters\Berichtenbox\EbmsAdapterClient;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Live client hand-offs and caching; adapter client refusals and odd answers.
 *
 * @covers \OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp
 * @covers \OCA\Integriq\Adapters\Berichtenbox\EbmsAdapterClient
 * @covers \OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock
 */
class BerichtenboxTransportEdgesTest extends TestCase {

	private const CONFIG = ['adapterUrl' => 'http://ebms/e', 'cpaId' => 'c'];

	/**
	 * The requests the adapter client sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $history = [];

	/**
	 * The live client names every missing setting, and none when all are there.
	 *
	 * @return void
	 */
	public function testTheLiveClientNamesEveryMissingSetting(): void {
		$client = $this->liveClient(adapter: $this->createMock(EbmsAdapterClient::class));

		$this->assertSame('https', $client->flavour());
		$this->assertSame(
			['The live Berichtenbox needs the ebMS adapter (adapterUrl), the CPA id Logius made (cpaId), '
				. "Logius' party id from the CPA (toPartyId), the CPA service (service), the subscription check endpoint (wusEndpoint), "
				. 'a BerichtType per letter category (berichtTypes). Nothing was sent.'],
			$client->configurationRefusals(['berichtTypes' => ['', 7]])
		);
		$this->assertSame(
			[],
			$client->configurationRefusals([
				'adapterUrl' => 'http://ebms/e', 'cpaId' => 'c', 'toPartyId' => 'p', 'service' => 's', 'wusEndpoint' => 'https://wus',
				'berichtTypes' => ['case-update' => 'BESLUIT'],
			])
		);
	}//end testTheLiveClientNamesEveryMissingSetting()

	/**
	 * Subscriptions, delivery and processed marks are handed to the right service.
	 *
	 * @return void
	 */
	public function testEachStepIsHandedToItsService(): void {
		$batch = new BerichtenboxBatch('<xml/>', 'batch-1', 'b-1');
		$validatie = $this->createMock(BerichtenboxValidatieClient::class);
		$validatie->expects($this->once())->method('check')->with(['999993653'], 'BESLUIT', self::CONFIG)->willReturn(['999993653' => true]);
		$adapter = $this->createMock(EbmsAdapterClient::class);
		$adapter->expects($this->once())->method('send')->with($batch, self::CONFIG)->willReturn('b-1@integriq.berichtenbox');
		$adapter->expects($this->once())->method('markMessageProcessed')->with('r-1', self::CONFIG);
		$client = new BerichtenboxClientHttp($validatie, $adapter, $this->createMock(LoggerInterface::class));

		$this->assertSame(['999993653' => true], $client->checkSubscriptions(['999993653'], 'BESLUIT', self::CONFIG));
		$this->assertSame('b-1@integriq.berichtenbox', $client->deliver($batch, self::CONFIG));
		$client->resultProcessed('r-1', self::CONFIG);
	}//end testEachStepIsHandedToItsService()

	/**
	 * Transport events are read once per source, and a processed event drops out of the cache.
	 *
	 * @return void
	 */
	public function testTransportEventsAreReadOncePerSourceAndForgottenWhenProcessed(): void {
		$adapter = $this->createMock(EbmsAdapterClient::class);
		$adapter->expects($this->once())->method('unprocessedEvents')->with(self::CONFIG)->willReturn(['m-1' => 'DELIVERED', 'm-2' => 'FAILED']);
		$adapter->expects($this->once())->method('markEventProcessed')->with('m-1', self::CONFIG);
		$client = $this->liveClient(adapter: $adapter);

		$this->assertSame(['m-1' => 'DELIVERED', 'm-2' => 'FAILED'], $client->transportEvents(self::CONFIG));
		$client->eventProcessed('m-1', self::CONFIG);

		$this->assertSame(['m-2' => 'FAILED'], $client->transportEvents(self::CONFIG));
	}//end testTransportEventsAreReadOncePerSourceAndForgottenWhenProcessed()

	/**
	 * The mock client has no results or events, and marking them changes nothing.
	 *
	 * @return void
	 */
	public function testTheMockClientHasNothingToProcess(): void {
		$client = new BerichtenboxClientMock();
		$client->resultProcessed('r-1', self::CONFIG);
		$client->eventProcessed('m-1', self::CONFIG);

		$this->assertSame([], $client->results(self::CONFIG));
		$this->assertSame([], $client->transportEvents(self::CONFIG));
	}//end testTheMockClientHasNothingToProcess()

	/**
	 * An empty adapter answer to a send falls back to the message id the client gave the letter.
	 *
	 * @return void
	 */
	public function testAnEmptySendAnswerFallsBackToTheLettersMessageId(): void {
		$adapter = $this->adapterClient(responses: [new Response(200, [], "  \n")]);

		$this->assertSame('b-7@integriq.berichtenbox', $adapter->send(new BerichtenboxBatch('<xml/>', 'batch-7', 'b-7'), self::CONFIG));
	}//end testAnEmptySendAnswerFallsBackToTheLettersMessageId()

	/**
	 * Odd adapter answers read as nothing: a non-list of results, a payload without content, events of the wrong shape.
	 *
	 * @return void
	 */
	public function testOddAdapterAnswersReadAsNothing(): void {
		$adapter = $this->adapterClient(responses: [
			new Response(200, [], '"no results"'),
			new Response(200, [], '{"dataSources":[{"content":"%%%"}]}'),
			new Response(200, [], '"not a list"'),
			new Response(200, [], '[{"messageId":"m-1"},"x",{"messageId":"m-2","type":"EXPIRED"}]'),
		]);

		$this->assertSame([], $adapter->unprocessedResults(self::CONFIG));
		$this->assertSame('', $adapter->payload('r-1', self::CONFIG));
		$this->assertSame([], $adapter->unprocessedEvents(self::CONFIG));
		$this->assertSame(['m-2' => 'EXPIRED'], $adapter->unprocessedEvents(self::CONFIG));
	}//end testOddAdapterAnswersReadAsNothing()

	/**
	 * Marking a message or an event processed is a PATCH on its own encoded id.
	 *
	 * @return void
	 */
	public function testMarkingProcessedPatchesTheEncodedId(): void {
		$adapter = $this->adapterClient(responses: [new Response(204), new Response(204)]);

		$adapter->markMessageProcessed('r 1@logius', self::CONFIG);
		$adapter->markEventProcessed('m/1', self::CONFIG);

		$this->assertSame('PATCH', $this->history[0]['request']->getMethod());
		$this->assertSame('http://ebms/e/messages/r%201%40logius', (string)$this->history[0]['request']->getUri());
		$this->assertSame('http://ebms/e/events/m%2F1', (string)$this->history[1]['request']->getUri());
	}//end testMarkingProcessedPatchesTheEncodedId()

	/**
	 * A host the allow-list does not name, an unreachable adapter and a bad token are refused with a reason.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirReason(): void {
		$cases = [
			'not allowed' => [
				$this->adapterClient(responses: []),
				['adapterUrl' => 'http://elsewhere/e', 'cpaId' => 'c'],
				'The ebMS adapter may not be called: ',
				BerichtenboxException::CODE_NOT_CONFIGURED,
			],
			'unreachable' => [
				$this->adapterClient(responses: [new ConnectException('connection refused', new Request('GET', 'http://ebms/e'))]),
				self::CONFIG,
				'The ebMS adapter cannot be reached: connection refused',
				BerichtenboxException::CODE_TRANSPORT,
			],
			'bad token' => [
				$this->adapterClient(responses: []),
				self::CONFIG + ['authentication' => ['encryptedToken' => 'not-a-ciphertext']],
				'The ebMS adapter token cannot be decrypted.',
				BerichtenboxException::CODE_NOT_CONFIGURED,
			],
		];

		foreach ($cases as $name => [$adapter, $config, $message, $reason]) {
			try {
				$adapter->unprocessedEvents($config);
				$this->fail('Expected a refusal: ' . $name);
			} catch (BerichtenboxException $e) {
				$this->assertStringStartsWith($message, $e->getMessage(), $name);
				$this->assertSame($reason, $e->getReason(), $name);
			}
		}
	}//end testRefusalsCarryTheirReason()

	/**
	 * A live client over the given adapter client.
	 *
	 * @param EbmsAdapterClient $adapter The adapter client.
	 *
	 * @return BerichtenboxClientHttp
	 */
	private function liveClient(EbmsAdapterClient $adapter): BerichtenboxClientHttp {
		return new BerichtenboxClientHttp(
			$this->createMock(BerichtenboxValidatieClient::class),
			$adapter,
			$this->createMock(LoggerInterface::class)
		);
	}//end liveClient()

	/**
	 * A real adapter client over scripted HTTP answers, allowed to reach the `ebms` host only.
	 *
	 * @param array<int,mixed> $responses The answers.
	 *
	 * @return EbmsAdapterClient
	 */
	private function adapterClient(array $responses): EbmsAdapterClient {
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('ebms,wus.example');

		return new EbmsAdapterClient(new Client(['handler' => $stack]), new EgressGuard($appConfig), new AesTestCrypto());
	}//end adapterClient()
}//end class
