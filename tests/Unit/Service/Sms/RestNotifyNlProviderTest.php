<?php

/**
 * Unit tests for RestNotifyNlProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/notifynl-sms-channel/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\SmsProviderException;
use OCA\OpenConnector\Service\Sms\RestNotifyNlProvider;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST NotifyNL provider (JWT-signed dispatch).
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-notifynl-rest-provider-with-jwt-signed-requests
 */
class RestNotifyNlProviderTest extends TestCase {

	/**
	 * A well-formed `<name>-<serviceId>-<secret>` NotifyNL API key (fixture).
	 *
	 * @var string
	 */
	private const FIXTURE_API_KEY = 'my_key_name-11111111-1111-1111-1111-111111111111-22222222-2222-2222-2222-222222222222';

	/**
	 * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
	 */
	private array $history = [];

	/**
	 * @var ICrypto|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $crypto;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * The valid configuration used by every "happy path" test.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://api.notifynl.example',
		'authentication' => ['encryptedApiKey' => 'ciphertext-blob'],
	];

	/**
	 * Set up test fixtures (crypto/l10n/logger mocks — HTTP is mocked per-test via buildProvider()).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('decrypt')->willReturn(self::FIXTURE_API_KEY);

		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build a provider whose Guzzle client is backed by a MockHandler queue,
	 * recording every issued request into $this->history.
	 *
	 * @param array<int,Response> $responses The queued mock responses.
	 *
	 * @return RestNotifyNlProvider The provider under test.
	 */
	private function buildProvider(array $responses): RestNotifyNlProvider {
		$mock = new MockHandler($responses);
		$stack = HandlerStack::create($mock);

		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new RestNotifyNlProvider(
			new Client(['handler' => $stack]),
			$this->crypto,
			$this->l,
			$this->logger
		);

	}//end buildProvider()

	/**
	 * Decode the `Authorization: Bearer <jwt>` header of a recorded request into its header + payload claims.
	 *
	 * @param \GuzzleHttp\Psr7\Request $request The recorded request.
	 *
	 * @return array{header: array, payload: array} The decoded JWT header and payload.
	 */
	private function decodeBearerJwt($request): array {
		$authorization = $request->getHeaderLine('Authorization');
		$this->assertStringStartsWith('Bearer ', $authorization);

		$jwt = substr($authorization, strlen('Bearer '));
		$segments = explode('.', $jwt);
		$this->assertCount(3, $segments);

		$decode = static function (string $segment): array {
			$padded = strtr($segment, '-_', '+/');
			$padded .= str_repeat('=', (4 - (strlen($padded) % 4)) % 4);
			return json_decode(base64_decode($padded), true);
		};

		return ['header' => $decode($segments[0]), 'payload' => $decode($segments[1])];
	}//end decodeBearerJwt()

	/**
	 * send() signs an HS256 JWT with `iss` set to the API key's service-id segment.
	 *
	 * @return void
	 */
	public function testSendSignsJwtWithServiceIdIssuer(): void {
		$provider = $this->buildProvider([new Response(201, [], json_encode(['id' => 'notify-id-1']))]);

		$provider->send(
			sourceConfiguration: $this->configuration,
			to: '+31612345678',
			body: 'ignored',
			options: ['templateId' => 'tmpl-1', 'personalisation' => ['name' => 'Jan']]
		);

		$this->assertCount(1, $this->history);
		$jwt = $this->decodeBearerJwt($this->history[0]['request']);

		$this->assertSame('HS256', $jwt['header']['alg']);
		$this->assertSame('JWT', $jwt['header']['typ']);
		$this->assertSame('11111111-1111-1111-1111-111111111111', $jwt['payload']['iss']);
		$this->assertIsInt($jwt['payload']['iat']);

	}//end testSendSignsJwtWithServiceIdIssuer()

	/**
	 * send() posts the phone_number/template_id/personalisation payload NotifyNL expects.
	 *
	 * @return void
	 */
	public function testSendPostsExpectedPayloadShape(): void {
		$provider = $this->buildProvider([new Response(201, [], json_encode(['id' => 'notify-id-1']))]);

		$provider->send(
			sourceConfiguration: $this->configuration,
			to: '+31612345678',
			body: 'ignored',
			options: ['templateId' => 'tmpl-1', 'personalisation' => ['name' => 'Jan']]
		);

		$request = $this->history[0]['request'];
		$body = json_decode((string)$request->getBody(), true);

		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('/v2/notifications/sms', $request->getUri()->getPath());
		$this->assertSame('+31612345678', $body['phone_number']);
		$this->assertSame('tmpl-1', $body['template_id']);
		$this->assertSame(['name' => 'Jan'], $body['personalisation']);

	}//end testSendPostsExpectedPayloadShape()

	/**
	 * A logical template name is resolved via `configuration.templateMapping`.
	 *
	 * @return void
	 */
	public function testSendResolvesLogicalTemplateNameViaMapping(): void {
		$provider = $this->buildProvider([new Response(201, [], json_encode(['id' => 'notify-id-1']))]);
		$configuration = $this->configuration;
		$configuration['templateMapping'] = ['reminder' => 'real-notifynl-template-uuid'];

		$provider->send(sourceConfiguration: $configuration, to: '+31612345678', body: '', options: ['templateId' => 'reminder']);

		$body = json_decode((string)$this->history[0]['request']->getBody(), true);
		$this->assertSame('real-notifynl-template-uuid', $body['template_id']);

	}//end testSendResolvesLogicalTemplateNameViaMapping()

