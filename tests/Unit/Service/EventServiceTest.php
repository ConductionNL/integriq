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
            new WebhookSignatureService($this->logger),
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


}//end class
