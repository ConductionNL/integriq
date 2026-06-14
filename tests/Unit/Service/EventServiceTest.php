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

use OCA\OpenConnector\Service\EventService;
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->clientService = $this->createMock(IClientService::class);

        $this->service = new EventService(
            $this->objectService,
            $this->clientService,
            $this->logger,
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


}//end class