	/**
	 * send() returns a `queued` DeliveryResult carrying NotifyNL's `id`.
	 *
	 * @return void
	 */
	public function testSendReturnsQueuedDeliveryResult(): void {
		$provider = $this->buildProvider([new Response(201, [], json_encode(['id' => 'notify-id-1']))]);

		$result = $provider->send(sourceConfiguration: $this->configuration, to: '+31612345678', body: '', options: ['templateId' => 'tmpl-1']);

		$this->assertSame('notify-id-1', $result->providerMessageId);
		$this->assertSame('queued', $result->status);

	}//end testSendReturnsQueuedDeliveryResult()

	/**
	 * A missing templateId is rejected before any network call.
	 *
	 * @return void
	 */
	public function testSendWithoutTemplateIdThrowsBeforeDispatch(): void {
		$provider = $this->buildProvider([]);

		$this->expectException(SmsProviderException::class);

		$provider->send(sourceConfiguration: $this->configuration, to: '+31612345678', body: 'hi');

	}//end testSendWithoutTemplateIdThrowsBeforeDispatch()

	/**
	 * A missing credential is rejected before any network call.
	 *
	 * @return void
	 */
	public function testSendWithoutCredentialThrowsBeforeDispatch(): void {
		$provider = $this->buildProvider([]);

		$this->expectException(SmsProviderException::class);

		$provider->send(
			sourceConfiguration: ['baseUrl' => 'https://api.notifynl.example'],
			to: '+31612345678',
			body: '',
			options: ['templateId' => 'tmpl-1']
		);

	}//end testSendWithoutCredentialThrowsBeforeDispatch()

	/**
	 * A malformed (too-short) decrypted API key is rejected with a clear, secret-free message.
	 *
	 * @return void
	 */
	public function testMalformedApiKeyIsRejected(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('decrypt')->willReturn('too-short');

		$provider = $this->buildProvider([]);

		$this->expectException(SmsProviderException::class);
		$this->expectExceptionMessageMatches('/malformed/');

		$provider->send(sourceConfiguration: $this->configuration, to: '+31612345678', body: '', options: ['templateId' => 'tmpl-1']);

	}//end testMalformedApiKeyIsRejected()

	/**
	 * A non-2xx NotifyNL response is a descriptive SmsProviderException, never a crash.
	 *
	 * @return void
	 */
	public function testSendNonSuccessStatusIsMappedNotCrash(): void {
		$provider = $this->buildProvider([new Response(503, [], 'upstream down')]);

		$this->expectException(SmsProviderException::class);

		$provider->send(sourceConfiguration: $this->configuration, to: '+31612345678', body: '', options: ['templateId' => 'tmpl-1']);

	}//end testSendNonSuccessStatusIsMappedNotCrash()

	/**
	 * fetchStatus() issues a GET to `/v2/notifications/{id}` and maps `delivered` verbatim.
	 *
	 * @return void
	 */
	public function testFetchStatusMapsDelivered(): void {
		$provider = $this->buildProvider([new Response(200, [], json_encode(['status' => 'delivered']))]);

		$result = $provider->fetchStatus(sourceConfiguration: $this->configuration, providerMessageId: 'notify-id-1');

		$this->assertSame('delivered', $result->status);
		$this->assertSame('GET', $this->history[0]['request']->getMethod());
		$this->assertSame('/v2/notifications/notify-id-1', $this->history[0]['request']->getUri()->getPath());

	}//end testFetchStatusMapsDelivered()

	/**
	 * fetchStatus() maps NotifyNL's in-flight statuses to the normalised `sent`.
	 *
	 * @return void
	 */
	public function testFetchStatusMapsSendingToSent(): void {
		$provider = $this->buildProvider([new Response(200, [], json_encode(['status' => 'sending']))]);

		$result = $provider->fetchStatus(sourceConfiguration: $this->configuration, providerMessageId: 'notify-id-1');

		$this->assertSame('sent', $result->status);

	}//end testFetchStatusMapsSendingToSent()

	/**
	 * fetchStatus() maps NotifyNL's failure statuses to the normalised `failed`.
	 *
	 * @return void
	 */
	public function testFetchStatusMapsPermanentFailureToFailed(): void {
		$provider = $this->buildProvider([new Response(200, [], json_encode(['status' => 'permanent-failure']))]);

		$result = $provider->fetchStatus(sourceConfiguration: $this->configuration, providerMessageId: 'notify-id-1');

		$this->assertSame('failed', $result->status);

	}//end testFetchStatusMapsPermanentFailureToFailed()

	/**
	 * fetchStatus() maps an unrecognised NotifyNL status to the conservative default `queued`.
	 *
	 * @return void
	 */
	public function testFetchStatusMapsUnknownStatusToQueued(): void {
		$provider = $this->buildProvider([new Response(200, [], json_encode(['status' => 'some-new-status']))]);

		$result = $provider->fetchStatus(sourceConfiguration: $this->configuration, providerMessageId: 'notify-id-1');

		$this->assertSame('queued', $result->status);

	}//end testFetchStatusMapsUnknownStatusToQueued()

	/**
	 * getProviderId/getProviderName expose the stable identifiers used by SmsDispatchService::resolveProvider().
	 *
	 * @return void
	 */
	public function testProviderIdentity(): void {
		$provider = $this->buildProvider([]);

		$this->assertSame('notifynl', $provider->getProviderId());
		$this->assertSame('NotifyNL', $provider->getProviderName());

	}//end testProviderIdentity()
}//end class
