<?php

/**
 * Unit tests for SmsStatusCallbackService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\CmComSmsProvider;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
use OCA\Integriq\Service\SmsDispatchServiceInterface;
use OCA\Integriq\Service\SmsStatusCallbackService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The token picks the source; the binding checks; the dispatcher applies.
 *
 * @covers \OCA\Integriq\Service\SmsStatusCallbackService
 * @uses   \OCA\Integriq\Service\Sms\SmsProviderRegistry
 * @uses   \OCA\Integriq\Service\Sms\SmsStatusCallback
 * @uses   \OCA\Integriq\Service\Sms\BrokeredSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\CmComSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\TwilioSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\DeliveryResult
 * @uses   \OCA\Integriq\Service\Security\RawSourceResolver
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsStatusCallbackServiceTest extends TestCase {

	private const TOKEN = 'abcdefabcdefabcdefabcdefabcdefabcdef0123456789ab';

	/**
	 * The object service mock.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * The dispatcher mock.
	 *
	 * @var SmsDispatchServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $dispatch;

	/**
	 * The service under test.
	 *
	 * @var SmsStatusCallbackService
	 */
	private SmsStatusCallbackService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->dispatch = $this->createMock(SmsDispatchServiceInterface::class);
		$broker = $this->createMock(BrokeredCallService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$log = $this->createMock(LogSmsProvider::class);
		$log->method('getProviderId')->willReturn('log');
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if ($cipher !== 'enc(auth-token)') {
					throw new \RuntimeException('does not decrypt');
				}

				return 'auth-token';
			}
		);

		$this->service = new SmsStatusCallbackService(
			objectService: $this->objectService,
			rawSourceResolver: new RawSourceResolver($this->objectService, new NullLogger()),
			providers: new SmsProviderRegistry(
				logProvider: $log,
				cmCom: new CmComSmsProvider($broker, $logger),
				twilio: new TwilioSmsProvider($broker, $logger)
			),
			dispatch: $this->dispatch,
			crypto: $crypto
		);

	}//end setUp()

	/**
	 * An unpersisted source entity.
	 *
	 * @param array<string,mixed> $configuration The configuration.
	 *
	 * @return ObjectEntity
	 */
	private static function source(array $configuration): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['type' => 'sms', 'isEnabled' => true, 'configuration' => $configuration]);
		$entity->setUuid('');
		return $entity;

	}//end source()

	/**
	 * The token picks the enabled SMS source of the provider that carries it.
	 *
	 * @return void
	 */
	public function testTheTokenPicksTheSourceOfThatProvider(): void {
		$cm = self::source(['provider' => 'cmcom', 'statusCallbackToken' => self::TOKEN]);
		$other = self::source(['provider' => 'cmcom', 'statusCallbackToken' => strrev(self::TOKEN)]);
		$filters = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$filters, $cm, $other): array {
				$filters[] = $config['filters'];
				return ['results' => [$other, $cm], 'total' => 2];
			}
		);

		$this->assertSame($cm, $this->service->sourceFor(provider: 'cmcom', token: self::TOKEN));
		$this->assertSame([['register' => 'integriq', 'schema' => 'source', 'type' => 'sms', 'isEnabled' => true]], $filters);

	}//end testTheTokenPicksTheSourceOfThatProvider()

	/**
	 * A wrong token, another provider's token, an empty token or a binding without callbacks matches nothing.
	 *
	 * @return void
	 */
	public function testNoSourceAnswersToAWrongToken(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				'results' => [
					self::source(['provider' => 'twilio', 'statusCallbackToken' => self::TOKEN]),
					self::source(['provider' => 'cmcom']),
					self::source(['provider' => 'log', 'statusCallbackToken' => self::TOKEN]),
				],
			]
		);

		$this->assertNull($this->service->sourceFor(provider: 'cmcom', token: self::TOKEN));
		$this->assertNull($this->service->sourceFor(provider: 'twilio', token: self::TOKEN . 'x'));
		$this->assertNull($this->service->sourceFor(provider: 'twilio', token: ''));
		$this->assertNull($this->service->sourceFor(provider: 'log', token: self::TOKEN));
		$this->assertNull($this->service->sourceFor(provider: 'twillio', token: self::TOKEN));

	}//end testNoSourceAnswersToAWrongToken()

	/**
	 * A signed Twilio callback is checked with the decrypted key and its status applied.
	 *
	 * @return void
	 */
	public function testACheckedCallbackIsAppliedToItsMessage(): void {
		$url = 'https://cloud.example.nl/apps/integriq/api/sms/twilio/status/' . self::TOKEN;
		$fields = ['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'];
		$signature = base64_encode(hash_hmac('sha1', $url . 'MessageSidSM1MessageStatusdelivered', 'auth-token', true));
		$updated = new ObjectEntity();
		$this->dispatch->expects($this->once())->method('handleStatusCallback')
			->with('SM1', 'delivered', 'Twilio status: delivered')
			->willReturn($updated);

		$result = $this->service->receive(
			source: self::source(['provider' => 'twilio', 'encryptedWebhookSigningKey' => 'enc(auth-token)']),
			callback: new SmsStatusCallback(url: $url, rawBody: http_build_query($fields), params: $fields, headers: ['x-twilio-signature' => $signature], signingKey: 'ignored')
		);

		$this->assertSame($updated, $result);

	}//end testACheckedCallbackIsAppliedToItsMessage()

	/**
	 * A key that does not decrypt, or none, refuses a signing vendor's callback and changes nothing.
	 *
	 * @return void
	 */
	public function testACallbackWithoutAUsableKeyChangesNothing(): void {
		$url = 'https://cloud.example.nl/x';
		$signature = base64_encode(hash_hmac('sha1', $url . 'MessageSidSM1', 'auth-token', true));
		$this->dispatch->expects($this->never())->method('handleStatusCallback');

		foreach ([['encryptedWebhookSigningKey' => 'garbage'], []] as $key) {
			try {
				$this->service->receive(
					source: self::source(['provider' => 'twilio'] + $key),
					callback: new SmsStatusCallback(url: $url, rawBody: 'MessageSid=SM1', params: [], headers: ['x-twilio-signature' => $signature])
				);
				$this->fail('A callback without a usable signing key must be refused.');
			} catch (SmsProviderException $exception) {
				$this->assertSame('bad_signature', $exception->getErrorCode());
			}
		}

		$this->expectException(SmsProviderException::class);
		$this->service->receive(source: self::source(['provider' => 'log']), callback: new SmsStatusCallback(url: $url, rawBody: '', params: []));

	}//end testACallbackWithoutAUsableKeyChangesNothing()
}//end class
