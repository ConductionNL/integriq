<?php

/**
 * Golden tests for the brokered dispatch of RestPeppolAccessPointProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Peppol;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Peppol\RestPeppolAccessPointProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what every brokered call sends to the broker, in which order, and how a
 * missing credential, a broker configuration error and a transport failure end.
 */
class RestPeppolDispatchGoldenTest extends TestCase {

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
	 * @return RestPeppolAccessPointProvider The provider.
	 */
	private function provider(bool $hasCredential, ?\Throwable $failure=null, ?Response $answer=null): RestPeppolAccessPointProvider {
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

		return new RestPeppolAccessPointProvider($broker, $l10n, $logger);

	}//end provider()

	/**
	 * The source configuration.
	 *
	 * @return array The configuration.
	 */
	private function configuration(): array {
		return ['baseUrl' => 'https://ap.example/peppol/', 'authentication' => ['credentialRef' => 'ref-1']];

	}//end configuration()

	/**
	 * A POST carries the JSON body; the broker is asked in order hasCredentialRef, prepare, dispatch.
	 *
	 * @return void
	 */
	public function testPostSendsJsonThroughTheBrokerInOrder(): void {
		$provider = $this->provider(true, null, new Response(202, [], '{"transmissionId":"T-1"}'));
		$this->assertSame('T-1', $provider->submitDocument($this->configuration(), '0106:123', 'invoice', '<Invoice/>'));

		$config = ['authentication' => ['credentialRef' => 'ref-1'], 'json' => ['recipientPeppolId' => '0106:123', 'documentType' => 'invoice', 'payload' => '<Invoice/>']];
		$this->assertSame(
			[
				['hasCredentialRef', $config],
				['prepare', $config, ['type' => 'peppol'], false],
				['dispatch', 'cred-uuid', 'alice', 'POST', 'https://ap.example/peppol/documents', $config],
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
		$provider = $this->provider(true, null, new Response(200, [], '{"exists":true,"supportedDocTypes":["invoice"]}'));
		$this->assertSame(['exists' => true, 'supportedDocTypes' => ['invoice']], $provider->lookupParticipant($this->configuration(), '0106:123'));

		$this->assertSame(['authentication' => ['credentialRef' => 'ref-1']], $this->calls[2][5]);
		$this->assertSame('GET', $this->calls[2][3]);
		$this->assertSame('https://ap.example/peppol/participants/0106%3A123', $this->calls[2][4]);

	}//end testGetSendsNoJsonKey()

	/**
	 * No credentialRef: refused before prepare or dispatch, with the full message.
	 *
	 * @return void
	 */
	public function testMissingCredentialIsRefusedBeforeDispatch(): void {
		$provider = $this->provider(false);
		try {
			$provider->lookupParticipant(['baseUrl' => 'https://ap.example'], '0106:123');
			$this->fail('Not refused.');
		} catch (PeppolProviderException $exception) {
			$this->assertSame(
				'Access point credential missing: the `rest` Peppol provider requires `configuration.authentication.credentialRef` — none is configured. '
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
			$provider->lookupParticipant($this->configuration(), '0106:123');
			$this->fail('Not refused.');
		} catch (PeppolProviderException $exception) {
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
			$provider->lookupParticipant($this->configuration(), '0106:123');
			$this->fail('Not refused.');
		} catch (PeppolProviderException $exception) {
			$this->assertSame('The Peppol Access Point request failed unexpectedly: socket closed', $exception->getMessage());
			$this->assertSame($cause, $exception->getPrevious());
		}

		$this->assertSame([['[RestPeppolAccessPointProvider] unexpected transport failure', ['exception' => 'socket closed']]], $this->warnings);

	}//end testTransportFailureIsLoggedAndWrapped()

	/**
	 * Answers: a non-2xx status and a non-JSON lookup answer are refused.
	 *
	 * @return void
	 */
	public function testAnswerRefusals(): void {
		foreach ([199, 300, 403, 500] as $status) {
			$provider = $this->provider(true, null, new Response($status, [], '{}'));
			try {
				$provider->lookupParticipant($this->configuration(), '0106:123');
				$this->fail('Not refused.');
			} catch (PeppolProviderException $exception) {
				$this->assertSame('The Peppol Access Point responded with HTTP '.$status.'.', $exception->getMessage());
			}
		}

		$provider = $this->provider(true, null, new Response(200, [], 'not json'));
		try {
			$provider->lookupParticipant($this->configuration(), '0106:123');
			$this->fail('Not refused.');
		} catch (PeppolProviderException $exception) {
			$this->assertSame('The Peppol Access Point returned a non-JSON response for the participant lookup.', $exception->getMessage());
		}

	}//end testAnswerRefusals()
}//end class
