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
use OCP\Http\Client\IClientService;
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


}//end class
