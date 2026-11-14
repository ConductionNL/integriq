<?php

/**
 * The configuration schemas and edge answers of the SMS providers, and the
 * array shape of a delivery result.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the SMS provider contract surface.
 *
 * @covers \OCA\Integriq\Service\Sms\RestNotifyNlProvider
 * @covers \OCA\Integriq\Service\Sms\LogSmsProvider
 * @covers \OCA\Integriq\Service\Sms\DeliveryResult
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 */
class SmsProviderContractTest extends TestCase {

	/**
	 * Requests the NotifyNL client sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * A NotifyNL provider over queued responses and the given crypto.
	 *
	 * @param list<Response> $responses The responses.
	 * @param ICrypto        $crypto    The crypto.
	 *
	 * @return RestNotifyNlProvider The provider.
	 */
	private function notifyNl(array $responses, ICrypto $crypto): RestNotifyNlProvider {
		$stack = HandlerStack::create(new MockHandler($responses));
		$this->history = [];
		$stack->push(Middleware::history($this->history));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RestNotifyNlProvider(
			httpClient: new Client(['handler' => $stack]),
			crypto: $crypto,
			l: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end notifyNl()

	/**
	 * A crypto that decrypts to a well-formed NotifyNL key.
	 *
	 * @return ICrypto The crypto.
	 */
	private function workingCrypto(): ICrypto {
		$crypto = $this->createMock(originalClassName: ICrypto::class);
		$crypto->method('decrypt')->willReturn(
			'my_key_name-11111111-1111-1111-1111-111111111111-22222222-2222-2222-2222-222222222222'
		);

		return $crypto;
	}//end workingCrypto()

	/**
	 * The NotifyNL schema requires the encrypted key and defaults the base URL.
	 *
	 * @return void
	 */
	public function testTheNotifyNlSchemaRequiresTheEncryptedKey(): void {
		$schema = $this->notifyNl(responses: [], crypto: $this->workingCrypto())->getConfigSchema();

		$this->assertSame(expected: 'object', actual: $schema['type']);
		$this->assertSame(expected: ['authentication'], actual: $schema['required']);
		$this->assertSame(expected: RestNotifyNlProvider::DEFAULT_BASE_URL, actual: $schema['properties']['baseUrl']['default']);
		$this->assertSame(expected: ['encryptedApiKey'], actual: $schema['properties']['authentication']['required']);
		$this->assertSame(
			expected: ['baseUrl', 'authentication', 'senderId', 'templateMapping'],
			actual: array_keys($schema['properties'])
		);
		$this->assertSame(expected: ['type' => 'string'], actual: $schema['properties']['templateMapping']['additionalProperties']);
	}//end testTheNotifyNlSchemaRequiresTheEncryptedKey()

	/**
	 * A key that cannot be decrypted is refused with the reason, before any request.
	 *
	 * @return void
	 */
	public function testAnUndecryptableKeyIsRefusedBeforeDispatch(): void {
		$crypto = $this->createMock(originalClassName: ICrypto::class);
		$crypto->method('decrypt')->willThrowException(exception: new RuntimeException(message: 'HMAC does not match'));
		$provider = $this->notifyNl(responses: [], crypto: $crypto);

		try {
			$provider->send(
				sourceConfiguration: ['authentication' => ['encryptedApiKey' => 'ciphertext']],
				to: '+31612345678',
				body: '',
				options: ['templateId' => 'tmpl-1']
			);
			$this->fail(message: 'an undecryptable key was used');
		} catch (SmsProviderException $exception) {
			$this->assertSame(
				expected: 'The stored NotifyNL API key could not be decrypted: HMAC does not match',
				actual: $exception->getMessage()
			);
		}

		$this->assertSame(expected: [], actual: $this->history);
	}//end testAnUndecryptableKeyIsRefusedBeforeDispatch()

	/**
	 * A status lookup without a status is queued with no detail.
	 *
	 * @return void
	 */
	public function testAStatusWithoutAStatusIsQueuedWithoutDetail(): void {
		$provider = $this->notifyNl(responses: [new Response(200, [], '{"id":"notify-id-1"}')], crypto: $this->workingCrypto());

		$result = $provider->fetchStatus(
			sourceConfiguration: ['baseUrl' => 'https://api.notifynl.example', 'authentication' => ['encryptedApiKey' => 'ciphertext']],
			providerMessageId: 'notify-id-1'
		);

		$this->assertSame(expected: ['providerMessageId' => 'notify-id-1', 'status' => 'queued', 'detail' => null], actual: $result->toArray());
	}//end testAStatusWithoutAStatusIsQueuedWithoutDetail()

	/**
	 * The log provider names itself as a sandbox and needs no configuration.
	 *
	 * @return void
	 */
	public function testTheLogProviderNeedsNoConfiguration(): void {
		$provider = new LogSmsProvider();

		$this->assertSame(expected: 'Sandbox / log (no network call)', actual: $provider->getProviderName());
		$this->assertSame(expected: ['type' => 'object', 'properties' => []], actual: $provider->getConfigSchema());
	}//end testTheLogProviderNeedsNoConfiguration()

	/**
	 * A delivery result serialises its id, status and detail in that order.
	 *
	 * @return void
	 */
	public function testADeliveryResultSerialises(): void {
		$this->assertSame(
			expected: ['providerMessageId' => 'm-1', 'status' => 'failed', 'detail' => 'permanent-failure'],
			actual: (new DeliveryResult(providerMessageId: 'm-1', status: 'failed', detail: 'permanent-failure'))->toArray()
		);
	}//end testADeliveryResultSerialises()
}//end class
