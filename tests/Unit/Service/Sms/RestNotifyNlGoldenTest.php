<?php

/**
 * Golden tests for the NotifyNL provider's send, status and dispatch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the requests and outcomes of send and fetchStatus — the payload, the
 * sender id, the URL, the headers (the signed token masked), every refusal
 * and the transport warning.
 */
class RestNotifyNlGoldenTest extends TestCase {

	private const API_KEY = 'integriq-11111111-2222-3333-4444-555555555555-66666666-7777-8888-9999-000000000000';

	/**
	 * Requests seen.
	 *
	 * @var array<int, array{request: Request}>
	 */
	private array $history = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, array>
	 */
	private array $warnings = [];

	/**
	 * A provider over the given answers.
	 *
	 * @param array $answers Responses or exceptions.
	 *
	 * @return RestNotifyNlProvider The provider.
	 */
	private function provider(array $answers): RestNotifyNlProvider {
		$this->history  = [];
		$this->warnings = [];
		$stack          = HandlerStack::create(new MockHandler($answers));
		$stack->push(Middleware::history($this->history));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn(self::API_KEY);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new RestNotifyNlProvider(new Client(['handler' => $stack]), $crypto, $l10n, $logger);

	}//end provider()

	/**
	 * The request, with the signed token masked.
	 *
	 * @return array The method, URL, headers and body.
	 */
	private function request(): array {
		$request = $this->history[0]['request'];
		$this->assertMatchesRegularExpression('/^Bearer [\w-]+\.[\w-]+\.[\w-]+$/', $request->getHeaderLine('Authorization'));
		return [$request->getMethod(), (string) $request->getUri(), $request->getHeaderLine('Content-Type'), (string) $request->getBody()];

	}//end request()

	/**
	 * Capture a refusal.
	 *
	 * @param callable $call The call.
	 *
	 * @return string The message.
	 */
	private static function refusal(callable $call): string {
		try {
			$call();
		} catch (SmsProviderException $exception) {
			return $exception->getMessage();
		}

		return 'not refused';

	}//end refusal()

	/**
	 * Send: the payload with and without a sender id, and the result.
	 *
	 * @return void
	 */
	public function testSend(): void {
		$config   = ['baseUrl' => 'https://notify.example/', 'authentication' => ['encryptedApiKey' => 'x'], 'senderId' => 'Gemeente', 'templateMapping' => ['welkom' => 'T-1']];
		$provider = $this->provider([new Response(201, [], '{"id":"N-1"}')]);
		$result   = $provider->send($config, '+31612345678', 'ignored', ['templateId' => 'welkom', 'personalisation' => ['naam' => 'Jan']]);
		$this->assertSame(['N-1', 'queued', null], [$result->providerMessageId, $result->status, $result->detail]);
		$this->assertSame(
			['POST', 'https://notify.example/v2/notifications/sms', 'application/json', '{"phone_number":"+31612345678","template_id":"T-1","personalisation":{"naam":"Jan"},"sms_sender_id":"Gemeente"}'],
			$this->request()
		);

		$provider = $this->provider([new Response(200, [], '{"id":"N-2"}')]);
		$provider->send(['authentication' => ['encryptedApiKey' => 'x']], '+31600000000', 'b', ['templateId' => 'raw', 'personalisation' => 'not-an-array']);
		$this->assertSame(
			['POST', 'https://api.notifynl.nl/v2/notifications/sms', 'application/json', '{"phone_number":"+31600000000","template_id":"raw","personalisation":[]}'],
			$this->request()
		);

	}//end testSend()

	/**
	 * Refusals of send and fetchStatus, and the transport warning.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$config = ['authentication' => ['encryptedApiKey' => 'x']];
		foreach (['{}', '{"id":""}', '{"id":5}', 'nope'] as $body) {
			$provider = $this->provider([new Response(201, [], $body)]);
			$this->assertSame('NotifyNL accepted the request but returned no usable notification id.', self::refusal(static fn () => $provider->send($config, '+31', 'b', ['templateId' => 't'])));
		}

		foreach ([199, 300, 500] as $status) {
			$provider = $this->provider([new Response($status, [], '{"id":"N"}')]);
			$this->assertSame('NotifyNL responded with HTTP '.$status.'.', self::refusal(static fn () => $provider->send($config, '+31', 'b', ['templateId' => 't'])));
		}

		$provider = $this->provider([new Response(200, [], 'nope')]);
		$this->assertSame('NotifyNL returned a non-JSON response for the status lookup.', self::refusal(static fn () => $provider->fetchStatus($config, 'N 1')));
		$this->assertSame(['GET', 'https://api.notifynl.nl/v2/notifications/N%201', 'application/json', ''], $this->request());

		$provider = $this->provider([new ConnectException('down', new Request('GET', 'https://api.notifynl.nl'))]);
		$this->assertSame('The NotifyNL request failed unexpectedly: down', self::refusal(static fn () => $provider->fetchStatus($config, 'N')));
		$this->assertSame([['[RestNotifyNlProvider] unexpected transport failure', ['exception' => 'down']]], $this->warnings);

	}//end testRefusals()
}//end class
