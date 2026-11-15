<?php

/**
 * Unit tests for the CM.com, MessageBird and Twilio SMS bindings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Sms
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

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Sms\CmComSmsProvider;
use OCA\Integriq\Service\Sms\MessageBirdSmsProvider;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The three brokered SMS bindings against a stubbed credential broker.
 *
 * @covers \OCA\Integriq\Service\Sms\BrokeredSmsProvider
 * @covers \OCA\Integriq\Service\Sms\CmComSmsProvider
 * @covers \OCA\Integriq\Service\Sms\MessageBirdSmsProvider
 * @covers \OCA\Integriq\Service\Sms\TwilioSmsProvider
 * @uses   \OCA\Integriq\Service\Sms\DeliveryResult
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */
class BrokeredSmsProvidersTest extends TestCase {

	/**
	 * The stubbed broker.
	 *
	 * @var BrokeredCallService&MockObject
	 */
	private $broker;

	/**
	 * The dispatched calls: method, url and config.
	 *
	 * @var list<array{method: string, url: string, config: array}>
	 */
	private array $calls = [];

	/**
	 * Set up the broker stub.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->broker = $this->createMock(BrokeredCallService::class);
	}//end setUp()

	/**
	 * A source configuration with a broker reference.
	 *
	 * @param array $extra Extra configuration.
	 *
	 * @return array The configuration.
	 */
	private static function source(array $extra = []): array {
		return $extra + [
			'senderId' => 'Gemeente',
			'authentication' => ['credentialRef' => ['id' => 'cred-1']],
		];
	}//end source()

	/**
	 * Make the broker answer every call with one response, recording the calls.
	 *
	 * @param Response $response The answer.
	 *
	 * @return void
	 */
	private function brokerAnswers(Response $response): void {
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->broker->method('dispatch')->willReturnCallback(
			function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use ($response): Response {
				$this->calls[] = ['method' => $method, 'url' => $url, 'config' => $config];
				return $response;
			}
		);
	}//end brokerAnswers()

	/**
	 * CM.com posts one message through the broker and keeps the reference.
	 *
	 * @return void
	 */
	public function testCmComSendsThroughTheBrokerAndKeepsTheReference(): void {
		$this->brokerAnswers(new Response(200, [], json_encode(['messages' => [['status' => 'Accepted', 'reference' => 'ref-1']]])));
		$provider = new CmComSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$result = $provider->send(self::source(), '+31612345678', 'Uw afspraak', ['reference' => 'ref-1']);

		$this->assertSame('ref-1', $result->providerMessageId);
		$this->assertSame('sent', $result->status);
		$this->assertCount(1, $this->calls);
		$this->assertSame('POST', $this->calls[0]['method']);
		$this->assertSame('https://gw.messaging.cm.com/v1.0/message', $this->calls[0]['url']);
		$message = $this->calls[0]['config']['json']['messages']['msg'][0];
		$this->assertSame('Gemeente', $message['from']);
		$this->assertSame([['number' => '+31612345678']], $message['to']);
		$this->assertSame('Uw afspraak', $message['body']['content']);
		$this->assertSame(['credentialRef' => ['id' => 'cred-1']], $this->calls[0]['config']['authentication']);
	}//end testCmComSendsThroughTheBrokerAndKeepsTheReference()

	/**
	 * A CM.com message it does not accept is a failure naming CM.com's details.
	 *
	 * @return void
	 */
	public function testCmComRefusesAMessageItDidNotAccept(): void {
		$this->brokerAnswers(new Response(200, [], json_encode(['details' => 'Invalid number', 'messages' => [['status' => 'Rejected']]])));
		$provider = new CmComSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('Invalid number');

		$provider->send(self::source(), '+31612345678', 'x');
	}//end testCmComRefusesAMessageItDidNotAccept()

	/**
	 * Without a credentialRef nothing leaves and the reason names the credential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#scenario-a-twilio-source-without-a-credential-sends-nothing
	 */
	public function testNoCredentialRefSendsNothing(): void {
		$this->broker->method('hasCredentialRef')->willReturn(false);
		$this->broker->expects($this->never())->method('prepare');
		$this->broker->expects($this->never())->method('dispatch');
		$provider = new TwilioSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		try {
			$provider->send(self::source(['accountSid' => 'AC123']), '+31612345678', 'x');
			$this->fail('a source without a credential must not send');
		} catch (SmsProviderException $exception) {
			$this->assertSame('credential_missing', $exception->getErrorCode());
			$this->assertStringContainsString('credentialRef', $exception->getMessage());
		}
	}//end testNoCredentialRefSendsNothing()

	/**
	 * MessageBird sends and reads status through the broker, mapping the recipient status.
	 *
	 * @return void
	 */
	public function testMessageBirdSendsAndPollsThroughTheBroker(): void {
		$this->brokerAnswers(new Response(200, [], json_encode(['id' => 'mb-1', 'recipients' => ['items' => [['status' => 'delivered']]]])));
		$provider = new MessageBirdSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$sent = $provider->send(self::source(['baseUrl' => 'https://mb.example/']), '+31612345678', 'Hallo');
		$polled = $provider->fetchStatus(self::source(['baseUrl' => 'https://mb.example/']), 'mb-1');

		$this->assertSame('mb-1', $sent->providerMessageId);
		$this->assertSame('delivered', $polled->status);
		$this->assertSame(['POST', 'https://mb.example/messages'], [$this->calls[0]['method'], $this->calls[0]['url']]);
		$this->assertSame(['31612345678'], $this->calls[0]['config']['json']['recipients']);
		$this->assertSame('Gemeente', $this->calls[0]['config']['json']['originator']);
		$this->assertSame(['GET', 'https://mb.example/messages/mb-1'], [$this->calls[1]['method'], $this->calls[1]['url']]);
	}//end testMessageBirdSendsAndPollsThroughTheBroker()

