<?php
/**
 * Unit tests for EventService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the event processing and delivery service (OR cutover — no deleted Db types).
 */
class EventServiceTest extends TestCase
{

    /**
     * @var EventService
     */
    private EventService $service;

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $clientService;

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;

    /**
     * @var JobService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $jobService;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var FlowRunnerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $flowRunnerService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService          = ObjectServiceMockBuilder::make($this);
        $this->logger                 = $this->createMock(LoggerInterface::class);
        $this->clientService          = $this->createMock(IClientService::class);
        $this->synchronizationService = $this->createMock(SynchronizationService::class);
        $this->jobService             = $this->createMock(JobService::class);
        $this->callService            = $this->createMock(CallService::class);
        $this->flowRunnerService      = $this->createMock(FlowRunnerService::class);

        $this->service = new EventService(
            $this->objectService,
            $this->clientService,
            $this->logger,
            new WebhookSignatureService($this->logger),
            $this->synchronizationService,
            $this->jobService,
            $this->callService,
            $this->flowRunnerService,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates EventService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(EventService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * `attemptDelivery()` dispatches `action.kind = 'flow'` to
     * `FlowRunnerService::run(..., triggerSource: 'event')` and returns
     * true when the resulting flow_run's status is not `failed`/`stopped`
     * — flow-orchestration REQ-007c (event-triggered flow) / TC-16-adjacent
     * coverage for the `event_subscription` `action.kind` extension point.
     *
     * @return void
     */
    public function testAttemptDeliveryDispatchesFlowActionOnSuccess(): void
    {
        $flow    = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Event flow'], 'flow-1');
        $flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'completed'], 'flow-run-1');

        $this->flowRunnerService->method('findFlow')->with('flow-1')->willReturn($flow);
        $this->flowRunnerService->expects($this->once())
            ->method('run')
            ->with($this->identicalTo($flow), [], 'event')
            ->willReturn($flowRun);

        $message = ObjectServiceMockBuilder::objectEntity($this, ['payload' => []], 'message-1');
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['action' => ['kind' => 'flow', 'flowId' => 'flow-1']],
            'sub-1'
        );

        $method = new \ReflectionMethod(EventService::class, 'attemptDelivery');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $message, $subscription);

        $this->assertTrue($result);
    }//end testAttemptDeliveryDispatchesFlowActionOnSuccess()


    /**
     * A `stopped` flow run is recorded as a delivery failure — subject to
     * the same retry/dead-letter machinery as any other action kind.
     *
     * @return void
     */
    public function testAttemptDeliveryDispatchesFlowActionOnFailure(): void
    {
        $flow    = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Event flow'], 'flow-1');
        $flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'stopped'], 'flow-run-1');

        $this->flowRunnerService->method('findFlow')->willReturn($flow);
        $this->flowRunnerService->method('run')->willReturn($flowRun);

        $message = ObjectServiceMockBuilder::objectEntity($this, ['payload' => []], 'message-1');
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['action' => ['kind' => 'flow', 'flowId' => 'flow-1']],
            'sub-1'
        );

        $method = new \ReflectionMethod(EventService::class, 'attemptDelivery');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $message, $subscription);

        $this->assertFalse($result);
    }//end testAttemptDeliveryDispatchesFlowActionOnFailure()


    /**
     * hasActiveSubscriptions() returns false when the register has zero
     * active event_subscription objects — the CloudEventListener firehose
     * gate's negative case.
     *
     * @return void
     */
    public function testHasActiveSubscriptionsReturnsFalseWhenNone(): void
    {
        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $this->assertFalse($this->service->hasActiveSubscriptions());
    }//end testHasActiveSubscriptionsReturnsFalseWhenNone()


    /**
     * hasActiveSubscriptions() returns true when at least one active
     * event_subscription exists.
     *
     * @return void
     */
    public function testHasActiveSubscriptionsReturnsTrueWhenAtLeastOneActive(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'active'], 'sub-uuid');
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);

        $this->assertTrue($this->service->hasActiveSubscriptions());
    }//end testHasActiveSubscriptionsReturnsTrueWhenAtLeastOneActive()


    /**
     * Test that processEvent returns an empty array when there are no matching subscriptions.
     *
     * @return void
     */
    public function testProcessEventReturnsEmptyArrayWhenNoSubscriptions(): void
    {
        // Arrange — no active subscriptions
        $this->objectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        $eventEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['type' => 'com.example.created', 'source' => '/test'],
            'event-uuid-1'
        );

        // Act
        $messages = $this->service->processEvent($eventEntity);

        // Assert
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }//end testProcessEventReturnsEmptyArrayWhenNoSubscriptions()


    /**
     * Test that deliverMessage returns false when no subscriptionId is set.
     *
     * @return void
     */
    public function testDeliverMessageReturnsFalseWhenNoSubscriptionId(): void
    {
        // Arrange — message without subscriptionId
        $messageEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['payload' => ['key' => 'value']],
            'message-uuid-1'
        );

        // objectService::find should NOT be called since subscriptionId is null
        $this->objectService->expects($this->never())->method('find');

        // Act
        $result = $this->service->deliverMessage($messageEntity);

        // Assert
        $this->assertFalse($result);
    }//end testDeliverMessageReturnsFalseWhenNoSubscriptionId()


    /**
     * Test that deliverMessage returns false when the subscription is not found (find returns null).
     *
     * @return void
     */
    public function testDeliverMessageReturnsFalseWhenSubscriptionNotFound(): void
    {
        // Arrange — message with subscriptionId but subscription does not exist
        $messageEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['subscriptionId' => 'missing-sub-uuid'],
            'message-uuid-2'
        );

        $this->objectService->method('find')->willReturn(null);

        // Act
        $result = $this->service->deliverMessage($messageEntity);

        // Assert
        $this->assertFalse($result);
    }//end testDeliverMessageReturnsFalseWhenSubscriptionNotFound()


    /**
     * Test that processRetries returns 0 when there are no pending messages.
     *
     * @return void
     */
    public function testProcessRetriesReturnsZeroWhenNoPendingMessages(): void
    {
        // Arrange
        $this->objectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Act
        $count = $this->service->processRetries();

        // Assert
        $this->assertSame(0, $count);
    }//end testProcessRetriesReturnsZeroWhenNoPendingMessages()


    /**
     * REQ-WHS-001: a subscription with a signingSecret produces a verifiable
     * X-OpenConnector-Signature over the exact body bytes.
     *
     * @return void
     */
    public function testDeliverMessageSignsWhenSecretConfigured(): void
    {
        $secret  = 'whsec_testsecret';
        $payload = ['type' => 'com.example.created', 'id' => 'abc'];

        $message = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['subscriptionId' => 'sub-uuid', 'payload' => $payload],
            'msg-uuid'
        );
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'style'            => 'push',
                'sink'             => 'https://sink.example/hook',
                'protocolSettings' => ['signingSecret' => $secret],
            ],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);
        $this->objectService->method('saveObject')->willReturn($message);

        $capturedHeaders = null;
        $capturedBody    = null;
        $response        = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('ok');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $url, array $opts) use (&$capturedHeaders, &$capturedBody, $response) {
                $capturedHeaders = $opts['headers'];
                $capturedBody    = $opts['body'];
                return $response;
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $this->service->deliverMessage($message);

        $this->assertArrayHasKey('X-OpenConnector-Signature', $capturedHeaders);
        $this->assertArrayHasKey('X-OpenConnector-Event-Id', $capturedHeaders);

        // Recompute the signature against the exact body bytes that were sent.
        $header = $capturedHeaders['X-OpenConnector-Signature'];
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);

        preg_match('/t=(\d+),v1=([0-9a-f]+)/', $header, $m);
        $expected = hash_hmac('sha256', $m[1].'.'.$capturedBody, $secret);
        $this->assertSame($expected, $m[2]);
    }//end testDeliverMessageSignsWhenSecretConfigured()


    /**
     * REQ-WHS-001 fail-open guard: a signing failure MUST NOT result in an
     * unsigned delivery. `sign()` throwing (e.g. a future crypto failure)
     * must abort before the HTTP POST — the client is never invoked — and
     * the message is recorded as a failed delivery attempt, not silently
     * sent unsigned.
     *
     * @return void
     */
    public function testDeliverMessageNeverSendsUnsignedWhenSigningFails(): void
    {
        $message = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['subscriptionId' => 'sub-uuid', 'payload' => ['a' => 1]],
            'msg-uuid'
        );
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'style'            => 'push',
                'sink'             => 'https://sink.example/hook',
                'protocolSettings' => ['signingSecret' => 'whsec_broken'],
            ],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured, $message) {
                $captured = $object;
                return $message;
            }
        );

        $signatureService = $this->createMock(WebhookSignatureService::class);
        $signatureService->method('isRotationGraceActive')->willReturn(false);
        $signatureService->method('sign')->willThrowException(new \RuntimeException('signing failed'));

        $service = new EventService(
            $this->objectService,
            $this->clientService,
            $this->logger,
            $signatureService,
            $this->synchronizationService,
            $this->jobService,
            $this->callService,
            $this->flowRunnerService,
        );

        // The HTTP client must never be asked for — no unsigned bytes leave
        // the process.
        $this->clientService->expects($this->never())->method('newClient');

        $result = $service->deliverMessage($message);

        $this->assertFalse($result);
        $this->assertSame('failed', $captured['status']);
        $this->assertSame(1, $captured['retryCount']);
    }//end testDeliverMessageNeverSendsUnsignedWhenSigningFails()


    /**
     * REQ-WHS-001: a subscription without a signingSecret delivers unsigned.
     *
     * @return void
     */
    public function testDeliverMessageUnsignedWhenNoSecret(): void
    {
        $message = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['subscriptionId' => 'sub-uuid', 'payload' => ['a' => 1]],
            'msg-uuid'
        );
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);
        $this->objectService->method('saveObject')->willReturn($message);

        $capturedHeaders = null;
        $response        = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('ok');
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $url, array $opts) use (&$capturedHeaders, $response) {
                $capturedHeaders = $opts['headers'];
                return $response;
            }
        );
        $this->clientService->method('newClient')->willReturn($client);

        $this->service->deliverMessage($message);

        $this->assertArrayNotHasKey('X-OpenConnector-Signature', $capturedHeaders);
    }//end testDeliverMessageUnsignedWhenNoSecret()


    /**
     * Configure the HTTP client mock to return a response with the given status,
     * body and optional Retry-After header on the next post() call.
     *
     * @param integer $statusCode  HTTP status code to return.
     * @param string  $body        Response body.
     * @param string  $retryAfter  Retry-After header value (empty when absent).
     *
     * @return void
     */
    private function stubHttpResponse(int $statusCode, string $body='ok', string $retryAfter=''): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($body);
        $response->method('getHeader')->willReturnCallback(
            static function (string $key) use ($retryAfter): string {
                if (strtolower($key) === 'retry-after') {
                    return $retryAfter;
                }

                return '';
            }
        );

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);

        $this->clientService->method('newClient')->willReturn($client);
    }//end stubHttpResponse()


    /**
     * Configure the HTTP client mock so that post() throws (transport failure).
     *
     * @param string $message The exception message.
     *
     * @return void
     */
    private function stubHttpThrows(string $message='connection timeout'): void
    {
        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception($message));
        $this->clientService->method('newClient')->willReturn($client);
    }//end stubHttpThrows()


    /**
     * Wire objectService->find to return a push subscription and capture the
     * single saveObject payload into $captured by reference.
     *
     * @param array      $messageBody The message body for the entity under test.
     * @param array|null $captured    Receives the saved object array by reference.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity The message entity.
     */
    private function pushMessage(array $messageBody, ?array &$captured)
    {
        $message = ObjectServiceMockBuilder::objectEntity($this, $messageBody, 'msg-uuid');

        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured, $message) {
                $captured = $object;
                return $message;
            }
        );

        return $message;
    }//end pushMessage()


    /**
     * REQ-002 success path: a 2xx delivery marks the message delivered, sets
     * nextAttempt=null, and appends a success attempt entry.
     *
     * @return void
     */
    public function testDeliverMessageSuccessMarksDelivered(): void
    {
        $this->stubHttpResponse(200, 'pong');
        $captured = null;
        $message  = $this->pushMessage(['subscriptionId' => 'sub-uuid', 'payload' => ['a' => 1]], $captured);

        $result = $this->service->deliverMessage($message);

        $this->assertTrue($result);
        $this->assertSame('delivered', $captured['status']);
        $this->assertNull($captured['nextAttempt']);
        $this->assertSame(200, $captured['deliveryResponse']['statusCode']);
        $this->assertCount(1, $captured['attempts']);
        $this->assertSame(200, $captured['attempts'][0]['statusCode']);
        $this->assertNull($captured['attempts'][0]['error']);
    }//end testDeliverMessageSuccessMarksDelivered()


    /**
     * REQ-002 failure path: a non-2xx response increments retryCount, sets
     * status=failed, schedules a ~60s backoff, and appends an attempt entry.
     *
     * @return void
     */
    public function testDeliverMessageFailureSchedulesBackoff(): void
    {
        $this->stubHttpResponse(500, 'boom');
        $captured = null;
        $message  = $this->pushMessage(['subscriptionId' => 'sub-uuid', 'retryCount' => 0], $captured);

        $result = $this->service->deliverMessage($message);

        $this->assertFalse($result);
        $this->assertSame('failed', $captured['status']);
        $this->assertSame(1, $captured['retryCount']);
        $this->assertNotEmpty($captured['lastAttempt']);

        $last = strtotime($captured['lastAttempt']);
        $next = strtotime($captured['nextAttempt']);
        // 60s * 4^(1-1) = 60s.
        $this->assertEqualsWithDelta(60, ($next - $last), 5);

        $this->assertCount(1, $captured['attempts']);
        $this->assertSame(500, $captured['attempts'][0]['statusCode']);
    }//end testDeliverMessageFailureSchedulesBackoff()


    /**
     * REQ-002: Retry-After delays the next attempt beyond the backoff step.
     *
     * @return void
     */
    public function testDeliverMessageRetryAfterWins(): void
    {
        $this->stubHttpResponse(429, 'slow down', '600');
        $captured = null;
        $message  = $this->pushMessage(['subscriptionId' => 'sub-uuid', 'retryCount' => 0], $captured);

        $this->service->deliverMessage($message);

        $last = strtotime($captured['lastAttempt']);
        $next = strtotime($captured['nextAttempt']);
        // 600s header beats the 60s backoff step.
        $this->assertEqualsWithDelta(600, ($next - $last), 5);
    }//end testDeliverMessageRetryAfterWins()


    /**
     * REQ-002 terminal transition: the final failed attempt abandons the
     * message with nextAttempt=null.
     *
     * @return void
     */
    public function testDeliverMessageFinalAttemptAbandons(): void
    {
        $this->stubHttpResponse(503, 'down');
        $captured = null;
        // retryCount 4 -> incremented to 5 == maxRetries default.
        $message = $this->pushMessage(['subscriptionId' => 'sub-uuid', 'retryCount' => 4], $captured);

        $result = $this->service->deliverMessage($message);

        $this->assertFalse($result);
        $this->assertSame('abandoned', $captured['status']);
        $this->assertSame(5, $captured['retryCount']);
        $this->assertNull($captured['nextAttempt']);
    }//end testDeliverMessageFinalAttemptAbandons()


    /**
     * REQ-006: a transport exception appends an attempt entry with a non-null
     * error and a null statusCode.
     *
     * @return void
     */
    public function testDeliverMessageExceptionRecordsErrorAttempt(): void
    {
        $this->stubHttpThrows('dns failure');
        $captured = null;
        $message  = $this->pushMessage(['subscriptionId' => 'sub-uuid', 'retryCount' => 0], $captured);

        $result = $this->service->deliverMessage($message);

        $this->assertFalse($result);
        $this->assertSame('failed', $captured['status']);
        $this->assertCount(1, $captured['attempts']);
        $this->assertNull($captured['attempts'][0]['statusCode']);
        $this->assertNotNull($captured['attempts'][0]['error']);
    }//end testDeliverMessageExceptionRecordsErrorAttempt()


    /**
     * REQ-002 sweep: processRetries selects due failed messages and skips
     * not-yet-due ones, terminal ones, and over-cap ones.
     *
     * @return void
     */
    public function testProcessRetriesSelectionMatrix(): void
    {
        $past   = (new \DateTime('-1 hour'))->format('c');
        $future = (new \DateTime('+1 hour'))->format('c');

        $rows = [
            // due failed — eligible.
            ['status' => 'failed', 'retryCount' => 2, 'nextAttempt' => $past, 'subscriptionId' => 'sub-uuid'],
            // not yet due — skipped.
            ['status' => 'failed', 'retryCount' => 1, 'nextAttempt' => $future, 'subscriptionId' => 'sub-uuid'],
            // over cap — skipped.
            ['status' => 'failed', 'retryCount' => 5, 'nextAttempt' => $past, 'subscriptionId' => 'sub-uuid'],
            // terminal — must never be returned by the query, but guard anyway.
            ['status' => 'abandoned', 'retryCount' => 5, 'nextAttempt' => null, 'subscriptionId' => 'sub-uuid'],
            ['status' => 'delivered', 'retryCount' => 1, 'nextAttempt' => null, 'subscriptionId' => 'sub-uuid'],
        ];

        $entities = [];
        foreach ($rows as $idx => $row) {
            $entities[] = ObjectServiceMockBuilder::objectEntity($this, $row, 'm-'.$idx);
        }

        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );

        $this->objectService->method('findAll')->willReturn(['results' => $entities, 'total' => count($entities)]);
        $this->objectService->method('find')->willReturn($subscription);

        // Count how many times delivery (saveObject) is reached.
        $delivered = 0;
        $this->stubHttpResponse(200, 'ok');
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$delivered, $entities) {
                $delivered++;
                return $entities[0];
            }
        );

        $count = $this->service->processRetries(5);

        // Only the single due, under-cap, non-terminal failed message is delivered.
        $this->assertSame(1, $count);
        $this->assertSame(1, $delivered);
    }//end testProcessRetriesSelectionMatrix()


    /**
     * REQ-DLR-003: replaying an abandoned message resets it to pending, stamps
     * the operator, preserves attempts[], and re-enters delivery.
     *
     * @return void
     */
    public function testReplayMessageResetsAndStamps(): void
    {
        $captured = null;

        $abandoned = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'         => 'abandoned',
                'retryCount'     => 5,
                'subscriptionId' => 'sub-uuid',
                'attempts'       => [['at' => 'x', 'statusCode' => 503, 'error' => null]],
            ],
            'msg-uuid'
        );

        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );

        // find() is called: (1) replayMessage load, then deliverMessage->find
        // subscription, then final re-load. Return the abandoned message first,
        // subscription on the style lookup, then the (reset) message.
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($abandoned, $subscription) {
                if ($schema === 'event_subscription') {
                    return $subscription;
                }

                return $abandoned;
            }
        );

        $this->stubHttpResponse(200, 'ok');
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured, $abandoned) {
                // Capture the reset (first) save; ignore the delivery save.
                if ($captured === null) {
                    $captured = $object;
                }

                return $abandoned;
            }
        );

        $this->service->replayMessage('msg-uuid', 'alice');

        $this->assertSame('pending', $captured['status']);
        $this->assertSame(0, $captured['retryCount']);
        $this->assertSame('alice', $captured['replayedBy']);
        $this->assertNotEmpty($captured['replayedAt']);
        // attempts[] preserved.
        $this->assertCount(1, $captured['attempts']);
    }//end testReplayMessageResetsAndStamps()


    /**
     * REQ-DLR-003: replaying a delivered message is rejected with a state error.
     *
     * @return void
     */
    public function testReplayMessageRejectsDeliveredState(): void
    {
        $delivered = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'delivered', 'subscriptionId' => 'sub-uuid'],
            'msg-uuid'
        );
        $this->objectService->method('find')->willReturn($delivered);

        $this->expectException(\OCA\OpenConnector\Exception\InvalidMessageStateException::class);
        $this->service->replayMessage('msg-uuid', 'alice');
    }//end testReplayMessageRejectsDeliveredState()


    /**
     * REQ-DLR-004: discard sets the terminal discarded state with an audit
     * stamp and nextAttempt=null.
     *
     * @return void
     */
    public function testDiscardMessageMarksDiscarded(): void
    {
        $captured = null;

        $abandoned = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'abandoned', 'subscriptionId' => 'sub-uuid'],
            'msg-uuid'
        );
        $this->objectService->method('find')->willReturn($abandoned);
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured, $abandoned) {
                $captured = $object;
                return $abandoned;
            }
        );

        $this->service->discardMessage('msg-uuid', 'bob');

        $this->assertSame('discarded', $captured['status']);
        $this->assertNull($captured['nextAttempt']);
        $this->assertSame('bob', $captured['discardedBy']);
        $this->assertNotEmpty($captured['discardedAt']);
    }//end testDiscardMessageMarksDiscarded()


    /**
     * REQ-DLR-004: discard on a pending message is rejected.
     *
     * @return void
     */
    public function testDiscardMessageRejectsPendingState(): void
    {
        $pending = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'pending', 'subscriptionId' => 'sub-uuid'],
            'msg-uuid'
        );
        $this->objectService->method('find')->willReturn($pending);

        $this->expectException(\OCA\OpenConnector\Exception\InvalidMessageStateException::class);
        $this->service->discardMessage('msg-uuid', 'bob');
    }//end testDiscardMessageRejectsPendingState()


    /**
     * emitCloudEvent persists an `event` object carrying the given type/source/subject/data
     * and fans it out via processEvent — @spec peppol-access-point-connector REQ-004.
     *
     * @return void
     */
    public function testEmitCloudEventPersistsAndProcesses(): void
    {
        $captured = null;
        $eventEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['type' => 'nl.conduction.peppol.delivery.status', 'source' => '/peppol/transmissions/tx-1'],
            'event-uuid-1'
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured, $eventEntity) {
                $captured = $object;
                return $eventEntity;
            }
        );
        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $messages = $this->service->emitCloudEvent(
            'nl.conduction.peppol.delivery.status',
            '/peppol/transmissions/tx-1',
            'tx-1',
            ['transmissionId' => 'AP-TX-123', 'status' => 'sent']
        );

        $this->assertIsArray($messages);
        $this->assertSame('nl.conduction.peppol.delivery.status', $captured['type']);
        $this->assertSame('/peppol/transmissions/tx-1', $captured['source']);
        $this->assertSame('tx-1', $captured['subject']);
        $this->assertSame('AP-TX-123', $captured['data']['transmissionId']);
    }//end testEmitCloudEventPersistsAndProcesses()
}//end class
