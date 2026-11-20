<?php

/**
 * Golden tests for the brokered dispatch of RestPsd2AggregatorProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Psd2;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\Psd2ProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Psd2\RestPsd2AggregatorProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what every brokered call sends to the broker, in which order, and how a
 * missing credential, a broker configuration error and a transport failure end.
 */
class RestPsd2DispatchGoldenTest extends TestCase {

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
	 * @return RestPsd2AggregatorProvider The provider.
	 */
	private function provider(bool $hasCredential, ?\Throwable $failure=null, ?Response $answer=null): RestPsd2AggregatorProvider {
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

		return new RestPsd2AggregatorProvider($broker, $l10n, $logger);

	}//end provider()

	/**
	 * The source configuration.
	 *
	 * @return array The configuration.
	 */
	private function configuration(): array {
		return ['baseUrl' => 'https://aggregator.example/psd2/', 'authentication' => ['credentialRef' => 'ref-1']];

	}//end configuration()

	/**
	 * A POST carries the JSON body; the broker is asked in order hasCredentialRef, prepare, dispatch.
	 *
	 * @return void
	 */
	public function testPostSendsJsonThroughTheBrokerInOrder(): void {
		$provider = $this->provider(true, null, new Response(201, [], '{"id":"REQ-1","link":"https://bank.example/sca"}'));
		$provider->createRequisition($this->configuration(), 'BANK', 'https://nc.example/back');

		$config = ['authentication' => ['credentialRef' => 'ref-1'], 'json' => ['redirect' => 'https://nc.example/back', 'institution_id' => 'BANK']];
		$this->assertSame(
			[
				['hasCredentialRef', $config],
				['prepare', $config, ['type' => 'psd2'], false],
				['dispatch', 'cred-uuid', 'alice', 'POST', 'https://aggregator.example/psd2/requisitions/', $config],
			],
			$this->calls
		);

	}//end testPostSendsJsonThroughTheBrokerInOrder()

	/**
	 * A GET carries no json key at all.
	 *
	 * @return void
	 */
	public function testGetSendsNoJsonKey(): void {
		$provider = $this->provider(true, null, new Response(200, [], '{"transactions":{"booked":[]}}'));
		$provider->listTransactions($this->configuration(), 'acc-1', '2026-01-01', '2026-01-31');

		$this->assertSame(['authentication' => ['credentialRef' => 'ref-1']], $this->calls[2][5]);
		$this->assertSame('GET', $this->calls[2][3]);

	}//end testGetSendsNoJsonKey()

	/**
	 * No credentialRef: refused before prepare or dispatch, with the full message.
	 *
	 * @return void
	 */
	public function testMissingCredentialIsRefusedBeforeDispatch(): void {
		$provider = $this->provider(false);
		try {
			$provider->createRequisition(['baseUrl' => 'https://aggregator.example'], 'BANK', 'https://nc.example/back');
			$this->fail('Not refused.');
		} catch (Psd2ProviderException $exception) {
			$this->assertSame(
				'Aggregator credential missing: the `rest` PSD2 provider requires `configuration.authentication.credentialRef` — none is configured. '
				.'Configure a credentialRef through the OpenRegister credential broker; no plaintext-token fallback is permitted (ADR-007).',
				$exception->getMessage()
			);
			$this->assertNull($exception->getPrevious());
		}

		$this->assertSame([['hasCredentialRef', ['authentication' => [], 'json' => ['redirect' => 'https://nc.example/back', 'institution_id' => 'BANK']]]], $this->calls);
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
			$provider->createRequisition($this->configuration(), 'BANK', 'https://nc.example/back');
			$this->fail('Not refused.');
		} catch (Psd2ProviderException $exception) {
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
			$provider->createRequisition($this->configuration(), 'BANK', 'https://nc.example/back');
			$this->fail('Not refused.');
		} catch (Psd2ProviderException $exception) {
			$this->assertSame('The PSD2 aggregator request failed unexpectedly: socket closed', $exception->getMessage());
			$this->assertSame($cause, $exception->getPrevious());
		}

		$this->assertSame([['[RestPsd2AggregatorProvider] unexpected transport failure', ['exception' => 'socket closed']]], $this->warnings);

	}//end testTransportFailureIsLoggedAndWrapped()

	/**
	 * Answers: 403 on a non-consent call is a plain status refusal, a non-JSON 2xx is refused.
	 *
	 * @return void
	 */
	public function testAnswerRefusals(): void {
		$cases = [
			[new Response(403, [], '{}'), 'createRequisition', 'The PSD2 aggregator responded with HTTP 403.', Psd2ProviderException::class],
			[new Response(200, [], 'not json'), 'createRequisition', 'The PSD2 aggregator returned a non-JSON response.', Psd2ProviderException::class],
			[new Response(401, [], 'not json'), 'listTransactions', 'The PSD2 aggregator rejected the consent (HTTP 401) — it was revoked or is no longer usable.', \OCA\Integriq\Service\Psd2\Psd2ConsentRevokedException::class],
		];
		foreach ($cases as [$answer, $operation, $message, $class]) {
			$provider = $this->provider(true, null, $answer);
			try {
				if ($operation === 'createRequisition') {
					$provider->createRequisition($this->configuration(), 'BANK', 'https://nc.example/back');
				} else {
					$provider->listTransactions($this->configuration(), 'acc-1', '2026-01-01', '2026-01-31');
				}

				$this->fail('Not refused.');
			} catch (Psd2ProviderException $exception) {
				$this->assertSame($class, $exception::class);
				$this->assertSame($message, $exception->getMessage());
			}
		}

	}//end testAnswerRefusals()
}//end class
