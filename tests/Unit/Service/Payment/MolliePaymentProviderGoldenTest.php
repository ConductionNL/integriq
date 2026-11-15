<?php

/**
 * Golden test pinning every outcome of MolliePaymentProvider's payment creation and status lookup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/payment-provider/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Payment;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins the brokered call (config, method, URL), the returned arrays, and every refusal's
 * exact message, previous exception and log line, including the credential gate.
 */
class MolliePaymentProviderGoldenTest extends TestCase {

	/** @var array<int, array> Broker calls: [method, arguments]. */
	private array $calls = [];

	/** @var array<int, array> Log lines. */
	private array $logs = [];

	/**
	 * A provider over a recording broker.
	 *
	 * @param bool  $hasCredential What hasCredentialRef() answers.
	 * @param mixed $answer        The response dispatch() returns, or a Throwable prepare() throws.
	 *
	 * @return MolliePaymentProvider
	 */
	private function provider(bool $hasCredential, mixed $answer = null): MolliePaymentProvider {
		$this->calls = [];
		$this->logs = [];
		$broker = $this->createMock(BrokeredCallService::class);
		$broker->method('hasCredentialRef')->willReturnCallback(
			function (...$arguments) use ($hasCredential): bool {
				$this->calls[] = ['hasCredentialRef', $arguments];
				return $hasCredential;
			}
		);
		$broker->method('prepare')->willReturnCallback(
			function (...$arguments) use ($answer): array {
				$this->calls[] = ['prepare', $arguments];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ['credentialId' => 'cred-1', 'actingUserId' => 'u-1'];
			}
		);
		$broker->method('dispatch')->willReturnCallback(
			function (...$arguments) use ($answer) {
				$this->calls[] = ['dispatch', $arguments];
				return $answer;
			}
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $logs The shared log line list.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		return new MolliePaymentProvider($broker, $l, $logger);

	}//end provider()

	/**
	 * Describe what a call threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array [short class, message, previous class or null]
	 */
	private function thrown(callable $call): array {
		try {
			$call();
		} catch (\Exception $exception) {
			$previous = $exception->getPrevious();
			return [(new \ReflectionClass($exception))->getShortName(), $exception->getMessage(), ($previous === null ? null : $previous::class)];
		}

		return ['none'];

	}//end thrown()

	/**
	 * Every createPayment() outcome.
	 *
	 * @return void
	 */
	public function testCreatePayment(): void {
		$configuration = ['baseUrl' => 'https://mollie.example/v2/', 'authentication' => ['credentialRef' => 'ref-1']];
		$payload = ['amount' => ['value' => '10.00', 'currency' => 'EUR'], 'description' => 'Leges', 'redirectUrl' => 'https://r', 'webhookUrl' => 'https://w', 'method' => '', 'metadata' => ['case' => 'Z-1']];
		$checkout = ['id' => 'tr_1', '_links' => ['checkout' => ['href' => 'https://pay/tr_1']]];

		$this->assertSame(
			['providerPaymentId' => 'tr_1', 'paymentStatus' => 'open', 'checkoutUrl' => 'https://pay/tr_1', 'extras' => ['method' => '']],
			$this->provider(true, new Response(201, [], (string)json_encode($checkout)))->createPayment($configuration, $payload)
		);
		$config = ['authentication' => ['credentialRef' => 'ref-1'], 'json' => ['amount' => ['value' => '10.00', 'currency' => 'EUR'], 'description' => 'Leges', 'redirectUrl' => 'https://r', 'webhookUrl' => 'https://w', 'metadata' => ['case' => 'Z-1']]];
		$this->assertSame(
			[
				['hasCredentialRef', [$config]],
				['prepare', [$config, ['type' => 'payment'], false]],
				['dispatch', ['cred-1', 'u-1', 'POST', 'https://mollie.example/v2/payments', $config]],
			],
			$this->calls
		);

		$withMethod = $this->provider(true, new Response(200, [], (string)json_encode($checkout + ['status' => 'paid', 'method' => 'ideal'])))->createPayment(['authentication' => []], array_merge($payload, ['method' => 'creditcard', 'metadata' => []]));
		$this->assertSame(['providerPaymentId' => 'tr_1', 'paymentStatus' => 'paid', 'checkoutUrl' => 'https://pay/tr_1', 'extras' => ['method' => 'ideal']], $withMethod);
		$this->assertSame('https://api.mollie.com/v2/payments', $this->calls[2][1][3]);
		$this->assertSame('creditcard', $this->calls[2][1][4]['json']['method']);
		$this->assertArrayNotHasKey('metadata', $this->calls[2][1][4]['json']);

		$credential = 'Payment provider credential missing: the `mollie` payment provider requires `configuration.authentication.credentialRef` — none is configured. '
			. 'Configure a credentialRef through the OpenRegister credential broker; no plaintext-key fallback is permitted (ADR-007).';
		$this->assertSame(['PaymentProviderException', $credential, null], $this->thrown(fn () => $this->provider(false)->createPayment($configuration, $payload)));
		$this->assertSame(['hasCredentialRef'], array_column($this->calls, 0));

		$this->assertSame(
			['PaymentProviderException', 'bad broker config', BrokeredCallConfigurationException::class],
			$this->thrown(fn () => $this->provider(true, new BrokeredCallConfigurationException('bad broker config'))->createPayment($configuration, $payload))
		);
		$this->assertSame([], $this->logs);
		$this->assertSame(
			['PaymentProviderException', 'The Mollie request failed unexpectedly: down', RuntimeException::class],
			$this->thrown(fn () => $this->provider(true, new RuntimeException('down'))->createPayment($configuration, $payload))
		);
		$this->assertSame([['warning', '[MolliePaymentProvider] unexpected transport failure', ['exception' => 'down']]], $this->logs);
		$this->assertSame(['PaymentProviderException', 'Mollie responded with HTTP 422.', null], $this->thrown(fn () => $this->provider(true, new Response(422, [], '{}'))->createPayment($configuration, $payload)));
		$this->assertSame(['PaymentProviderException', 'Mollie returned a non-JSON response for payment creation.', null], $this->thrown(fn () => $this->provider(true, new Response(200, [], 'x'))->createPayment($configuration, $payload)));
		foreach ([['id' => 'tr_1'], ['_links' => ['checkout' => ['href' => 'u']]], ['id' => '', '_links' => ['checkout' => ['href' => 'u']]], ['id' => 'tr_1', '_links' => ['checkout' => ['href' => 7]]]] as $index => $body) {
			$this->assertSame(
				['PaymentProviderException', 'Mollie accepted the request but returned no usable payment id or checkout URL.', null],
				$this->thrown(fn () => $this->provider(true, new Response(200, [], (string)json_encode($body)))->createPayment($configuration, $payload)),
				'body ' . $index
			);
		}

	}//end testCreatePayment()

	/**
	 * Every fetchPaymentStatus() outcome.
	 *
	 * @return void
	 */
	public function testFetchPaymentStatus(): void {
		$configuration = ['baseUrl' => '', 'authentication' => ['credentialRef' => 'ref-1']];

		$this->assertSame(['providerPaymentId' => 'tr 1/x', 'paymentStatus' => 'paid'], $this->provider(true, new Response(200, [], '{"status":"paid"}'))->fetchPaymentStatus($configuration, 'tr 1/x'));
		$this->assertSame(['dispatch', ['cred-1', 'u-1', 'GET', 'https://api.mollie.com/v2/payments/tr%201%2Fx', ['authentication' => ['credentialRef' => 'ref-1']]]], $this->calls[2]);
		$this->assertSame(['providerPaymentId' => 'tr_2', 'paymentStatus' => ''], $this->provider(true, new Response(200, [], '{}'))->fetchPaymentStatus($configuration, 'tr_2'));
		$this->assertSame(
			['PaymentProviderException', 'Mollie returned a non-JSON response for the payment status lookup.', null],
			$this->thrown(fn () => $this->provider(true, new Response(200, [], 'x'))->fetchPaymentStatus($configuration, 'tr_2'))
		);

	}//end testFetchPaymentStatus()
}//end class
