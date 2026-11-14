<?php

/**
 * The CTI bindings themselves: their ids, their configuration schemas, signature verification and turning a payload into call events.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\LogCtiProvider;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Kiss\LogCtiProvider
 * @covers \OCA\Integriq\Service\Kiss\WebhookCtiProvider
 * @uses   \OCA\Integriq\Service\Kiss\CallEventNormaliser
 * @uses   \OCA\Integriq\Service\Kiss\MappedPayload
 */
class CtiBindingsTest extends TestCase {

	/**
	 * A call event without number, agent or moment, as the normaliser shapes it.
	 *
	 * @param string $kind   The kind.
	 * @param string $callId The call id.
	 *
	 * @return array<string, string>
	 */
	private static function event(string $kind, string $callId): array {
		return ['kind' => $kind, 'callId' => $callId, 'callerNumber' => '', 'agentId' => '', 'at' => ''];
	}//end event()

	/**
	 * The log binding is named log and requires a shared secret.
	 *
	 * @return void
	 */
	public function testTheLogBindingRequiresASharedSecret(): void {
		$provider = new LogCtiProvider(new CallEventNormaliser(), $this->createMock(LoggerInterface::class));

		$this->assertSame('log', $provider->getProviderId());
		$schema = $provider->getConfigSchema();
		$this->assertSame('object', $schema['type']);
		$this->assertSame(['sharedSecret'], $schema['required']);
		$this->assertSame(['sharedSecret'], array_keys($schema['properties']));
		$this->assertSame('string', $schema['properties']['sharedSecret']['type']);
	}//end testTheLogBindingRequiresASharedSecret()

	/**
	 * The log binding normalises an events list or a single event, drops what is no event, and logs each one.
	 *
	 * @return void
	 */
	public function testTheLogBindingNormalisesAndLogsEachEvent(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(static function (string $message) use (&$logged): void {
			$logged[] = $message;
		});
		$provider = new LogCtiProvider(new CallEventNormaliser(), $logger);

		$events = $provider->normalize([], ['events' => [['kind' => 'ringing', 'callId' => 'c-1'], 'noise', ['kind' => 'hold', 'callId' => 'c-1'], ['kind' => 'answered', 'callId' => 'c-2']]]);
		$this->assertSame([self::event('ringing', 'c-1'), self::event('answered', 'c-2')], $events);
		$this->assertSame(['[LogCtiProvider] ringing on call c-1', '[LogCtiProvider] answered on call c-2'], $logged);

		$this->assertSame([self::event('transferred', 'c-3')], $provider->normalize([], ['kind' => 'transferred', 'callId' => 'c-3']));
		$this->assertSame([], $provider->normalize([], ['events' => 'not a list']));
		$this->assertSame([], $provider->normalize([], ['kind' => 'ringing']), 'no call id, no event');
	}//end testTheLogBindingNormalisesAndLogsEachEvent()

	/**
	 * The webhook binding is named webhook and offers a signature or a shared secret, and the mappings.
	 *
	 * @return void
	 */
	public function testTheWebhookBindingSchema(): void {
		$provider = new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(WebhookSignatureService::class));

		$this->assertSame('webhook', $provider->getProviderId());
		$schema = $provider->getConfigSchema();
		$this->assertSame('object', $schema['type']);
		$this->assertArrayNotHasKey('required', $schema, 'either secret will do, so neither is required on its own');
		$this->assertSame(['signatureSecret', 'sharedSecret', 'eventsPath', 'fieldMapping', 'kindMapping'], array_keys($schema['properties']));
		$this->assertSame('object', $schema['properties']['fieldMapping']['type']);
		$this->assertSame('object', $schema['properties']['kindMapping']['type']);
	}//end testTheWebhookBindingSchema()

	/**
	 * With a signature secret the signature service decides, over the raw body, the signature and the timestamp.
	 *
	 * @return void
	 */
	public function testASignatureSecretIsVerifiedByTheSignatureService(): void {
		$signatures = $this->createMock(WebhookSignatureService::class);
		$signatures->expects($this->exactly(2))->method('verify')
			->with('{"kind":"ringing"}', 'sha256=abc', ['secret' => 'hmac-secret', 'timestamp' => '1700000000'])
			->willReturnOnConsecutiveCalls(true, false);
		$provider = new WebhookCtiProvider(new CallEventNormaliser(), $signatures);
		$config   = ['signatureSecret' => 'hmac-secret', 'sharedSecret' => 'shared'];
		$headers  = ['x-signature' => 'sha256=abc', 'x-timestamp' => '1700000000', 'x-api-key' => 'shared'];

		$this->assertTrue($provider->verify($config, $headers, '{"kind":"ringing"}'));
		$this->assertFalse($provider->verify($config, $headers, '{"kind":"ringing"}'), 'a failed signature is not rescued by a matching shared secret');
	}//end testASignatureSecretIsVerifiedByTheSignatureService()

	/**
	 * Without a signature secret a shared secret must match the API key; with neither, nothing is admitted.
	 *
	 * @return void
	 */
	public function testWithoutASignatureTheSharedSecretMustMatch(): void {
		$signatures = $this->createMock(WebhookSignatureService::class);
		$signatures->expects($this->never())->method('verify');
		$provider = new WebhookCtiProvider(new CallEventNormaliser(), $signatures);

		$this->assertTrue($provider->verify(['sharedSecret' => 'shared'], ['x-api-key' => 'shared'], ''));
		$this->assertFalse($provider->verify(['sharedSecret' => 'shared'], ['x-api-key' => 'other'], ''));
		$this->assertFalse($provider->verify(['sharedSecret' => 'shared'], [], ''));
		$this->assertFalse($provider->verify([], ['x-api-key' => ''], ''));
	}//end testWithoutASignatureTheSharedSecretMustMatch()

	/**
	 * The webhook binding reads the events at the path, maps the vendor's kinds and fields, and drops the rest.
	 *
	 * @return void
	 */
	public function testTheWebhookBindingReadsEventsAtThePath(): void {
		$provider = new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(WebhookSignatureService::class));
		$config   = [
			'eventsPath' => 'data.calls',
			'fieldMapping' => ['kind' => 'state', 'callId' => 'uniqueid'],
			'kindMapping' => ['alerting' => 'ringing'],
		];
		$payload  = ['data' => ['calls' => [['state' => 'alerting', 'uniqueid' => 'u-1'], 'noise', ['state' => 'parked', 'uniqueid' => 'u-2']]]];

		$this->assertSame([self::event('ringing', 'u-1')], $provider->normalize($config, $payload));
		$this->assertSame([], $provider->normalize($config, ['data' => ['other' => []]]), 'a path that is not there');
		$this->assertSame([], $provider->normalize($config, ['data' => ['calls' => 'one']]), 'a path that is no list');
		$this->assertSame([], $provider->normalize($config, ['data' => 'flat']), 'a path through a scalar');
	}//end testTheWebhookBindingReadsEventsAtThePath()

	/**
	 * Without a path the body is one event; mappings that are no objects are ignored.
	 *
	 * @return void
	 */
	public function testWithoutAPathTheBodyIsOneEvent(): void {
		$provider = new WebhookCtiProvider(new CallEventNormaliser(), $this->createMock(WebhookSignatureService::class));

		$this->assertSame(
			[self::event('answered', 'c-9')],
			$provider->normalize(['fieldMapping' => 'kind=state', 'kindMapping' => 7], ['kind' => 'answered', 'callId' => 'c-9'])
		);
	}//end testWithoutAPathTheBodyIsOneEvent()
}//end class