	/**
	 * MessageBird's failure statuses map to failed.
	 *
	 * @return void
	 */
	public function testMessageBirdMapsDeliveryFailedToFailed(): void {
		$this->brokerAnswers(new Response(200, [], json_encode(['id' => 'mb-2', 'recipients' => ['items' => [['status' => 'delivery_failed']]]])));
		$provider = new MessageBirdSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->assertSame('failed', $provider->fetchStatus(self::source(), 'mb-2')->status);
	}//end testMessageBirdMapsDeliveryFailedToFailed()

	/**
	 * Twilio posts form-encoded to the account's Messages.json and stores the SID.
	 *
	 * @return void
	 */
	public function testTwilioPostsToTheAccountsMessagesAndStoresTheSid(): void {
		$this->brokerAnswers(new Response(201, [], json_encode(['sid' => 'SM1', 'status' => 'queued'])));
		$provider = new TwilioSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$result = $provider->send(self::source(['accountSid' => 'AC42']), '+31612345678', 'Hallo daar');

		$this->assertSame('SM1', $result->providerMessageId);
		$this->assertSame('queued', $result->status);
		$this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC42/Messages.json', $this->calls[0]['url']);
		$this->assertSame('application/x-www-form-urlencoded', $this->calls[0]['config']['headers']['Content-Type']);
		parse_str($this->calls[0]['config']['body'], $form);
		$this->assertSame(['To' => '+31612345678', 'From' => 'Gemeente', 'Body' => 'Hallo daar'], $form);
	}//end testTwilioPostsToTheAccountsMessagesAndStoresTheSid()

	/**
	 * Twilio's status read maps undelivered to failed and reads the SID's resource.
	 *
	 * @return void
	 */
	public function testTwilioStatusReadMapsUndeliveredToFailed(): void {
		$this->brokerAnswers(new Response(200, [], json_encode(['sid' => 'SM1', 'status' => 'undelivered', 'error_message' => 'Unreachable'])));
		$provider = new TwilioSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$result = $provider->fetchStatus(self::source(['accountSid' => 'AC42']), 'SM1');

		$this->assertSame('failed', $result->status);
		$this->assertStringContainsString('Unreachable', (string)$result->detail);
		$this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC42/Messages/SM1.json', $this->calls[0]['url']);
	}//end testTwilioStatusReadMapsUndeliveredToFailed()

	/**
	 * A Twilio source without an account SID sends nothing.
	 *
	 * @return void
	 */
	public function testTwilioWithoutAnAccountSidSendsNothing(): void {
		$this->broker->expects($this->never())->method('dispatch');
		$provider = new TwilioSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('accountSid');

		$provider->send(self::source(), '+31612345678', 'x');
	}//end testTwilioWithoutAnAccountSidSendsNothing()

	/**
	 * A source without a sender sends nothing.
	 *
	 * @return void
	 */
	public function testASourceWithoutASenderSendsNothing(): void {
		$this->broker->expects($this->never())->method('dispatch');
		$provider = new MessageBirdSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('senderId');

		$provider->send(['authentication' => ['credentialRef' => ['id' => 'c']]], '+31612345678', 'x');
	}//end testASourceWithoutASenderSendsNothing()

	/**
	 * A non-2xx answer is a failure naming the vendor and the status.
	 *
	 * @return void
	 */
	public function testANon2xxAnswerIsAFailure(): void {
		$this->brokerAnswers(new Response(401, [], 'unauthorised'));
		$provider = new TwilioSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		try {
			$provider->send(self::source(['accountSid' => 'AC42']), '+31612345678', 'x');
			$this->fail('a 401 must fail');
		} catch (SmsProviderException $exception) {
			$this->assertSame('Twilio responded with HTTP 401.', $exception->getMessage());
			$this->assertSame('provider_http_401', $exception->getErrorCode());
		}
	}//end testANon2xxAnswerIsAFailure()

	/**
	 * A broker configuration refusal is a provider failure with the broker's reason.
	 *
	 * @return void
	 */
	public function testABrokerRefusalIsAProviderFailure(): void {
		$this->broker->method('hasCredentialRef')->willReturn(true);
		$this->broker->method('prepare')->willThrowException(new BrokeredCallConfigurationException('credential not found'));
		$provider = new CmComSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('credential not found');

		$provider->send(self::source(), '+31612345678', 'x');
	}//end testABrokerRefusalIsAProviderFailure()

	/**
	 * CM.com has no status read and says that status arrives by callback.
	 *
	 * @return void
	 */
	public function testCmComHasNoStatusRead(): void {
		$this->broker->expects($this->never())->method('dispatch');
		$provider = new CmComSmsProvider($this->broker, $this->createMock(LoggerInterface::class));

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('callback');

		$provider->fetchStatus(self::source(), 'ref-1');
	}//end testCmComHasNoStatusRead()

	/**
	 * Each binding names its id and asks for a broker reference in its schema.
	 *
	 * @return void
	 */
	public function testEachBindingNamesItsIdAndRequiresACredentialRef(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$providers = [
			'cmcom' => new CmComSmsProvider($this->broker, $logger),
			'messagebird' => new MessageBirdSmsProvider($this->broker, $logger),
			'twilio' => new TwilioSmsProvider($this->broker, $logger),
		];

		foreach ($providers as $id => $provider) {
			$this->assertSame($id, $provider->getProviderId());
			$this->assertSame(['credentialRef'], $provider->getConfigSchema()['properties']['authentication']['required']);
		}

		$this->assertContains('accountSid', $providers['twilio']->getConfigSchema()['required']);
	}//end testEachBindingNamesItsIdAndRequiresACredentialRef()
}//end class
