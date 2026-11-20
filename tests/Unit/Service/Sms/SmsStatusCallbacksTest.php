<?php

/**
 * Unit tests for reading CM.com, MessageBird and Twilio delivery callbacks.
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

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Sms\CmComSmsProvider;
use OCA\Integriq\Service\Sms\MessageBirdSmsProvider;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Signature checks and status mapping of the three vendors' callbacks.
 *
 * @covers \OCA\Integriq\Service\Sms\BrokeredSmsProvider
 * @covers \OCA\Integriq\Service\Sms\CmComSmsProvider
 * @covers \OCA\Integriq\Service\Sms\MessageBirdSmsProvider
 * @covers \OCA\Integriq\Service\Sms\TwilioSmsProvider
 * @covers \OCA\Integriq\Service\Sms\SmsStatusCallback
 * @uses   \OCA\Integriq\Service\Sms\DeliveryResult
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsStatusCallbacksTest extends TestCase {

	private const URL = 'https://cloud.example.nl/apps/integriq/api/sms/twilio/status/abc123?x=1';

	private const KEY = 'signing-key-of-the-source';

	/**
	 * A binding over a broker that is never called.
	 *
	 * @param string $class The binding class.
	 *
	 * @return CmComSmsProvider|MessageBirdSmsProvider|TwilioSmsProvider The binding.
	 */
	private function binding(string $class): object {
		$broker = $this->createMock(BrokeredCallService::class);
		$broker->expects($this->never())->method('dispatch');
		return new $class($broker, $this->createMock(LoggerInterface::class));

	}//end binding()

	/**
	 * The Twilio signature of a form body posted to a URL.
	 *
	 * @param string               $url    The URL.
	 * @param array<string,string> $fields The posted fields.
	 * @param string               $key    The auth token.
	 *
	 * @return string The signature.
	 */
	private static function twilioSignature(string $url, array $fields, string $key): string {
		ksort($fields);
		$signed = $url;
		foreach ($fields as $name => $value) {
			$signed .= $name . $value;
		}

		return base64_encode(hash_hmac('sha1', $signed, $key, true));

	}//end twilioSignature()

	/**
	 * A MessageBird signature JWT.
	 *
	 * @param array<string,mixed> $claims The claims.
	 * @param string              $key    The signing key.
	 *
	 * @return string The token.
	 */
	private static function messageBirdJwt(array $claims, string $key): string {
		$encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
		$head = $encode((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$body = $encode((string)json_encode($claims));
		return $head . '.' . $body . '.' . $encode(hash_hmac('sha256', $head . '.' . $body, $key, true));

	}//end messageBirdJwt()

	/**
	 * A signed Twilio callback reporting delivered marks the SID delivered.
	 *
	 * @return void
	 */
	public function testASignedTwilioCallbackReadsDelivered(): void {
		$fields = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered', 'AccountSid' => 'AC9'];
		$callback = new SmsStatusCallback(
			url: self::URL,
			rawBody: http_build_query($fields),
			params: $fields,
			headers: ['x-twilio-signature' => self::twilioSignature(url: self::URL, fields: $fields, key: self::KEY)],
			signingKey: self::KEY
		);

		$result = $this->binding(class: TwilioSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);

		$this->assertSame(['SM123', 'delivered', 'Twilio status: delivered'], [$result->providerMessageId, $result->status, $result->detail]);

	}//end testASignedTwilioCallbackReadsDelivered()

	/**
	 * A Twilio failure carries its error code.
	 *
	 * @return void
	 */
	public function testATwilioFailureCarriesItsErrorCode(): void {
		$fields = ['MessageSid' => 'SM124', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
		$callback = new SmsStatusCallback(
			url: self::URL,
			rawBody: http_build_query($fields),
			params: [],
			headers: ['x-twilio-signature' => self::twilioSignature(url: self::URL, fields: $fields, key: self::KEY)],
			signingKey: self::KEY
		);

		$result = $this->binding(class: TwilioSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);

		$this->assertSame(['failed', 'Twilio status: undelivered (error 30003)'], [$result->status, $result->detail]);

	}//end testATwilioFailureCarriesItsErrorCode()

	/**
	 * Twilio callbacks that must be refused.
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: string}>
	 */
	public static function badTwilioCallbacks(): array {
		$fields = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'];
		$good = self::twilioSignature(url: self::URL, fields: $fields, key: self::KEY);
		$body = http_build_query($fields);

		return [
			'signed with another key' => [$body, self::KEY, self::twilioSignature(url: self::URL, fields: $fields, key: 'other')],
			'a changed body' => [http_build_query(['MessageSid' => 'SM123', 'MessageStatus' => 'failed']), self::KEY, $good],
			'no signature' => [$body, self::KEY, ''],
			'no signing key stored' => [$body, null, $good],
		];

	}//end badTwilioCallbacks()

	/**
	 * A Twilio callback with a bad or missing signature is refused as such.
	 *
	 * @param string      $body      The posted body.
	 * @param string|null $key       The stored signing key.
	 * @param string      $signature The signature header.
	 *
	 * @return void
	 *
	 * @dataProvider badTwilioCallbacks
	 */
	public function testABadTwilioSignatureIsRefused(string $body, ?string $key, string $signature): void {
		$callback = new SmsStatusCallback(url: self::URL, rawBody: $body, params: [], headers: ['x-twilio-signature' => $signature], signingKey: $key);

		try {
			$this->binding(class: TwilioSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);
			$this->fail('A bad Twilio signature must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame('bad_signature', $exception->getErrorCode());
		}

	}//end testABadTwilioSignatureIsRefused()

	/**
	 * A signed Twilio callback that names no message is unreadable.
	 *
	 * @return void
	 */
	public function testASignedTwilioCallbackWithoutASidIsUnreadable(): void {
		$fields = ['MessageStatus' => 'delivered'];
		$callback = new SmsStatusCallback(
			url: self::URL,
			rawBody: http_build_query($fields),
			params: [],
			headers: ['x-twilio-signature' => self::twilioSignature(url: self::URL, fields: $fields, key: self::KEY)],
			signingKey: self::KEY
		);

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessage('The twilio status callback names no message.');
		$this->binding(class: TwilioSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);

	}//end testASignedTwilioCallbackWithoutASidIsUnreadable()

	/**
	 * A MessageBird report signed over its URL and body reads its id and mapped status.
	 *
	 * @return void
	 */
	public function testASignedMessageBirdReportReadsItsStatus(): void {
		$body = '{"id":"mb-1","status":"delivery_failed"}';
		$jwt = self::messageBirdJwt(
			claims: ['iss' => 'MessageBird', 'nbf' => time() - 5, 'exp' => time() + 60, 'url_hash' => hash('sha256', self::URL), 'payload_hash' => hash('sha256', $body)],
			key: self::KEY
		);
		$callback = new SmsStatusCallback(
			url: self::URL,
			rawBody: $body,
			params: ['id' => 'mb-1', 'status' => 'delivery_failed'],
			headers: ['messagebird-signature-jwt' => $jwt],
			signingKey: self::KEY
		);

		$result = $this->binding(class: MessageBirdSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);

		$this->assertSame(['mb-1', 'failed', 'MessageBird status: delivery_failed'], [$result->providerMessageId, $result->status, $result->detail]);

	}//end testASignedMessageBirdReportReadsItsStatus()

	/**
	 * MessageBird reports that must be refused.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function badMessageBirdReports(): array {
		$body = '{"id":"mb-1","status":"delivered"}';
		$claims = ['nbf' => time() - 5, 'exp' => time() + 60, 'url_hash' => hash('sha256', self::URL), 'payload_hash' => hash('sha256', $body)];

		return [
			'signed with another key' => [$body, self::messageBirdJwt(claims: $claims, key: 'other')],
			'another URL' => [$body, self::messageBirdJwt(claims: ['url_hash' => hash('sha256', 'https://elsewhere')] + $claims, key: self::KEY)],
			'a changed body' => ['{"id":"mb-1","status":"failed"}', self::messageBirdJwt(claims: $claims, key: self::KEY)],
			'expired' => [$body, self::messageBirdJwt(claims: ['exp' => time() - 3600] + $claims, key: self::KEY)],
			'not yet valid' => [$body, self::messageBirdJwt(claims: ['nbf' => time() + 3600] + $claims, key: self::KEY)],
			'not a token' => [$body, 'not-a-token'],
			'no header' => [$body, ''],
		];

	}//end badMessageBirdReports()

	/**
	 * A MessageBird report whose signature does not hold is refused.
	 *
	 * @param string $body The posted body.
	 * @param string $jwt  The signature header.
	 *
	 * @return void
	 *
	 * @dataProvider badMessageBirdReports
	 */
	public function testABadMessageBirdSignatureIsRefused(string $body, string $jwt): void {
		$callback = new SmsStatusCallback(url: self::URL, rawBody: $body, params: ['id' => 'mb-1', 'status' => 'delivered'], headers: ['messagebird-signature-jwt' => $jwt], signingKey: self::KEY);

		try {
			$this->binding(class: MessageBirdSmsProvider::class)->readStatusCallback(sourceConfiguration: [], callback: $callback);
			$this->fail('A bad MessageBird signature must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame('bad_signature', $exception->getErrorCode());
		}

	}//end testABadMessageBirdSignatureIsRefused()

	/**
	 * CM.com reports, flat and nested, and the status each reads as.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string, 3: string}>
	 */
	public static function cmComReports(): array {
		return [
			'flat delivered' => [['REFERENCE' => 'ref-1', 'STATUS' => '2'], 'ref-1', 'delivered', 'CM.com status code: 2'],
			'flat rejected' => [['reference' => 'ref-2', 'statuscode' => 1, 'errordescription' => 'Blocked'], 'ref-2', 'failed', 'CM.com status code: 1 (Blocked)'],
			'nested failed' => [
				['messages' => ['msg' => ['reference' => 'ref-3', 'status' => ['code' => '3', 'errorDescription' => 'Expired']]]],
				'ref-3',
				'failed',
				'CM.com status code: 3 (Expired)',
			],
			'nested list accepted' => [['messages' => ['msg' => [['reference' => 'ref-4', 'status' => ['code' => '0']]]]], 'ref-4', 'sent', 'CM.com status code: 0'],
		];

	}//end cmComReports()

	/**
	 * A CM.com report reads its reference and mapped status; CM.com signs nothing.
	 *
	 * @param array  $params    The report.
	 * @param string $reference The expected reference.
	 * @param string $status    The expected status.
	 * @param string $detail    The expected detail.
	 *
	 * @return void
	 *
	 * @dataProvider cmComReports
	 */
	public function testACmComReportReadsItsStatus(array $params, string $reference, string $status, string $detail): void {
		$binding = $this->binding(class: CmComSmsProvider::class);
		$result = $binding->readStatusCallback(sourceConfiguration: [], callback: new SmsStatusCallback(url: self::URL, rawBody: '', params: $params));

		$this->assertFalse($binding->signsStatusCallbacks());
		$this->assertSame([$reference, $status, $detail], [$result->providerMessageId, $result->status, $result->detail]);

	}//end testACmComReportReadsItsStatus()

	/**
	 * A CM.com report without a reference is unreadable, and the signing vendors say they sign.
	 *
	 * @return void
	 */
	public function testACmComReportWithoutAReferenceIsUnreadable(): void {
		$this->assertTrue($this->binding(class: TwilioSmsProvider::class)->signsStatusCallbacks());
		$this->assertTrue($this->binding(class: MessageBirdSmsProvider::class)->signsStatusCallbacks());

		try {
			$this->binding(class: CmComSmsProvider::class)->readStatusCallback(
				sourceConfiguration: [],
				callback: new SmsStatusCallback(url: self::URL, rawBody: '', params: ['STATUS' => '2'])
			);
			$this->fail('A report without a reference must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame('unreadable_callback', $exception->getErrorCode());
		}

	}//end testACmComReportWithoutAReferenceIsUnreadable()
}//end class
