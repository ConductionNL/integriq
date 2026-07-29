<?php
/**
 * Unit tests for NextcloudFileEventListener.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\OpenConnector\EventListener\NextcloudFileEventListener;
use OCA\OpenConnector\Service\EventService;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class NextcloudFileEventListenerTest extends TestCase
{


    /**
     * Build a mock Node with the given id/path/mimetype/owner.
     *
     * @param integer $id       The file id.
     * @param string  $path     The file path.
     * @param string  $mimetype The file mimetype.
     * @param string  $ownerUid The owner's uid, or '' for no owner.
     *
     * @return Node|\PHPUnit\Framework\MockObject\MockObject
     */
    private function node(int $id, string $path, string $mimetype, string $ownerUid='alice')
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn($path);
        $node->method('getMimetype')->willReturn($mimetype);

        if ($ownerUid === '') {
            $node->method('getOwner')->willReturn(null);
        } else {
            $owner = $this->createMock(IUser::class);
            $owner->method('getUID')->willReturn($ownerUid);
            $node->method('getOwner')->willReturn($owner);
        }

        return $node;

    }//end node()


    /**
     * TC-1: a NodeCreatedEvent produces a `com.nextcloud.files.node.created`
     * CloudEvent with the expected source/subject/data.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testNodeCreatedEventProducesMatchingCloudEvent(): void
    {
        $node = $this->node(42, '/foo.pdf', 'application/pdf');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleNextcloudEvent')
            ->with(
                'com.nextcloud.files.node.created',
                $this->callback(function (array $payload) {
                    return $payload['source'] === '/nextcloud/files'
                        && $payload['subject'] === '42'
                        && $payload['data']['fileid'] === 42
                        && $payload['data']['path'] === '/foo.pdf'
                        && $payload['data']['mimetype'] === 'application/pdf'
                        && $payload['data']['owner'] === 'alice'
                        && $payload['userId'] === 'alice';
                })
            );

        $listener = new NextcloudFileEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeCreatedEvent($node));
    }//end testNodeCreatedEventProducesMatchingCloudEvent()


    /**
     * A NodeWrittenEvent produces `com.nextcloud.files.node.updated`.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testNodeWrittenEventProducesUpdatedType(): void
    {
        $node = $this->node(43, '/bar.pdf', 'application/pdf');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleNextcloudEvent')
            ->with('com.nextcloud.files.node.updated', $this->anything());

        $listener = new NextcloudFileEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeWrittenEvent($node));
    }//end testNodeWrittenEventProducesUpdatedType()


    /**
     * A NodeDeletedEvent produces `com.nextcloud.files.node.deleted`.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testNodeDeletedEventProducesDeletedType(): void
    {
        $node = $this->node(44, '/baz.pdf', 'application/pdf', '');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleNextcloudEvent')
            ->with(
                'com.nextcloud.files.node.deleted',
                $this->callback(fn(array $payload) => $payload['data']['owner'] === null)
            );

        $listener = new NextcloudFileEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeDeletedEvent($node));
    }//end testNodeDeletedEventProducesDeletedType()


    /**
     * The firehose gate: zero active subscriptions means zero persistence
     * cost, mirroring CloudEventListener's own guard.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testSkipsWhenNoActiveSubscriptions(): void
    {
        $node = $this->node(45, '/qux.pdf', 'application/pdf');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(false);
        $eventService->expects($this->never())->method('handleNextcloudEvent');

        $listener = new NextcloudFileEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeCreatedEvent($node));
    }//end testSkipsWhenNoActiveSubscriptions()


    /**
     * An exception thrown while processing the event is caught and logged,
     * never propagated into the NC event dispatcher (matches
     * CloudEventListener's broad-catch posture).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testExceptionIsCaughtAndLogged(): void
    {
        $node = $this->node(46, '/thrower.pdf', 'application/pdf');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $listener = new NextcloudFileEventListener($eventService, $logger);
        $listener->handle(new NodeCreatedEvent($node));

        // No exception propagated — reaching this line proves the catch worked.
        $this->addToAssertionCount(1);
    }//end testExceptionIsCaughtAndLogged()
}//end class
