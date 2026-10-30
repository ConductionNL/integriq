<?php

/**
 * Characterization tests for EventService::deliverMessage().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the request deliverMessage() sends, the trace step it records and the
 * message it saves, for the paths EventServiceTest does not reach.
 *
 * @covers \OCA\Integriq\Service\EventService
 */
class EventServiceDeliverMessageTest extends TestCase {

	/**
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * @var MockObject
	 */
	private $objectService;

	/**
	 * @var WebhookSignatureService&MockObject
	 */
	private $signatureService;

	/**
	 * @var IClientService&MockObject
	 */
	private $clientService;

	/**
	 * The posts sent, as [url, options].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $posts = [];

	/**
	 * The message bodies saved.
	 *
	 * @var array<int, array>
	 */
	private array $saved = [];

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
			}
		);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
		$this->clientService = $this->createMock(IClientService::class);

		$this->service = new EventService(
			$this->objectService,
			$this->clientService,
			$this->createMock(LoggerInterface::class),
			$this->signatureService,
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
		);
	}//end setUp()

	/**
	 * Wires the subscription the message names and the sink's answer.
	 *
	 * @param array $subscription The subscription body.
	 * @param int   $statusCode   The sink's status code.
	 *
	 * @return ObjectEntity The message to deliver.
	 */
	private function wire(array $subscription, int $statusCode): ObjectEntity {
		$this->objectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, $subscription, 'sub-uuid')
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn('answer');
		$response->method('getHeader')->willReturn('');
		$response->method('getHeaders')->willReturn(['X-Sink' => ['1'], 'Authorization' => ['Bearer leak']]);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response) {
				$this->posts[] = [$url, $options];
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['subscription' => 'sub-uuid', 'payload' => ['type' => 'nl.test', 'data' => ['a' => 1]], 'attempts' => [['at' => 'x', 'statusCode' => 500]]],
			'msg-uuid'
		);
	}//end wire()

	/**
	 * A push carries the JSON payload, the content type, the subscription's headers and a 30 s timeout.
	 *
	 * @return void
	 */
	public function testThePushCarriesThePayloadAndTheSubscriptionsHeaders(): void {
		$message = $this->wire(
			['style' => 'push', 'sink' => 'https://sink.example/hook', 'protocolSettings' => ['headers' => ['X-Tenant' => 't1', 'Content-Type' => 'application/json']]],
			204
		);
		$this->signatureService->expects($this->never())->method('sign');

		$this->assertTrue($this->service->deliverMessage($message));

		$this->assertSame('https://sink.example/hook', $this->posts[0][0]);
		$this->assertSame(
			[
				'body' => '{"type":"nl.test","data":{"a":1}}',
				'headers' => ['Content-Type' => 'application/json', 'X-Tenant' => 't1'],
				'timeout' => 30,
			],
			$this->posts[0][1]
		);
	}//end testThePushCarriesThePayloadAndTheSubscriptionsHeaders()

	/**
	 * A delivered message is saved with its response, timestamps and one more attempt.
	 *
	 * @return void
	 */
	public function testADeliveredMessageIsSavedWithItsResponseAndAttempt(): void {
		$message = $this->wire(['style' => 'push', 'sink' => 'https://sink.example/hook'], 200);

		$this->service->deliverMessage($message);

		$saved = $this->saved[0];
		$this->assertSame('delivered', $saved['status']);
		$this->assertSame($saved['deliveredAt'], $saved['lastAttempt']);
		$this->assertNotFalse(strtotime($saved['deliveredAt']));
		$this->assertArrayHasKey('nextAttempt', $saved);
		$this->assertNull($saved['nextAttempt']);
		$this->assertSame(['statusCode' => 200, 'body' => 'answer'], $saved['deliveryResponse']);
		$this->assertCount(2, $saved['attempts']);
		$this->assertSame(200, $saved['attempts'][1]['statusCode']);
		$this->assertSame(false, $saved['attempts'][1]['signed']);
		$this->assertSame(['type' => 'nl.test', 'data' => ['a' => 1]], $saved['payload']);
	}//end testADeliveredMessageIsSavedWithItsResponseAndAttempt()

	/**
	 * Within the rotation grace the previous secret signs too; the event id header names the message.
	 *
	 * @return void
	 */
	public function testWithinTheRotationGraceThePreviousSecretSignsToo(): void {
		$message = $this->wire(
			[
				'style' => 'push',
				'sink' => 'https://sink.example/hook',
				'protocolSettings' => ['signingSecret' => 'new', 'previousSigningSecret' => 'old', 'secretRotatedAt' => '2026-10-29T10:00:00+00:00'],
			],
			200
		);
		$this->signatureService->expects($this->once())->method('isRotationGraceActive')->with('2026-10-29T10:00:00+00:00')->willReturn(true);
		$this->signatureService->expects($this->once())->method('sign')
			->with('{"type":"nl.test","data":{"a":1}}', 'new', 'old')
			->willReturn('t=1,v1=abc');

		$this->service->deliverMessage($message);

		$this->assertSame('t=1,v1=abc', $this->posts[0][1]['headers']['X-OpenConnector-Signature']);
		$this->assertSame('msg-uuid', $this->posts[0][1]['headers']['X-OpenConnector-Event-Id']);
		$this->assertSame(true, $this->saved[0]['attempts'][1]['signed']);
	}//end testWithinTheRotationGraceThePreviousSecretSignsToo()

	/**
	 * Outside the rotation grace only the current secret signs.
	 *
	 * @return void
	 */
	public function testOutsideTheRotationGraceOnlyTheCurrentSecretSigns(): void {
		$message = $this->wire(
			['style' => 'push', 'sink' => 'https://sink.example/hook', 'protocolSettings' => ['signingSecret' => 'new', 'previousSigningSecret' => 'old']],
			200
		);
		$this->signatureService->method('isRotationGraceActive')->with(null)->willReturn(false);
		$this->signatureService->expects($this->once())->method('sign')->with($this->anything(), 'new', null)->willReturn('sig');

		$this->service->deliverMessage($message);
		$this->assertSame('sig', $this->posts[0][1]['headers']['X-OpenConnector-Signature']);
	}//end testOutsideTheRotationGraceOnlyTheCurrentSecretSigns()

	/**
	 * An empty secret, or an `unsigned` decision next to a secret, sends unsigned.
	 *
	 * @return void
	 */
	public function testAnEmptySecretOrAnUnsignedDecisionSendsUnsigned(): void {
		$this->signatureService->expects($this->never())->method('sign');
		$message = $this->wire(
			['style' => 'push', 'sink' => 'https://sink.example/hook', 'protocolSettings' => ['signingSecret' => 'kept', 'unsigned' => ['reason' => 'legacy sink']]],
			200
		);

		$this->service->deliverMessage($message);

		$this->assertArrayNotHasKey('X-OpenConnector-Signature', $this->posts[0][1]['headers']);
		$this->assertArrayNotHasKey('X-OpenConnector-Event-Id', $this->posts[0][1]['headers']);
	}//end testAnEmptySecretOrAnUnsignedDecisionSendsUnsigned()

	/**
	 * With a trace, a 2xx records a redacted `success` call step named after the subscription.
	 *
	 * @return void
	 */
	public function testATracedDeliveryRecordsARedactedCallStep(): void {
		$message = $this->wire(
			['name' => 'Case hook', 'style' => 'push', 'sink' => 'https://sink.example/hook', 'protocolSettings' => ['headers' => ['Authorization' => 'Bearer s3cret']]],
			201
		);
		$trace = new ExecutionTraceContext(entryPoint: 'event', entryPointId: 'ev-1');

		$this->service->deliverMessage($message, $trace);

		$steps = $trace->getSteps();
		$this->assertCount(1, $steps);
		$this->assertSame('call', $steps[0]['type']);
		$this->assertSame('Case hook', $steps[0]['name']);
		$this->assertNull($steps[0]['timing']);
		$this->assertSame('success', $steps[0]['status']);
		$this->assertSame('https://sink.example/hook', $steps[0]['input']['url']);
		$this->assertSame('POST', $steps[0]['input']['method']);
		$this->assertSame('application/cloudevents+json', $steps[0]['input']['headers']['Content-Type']);
		$this->assertNotSame('Bearer s3cret', $steps[0]['input']['headers']['Authorization']);
		$this->assertSame(201, $steps[0]['output']['statusCode']);
		$this->assertSame(['1'], $steps[0]['output']['headers']['X-Sink']);
	}//end testATracedDeliveryRecordsARedactedCallStep()

	/**
	 * With a trace, a non-2xx records an `error` step named after the sink, and a 1xx counts as an error.
	 *
	 * @return void
	 */
	public function testATracedFailureRecordsAnErrorStepNamedAfterTheSink(): void {
		$message = $this->wire(['style' => 'push', 'sink' => 'https://sink.example/hook'], 199);
		$trace = new ExecutionTraceContext(entryPoint: 'event', entryPointId: 'ev-2');

		$this->assertFalse($this->service->deliverMessage($message, $trace));

		$steps = $trace->getSteps();
		$this->assertSame('error', $steps[0]['status']);
		$this->assertSame('https://sink.example/hook', $steps[0]['name']);
		$this->assertSame(199, $steps[0]['output']['statusCode']);
		$this->assertSame('failed', $this->saved[0]['status']);
	}//end testATracedFailureRecordsAnErrorStepNamedAfterTheSink()

	/**
	 * A 300 is a failure, not a delivery.
	 *
	 * @return void
	 */
	public function testA300IsAFailure(): void {
		$message = $this->wire(['style' => 'push', 'sink' => 'https://sink.example/hook'], 300);

		$this->assertFalse($this->service->deliverMessage($message));
		$this->assertSame('failed', $this->saved[0]['status']);
	}//end testA300IsAFailure()

	/**
	 * A pull subscription is not pushed to and nothing is saved.
	 *
	 * @return void
	 */
	public function testAPullSubscriptionIsNotPushed(): void {
		$message = $this->wire(['style' => 'pull', 'sink' => 'https://sink.example/hook'], 200);

		$this->assertFalse($this->service->deliverMessage($message));
		$this->assertSame([], $this->posts);
		$this->assertSame([], $this->saved);
	}//end testAPullSubscriptionIsNotPushed()

	/**
	 * A transport failure after signing records the attempt as signed, with the subscription's retry policy.
	 *
	 * @return void
	 */
	public function testATransportFailureKeepsTheSignedFlagAndRetryPolicy(): void {
		$this->objectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['style' => 'push', 'sink' => 'https://sink.example/hook', 'retryPolicy' => ['maxRetries' => 1], 'protocolSettings' => ['signingSecret' => 's']],
				'sub-uuid'
			)
		);
		$this->signatureService->method('sign')->willReturn('sig');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('connection refused'));
		$this->clientService->method('newClient')->willReturn($client);
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-uuid', 'payload' => [], 'retryCount' => 0], 'msg-uuid');

		$this->assertFalse($this->service->deliverMessage($message));

		$saved = $this->saved[0];
		$this->assertSame('connection refused', $saved['attempts'][0]['error']);
		$this->assertSame(true, $saved['attempts'][0]['signed']);
		$this->assertSame('abandoned', $saved['status']);
	}//end testATransportFailureKeepsTheSignedFlagAndRetryPolicy()

	/**
	 * A failing subscription lookup is recorded as a failed attempt with no signed flag.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsRecordedWithoutASignedFlag(): void {
		$this->objectService->method('find')->willThrowException(new \Exception('register down'));
		$message = ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-uuid', 'retryCount' => 0], 'msg-uuid');

		$this->assertFalse($this->service->deliverMessage($message));

		$saved = $this->saved[0];
		$this->assertSame('register down', $saved['attempts'][0]['error']);
		$this->assertArrayNotHasKey('signed', $saved['attempts'][0]);
		$this->assertSame('failed', $saved['status']);
	}//end testAFailingLookupIsRecordedWithoutASignedFlag()
}//end class
