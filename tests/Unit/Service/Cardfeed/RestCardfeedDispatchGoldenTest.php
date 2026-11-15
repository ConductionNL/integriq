<?php

/**
 * Golden tests for the brokered dispatch of RestCardfeedProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Cardfeed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Cardfeed;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\CardfeedProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Cardfeed\RestCardfeedProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what every brokered call sends to the broker, in which order, and how a
 * missing credential, a broker configuration error and a transport failure end.
 */
class RestCardfeedDispatchGoldenTest extends TestCase {

	/**
	 * Calls the broker saw, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, array>
	 */
	private array $warnings = [];

	/**
	 * Build a provider over a broker that records every call.
	 *
	 * @param bool            $hasCredential What hasCredentialRef answers.
	 * @param \Throwable|null $failure       What dispatch throws, or null to answer.
	 * @param Response|null   $answer        What dispatch returns.
	 *
	 * @return RestCardfeedProvider The provider.
	 */
	private function provider(bool $hasCredential, ?\Throwable $failure=null, ?Response $answer=null): RestCardfeedProvider {
		$this->calls    = [];
		$this->warnings = [];
		$broker         = $this->createMock(BrokeredCallService::class);
		$broker->method('hasCredentialRef')->willReturnCallback(
			function (array $config) use ($hasCredential): bool {
				$this->calls[] = ['hasCredentialRef', $config];
				return $hasCredential;
			}
		);
		$broker->method('prepare')->willReturnCallback(
			function (array $config, array $sourceData, bool $asynchronous): array {
				$this->calls[] = ['prepare', $config, $sourceData, $asynchronous];
				return ['credentialId' => 'cred-uuid', 'actingUserId' => 'alice'];
			}
		);
		$broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use ($failure, $answer) {
				$this->calls[] = ['dispatch', $credentialId, $actingUserId, $method, $url, $config];
				if ($failure !== null) {
					throw $failure;
				}

				return $answer;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new RestCardfeedProvider($broker, $l10n, $logger);

	}//end provider()

	/**
	 * The source configuration.
	 *
	 * @return array The configuration.
	 */
	private function configuration(): array {
		return ['baseUrl' => 'https://cards.example/v1/', 'authentication' => ['credentialRef' => 'ref-1']];

	}//end configuration()

	/**
	 * A GET carries no json key; the broker is asked in order hasCredentialRef, prepare, dispatch.
	 *
	 * @return void
	 */
	public function testGetGoesThroughTheBrokerInOrder(): void {
		$provider = $this->provider(true, null, new Response(200, [], '{"transactions":[{"id":"t-1"}]}'));
		$this->assertSame([['id' => 't-1']], $provider->listTransactions($this->configuration(), 'card 1', '2026-01-01T00:00:00Z', '2026-01-31T00:00:00Z'));

		$config = ['authentication' => ['credentialRef' => 'ref-1']];
		$this->assertSame(
			[
				['hasCredentialRef', $config],
				['prepare', $config, ['type' => 'cardfeed'], false],
				['dispatch', 'cred-uuid', 'alice', 'GET', 'https://cards.example/v1/cards/card%201/transactions?since=2026-01-01&until=2026-01-31', $config],
			],
			$this->calls
		);

	}//end testGetGoesThroughTheBrokerInOrder()

	/**
	 * No credentialRef: refused before prepare or dispatch, with the full message.
	 *
	 * @return void
	 */
	public function testMissingCredentialIsRefusedBeforeDispatch(): void {
		$provider = $this->provider(false);
		try {
			$provider->listCards(['baseUrl' => 'https://cards.example']);
			$this->fail('Not refused.');
		} catch (CardfeedProviderException $exception) {
			$this->assertSame(
				'Card provider credential missing: the `rest` cardfeed provider requires `configuration.authentication.credentialRef` — none is configured. '
				.'Configure a credentialRef through the OpenRegister credential broker; no plaintext-key fallback is permitted (ADR-007).',
				$exception->getMessage()
			);
			$this->assertNull($exception->getPrevious());
		}

		$this->assertSame([['hasCredentialRef', ['authentication' => []]]], $this->calls);
		$this->assertSame([], $this->warnings);

	}//end testMissingCredentialIsRefusedBeforeDispatch()

	/**
	 * A broker configuration error keeps its message and is not logged.
	 *
	 * @return void
	 */
	public function testBrokerConfigurationErrorKeepsItsMessage(): void {
		$cause    = new BrokeredCallConfigurationException(message: 'credential not found');
		$provider = $this->provider(true, $cause);
		try {
			$provider->listCards($this->configuration());
			$this->fail('Not refused.');
		} catch (CardfeedProviderException $exception) {
			$this->assertSame('credential not found', $exception->getMessage());
			$this->assertSame($cause, $exception->getPrevious());
		}

		$this->assertSame([], $this->warnings);

	}//end testBrokerConfigurationErrorKeepsItsMessage()

	/**
	 * Any other failure is logged and wrapped.
	 *
	 * @return void
	 */
	public function testTransportFailureIsLoggedAndWrapped(): void {
		$cause    = new RuntimeException('socket closed');
		$provider = $this->provider(true, $cause);
		try {
			$provider->listCards($this->configuration());
			$this->fail('Not refused.');
		} catch (CardfeedProviderException $exception) {
			$this->assertSame('The card provider request failed unexpectedly: socket closed', $exception->getMessage());
			$this->assertSame($cause, $exception->getPrevious());
		}

		$this->assertSame([['[RestCardfeedProvider] unexpected transport failure', ['exception' => 'socket closed']]], $this->warnings);

	}//end testTransportFailureIsLoggedAndWrapped()

	/**
	 * Answers: a non-2xx status and a non-JSON answer are refused; cards are read from cards or data.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		foreach ([199, 300, 401, 500] as $status) {
			$provider = $this->provider(true, null, new Response($status, [], '{}'));
			try {
				$provider->listCards($this->configuration());
				$this->fail('Not refused.');
			} catch (CardfeedProviderException $exception) {
				$this->assertSame('The card provider responded with HTTP '.$status.'.', $exception->getMessage());
			}
		}

		$provider = $this->provider(true, null, new Response(200, [], 'not json'));
		try {
			$provider->listCards($this->configuration());
			$this->fail('Not refused.');
		} catch (CardfeedProviderException $exception) {
			$this->assertSame('The card provider returned a non-JSON response.', $exception->getMessage());
		}

		$provider = $this->provider(true, null, new Response(200, [], '{"data":[{"cardId":"c-1","last4":"1234","cardholder":"A","currency":"EUR"}]}'));
		$this->assertSame([['cardId' => 'c-1', 'last4' => '1234', 'cardholderName' => 'A', 'currency' => 'EUR']], $provider->listCards($this->configuration()));
		$this->assertSame('https://cards.example/v1/cards', $this->calls[2][4]);

	}//end testAnswers()
}//end class
