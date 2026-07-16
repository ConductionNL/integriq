<?php
/**
 * Unit tests for EventService's nextcloud-event-hub additions:
 * `handleNextcloudEvent`, the `action.kind` dispatch (webhook/synchronization/
 * job), and per-subscription `retryPolicy` overrides.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
 * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
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
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class EventServiceNextcloudEventTest extends TestCase
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService          = ObjectServiceMockBuilder::make($this);
        $logger                       = $this->createMock(LoggerInterface::class);
        $this->clientService          = $this->createMock(IClientService::class);
        $this->synchronizationService = $this->createMock(SynchronizationService::class);
        $this->jobService             = $this->createMock(JobService::class);

        $this->service = new EventService(
            $this->objectService,
            $this->clientService,
            $logger,
            new WebhookSignatureService($logger),
            $this->synchronizationService,
            $this->jobService,
            $this->createMock(CallService::class),
            $this->createMock(FlowRunnerService::class),
        );
    }//end setUp()


    /**
     * REQ-001: handleNextcloudEvent persists an `event` OR-object with the
     * expected CloudEvents shape and fans it out via processEvent.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
     */
    public function testHandleNextcloudEventPersistsAndProcesses(): void
    {
        $captured    = null;
        $eventEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['type' => 'com.nextcloud.files.node.created', 'source' => '/nextcloud/files'],
            'event-uuid-1'
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured, $eventEntity) {
                if ($captured === null) {
                    $captured = $object;
                }

                return $eventEntity;
            }
        );
        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $messages = $this->service->handleNextcloudEvent(
            'com.nextcloud.files.node.created',
            [
                'source'  => '/nextcloud/files',
                'subject' => '42',
                'data'    => ['fileid' => 42, 'path' => '/foo.pdf'],
                'userId'  => 'alice',
            ]
        );

        $this->assertIsArray($messages);
        $this->assertSame('com.nextcloud.files.node.created', $captured['type']);
        $this->assertSame('/nextcloud/files', $captured['source']);
        $this->assertSame('42', $captured['subject']);
        $this->assertSame(42, $captured['data']['fileid']);
        $this->assertSame('alice', $captured['userId']);
    }//end testHandleNextcloudEventPersistsAndProcesses()


    /**
     * TC-15 / REQ-008: a subscription with no `action` field dispatches via
     * `deliverMessage` unchanged (default kind='webhook').
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    public function testDefaultActionDispatchesWebhook(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'active', 'style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
        $this->objectService->method('find')->willReturn($subscription);
        $this->objectService->method('saveObject')->willReturnCallback(
            fn(array $object, ...$rest) => ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid')
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('ok');
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->synchronizationService->expects($this->never())->method('synchronize');
        $this->jobService->expects($this->never())->method('executeJob');

        $event = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'com.example.created'], 'event-uuid');
        $this->service->processEvent($event);
    }//end testDefaultActionDispatchesWebhook()


    /**
     * TC-16 / REQ-008: action.kind=synchronization invokes
     * SynchronizationService::synchronize, not deliverMessage (no HTTP call).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    public function testActionKindSynchronizationRunsSynchronization(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync-1'], 'sync-uuid');
        $subscription    = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status' => 'active',
                'style'  => 'push',
                'action' => ['kind' => 'synchronization', 'synchronizationId' => 'sync-uuid'],
            ],
            'sub-uuid'
        );

        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($synchronization) {
                if ($schema === 'synchronization') {
                    return $synchronization;
                }

                return null;
            }
        );

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $this->synchronizationService->expects($this->once())
            ->method('synchronize')
            ->with($this->identicalTo($synchronization));
        $this->clientService->expects($this->never())->method('newClient');

        $event = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'com.example.created'], 'event-uuid');
        $this->service->processEvent($event);

        $this->assertSame('delivered', $captured['status']);
    }//end testActionKindSynchronizationRunsSynchronization()


    /**
     * TC-17 / REQ-008: a synchronization-action failure enters the standard
     * retry/backoff machine (status=failed, retryCount incremented).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    public function testActionKindSynchronizationFailureEntersRetryMachine(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync-1'], 'sync-uuid');
        $subscription    = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status' => 'active',
                'style'  => 'push',
                'action' => ['kind' => 'synchronization', 'synchronizationId' => 'sync-uuid'],
            ],
            'sub-uuid'
        );

        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($synchronization) {
                if ($schema === 'synchronization') {
                    return $synchronization;
                }

                return null;
            }
        );

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $this->synchronizationService->method('synchronize')->willThrowException(new \RuntimeException('sync failed'));

        $event = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'com.example.created'], 'event-uuid');
        $this->service->processEvent($event);

        $this->assertSame('failed', $captured['status']);
        $this->assertSame(1, $captured['retryCount']);
    }//end testActionKindSynchronizationFailureEntersRetryMachine()


    /**
     * TC-18 / REQ-008: action.kind=job invokes JobService::executeJob
     * (forceRun: true), not deliverMessage.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    public function testActionKindJobRunsJob(): void
    {
        $job          = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'job-1'], 'job-uuid');
        $jobLog       = ObjectServiceMockBuilder::objectEntity($this, ['level' => 'SUCCESS'], 'log-uuid');
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status' => 'active',
                'style'  => 'push',
                'action' => ['kind' => 'job', 'jobId' => 'job-uuid'],
            ],
            'sub-uuid'
        );

        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($job) {
                if ($schema === 'job') {
                    return $job;
                }

                return null;
            }
        );

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $this->jobService->expects($this->once())
            ->method('executeJob')
            ->with($this->identicalTo($job), true)
            ->willReturn($jobLog);
        $this->clientService->expects($this->never())->method('newClient');

        $event = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'com.example.created'], 'event-uuid');
        $this->service->processEvent($event);

        $this->assertSame('delivered', $captured['status']);
    }//end testActionKindJobRunsJob()


    /**
     * TC-19 / REQ-008: an unrecognised action.kind fails once WITHOUT
     * incrementing retryCount (a configuration error, not a transient one).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
     */
    public function testUnrecognisedActionKindFailsOnceWithoutRetryIncrement(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'active', 'style' => 'push', 'action' => ['kind' => 'carrier-pigeon']],
            'sub-uuid'
        );

        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);
        $this->objectService->method('find')->willReturn($subscription);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $this->clientService->expects($this->never())->method('newClient');
        $this->synchronizationService->expects($this->never())->method('synchronize');
        $this->jobService->expects($this->never())->method('executeJob');

        $event = ObjectServiceMockBuilder::objectEntity($this, ['type' => 'com.example.created'], 'event-uuid');
        $this->service->processEvent($event);

        $this->assertSame('failed', $captured['status']);
        $this->assertSame(0, ($captured['retryCount'] ?? 0));
        $this->assertNotEmpty($captured['error']);
    }//end testUnrecognisedActionKindFailsOnceWithoutRetryIncrement()


    /**
     * TC-20 / REQ-002 / REQ-009: a custom retryPolicy overrides the default
     * backoff schedule and abandon threshold.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-push-delivery-with-status-tracking-and-retry-sweep-req-002
     */
    public function testCustomRetryPolicyOverridesBackoffAndAbandonThreshold(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'style'       => 'push',
                'sink'        => 'https://sink.example/hook',
                'retryPolicy' => ['baseSeconds' => 30, 'factor' => 2, 'capSeconds' => 1800, 'maxRetries' => 3],
            ],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getBody')->willReturn('boom');
        $response->method('getHeader')->willReturn('');
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        // retryCount 0 -> 1: nextAttempt should be ~30s (custom baseSeconds), not 60s.
        $message = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 0], 'msg-uuid');
        $this->service->deliverMessage($message);

        $last = strtotime($captured['lastAttempt']);
        $next = strtotime($captured['nextAttempt']);
        $this->assertEqualsWithDelta(30, ($next - $last), 5);
        $this->assertSame('failed', $captured['status']);

        // retryCount 2 -> 3 == custom maxRetries: abandons (not the default 5th).
        $message2 = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 2], 'msg-uuid');
        $this->service->deliverMessage($message2);

        $this->assertSame('abandoned', $captured['status']);
        $this->assertSame(3, $captured['retryCount']);
        $this->assertNull($captured['nextAttempt']);
    }//end testCustomRetryPolicyOverridesBackoffAndAbandonThreshold()


    /**
     * TC-21 / REQ-009: a partial retryPolicy (only maxRetries) only
     * overrides that key — baseSeconds/factor/capSeconds stay default.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    public function testPartialRetryPolicyOnlyOverridesSetKeys(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook', 'retryPolicy' => ['maxRetries' => 8]],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getBody')->willReturn('boom');
        $response->method('getHeader')->willReturn('');
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $message = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 0], 'msg-uuid');
        $this->service->deliverMessage($message);

        // Default baseSeconds=60/factor=4 still apply.
        $last = strtotime($captured['lastAttempt']);
        $next = strtotime($captured['nextAttempt']);
        $this->assertEqualsWithDelta(60, ($next - $last), 5);

        // retryCount 6 -> 7 < custom maxRetries=8: still failed, not abandoned.
        $message2 = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 6], 'msg-uuid');
        $this->service->deliverMessage($message2);
        $this->assertSame('failed', $captured['status']);
        $this->assertSame(7, $captured['retryCount']);

        // retryCount 7 -> 8 == custom maxRetries=8: abandons (not the default 5th).
        $message3 = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 7], 'msg-uuid');
        $this->service->deliverMessage($message3);
        $this->assertSame('abandoned', $captured['status']);
        $this->assertSame(8, $captured['retryCount']);
    }//end testPartialRetryPolicyOnlyOverridesSetKeys()


    /**
     * TC-22 / REQ-009 regression: a subscription without retryPolicy is
     * byte-for-byte unchanged from pre-change behaviour (60s / x4 / 5 retries).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-a-subscriptions-retrybackoff-policy-must-be-independently-configurable-req-009
     */
    public function testNoRetryPolicyIsByteForByteUnchanged(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['style' => 'push', 'sink' => 'https://sink.example/hook'],
            'sub-uuid'
        );
        $this->objectService->method('find')->willReturn($subscription);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(503);
        $response->method('getBody')->willReturn('down');
        $response->method('getHeader')->willReturn('');
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        // retryCount 4 -> 5 == default maxRetries: abandons.
        $message = ObjectServiceMockBuilder::objectEntity($this, ['subscriptionId' => 'sub-uuid', 'retryCount' => 4], 'msg-uuid');
        $this->service->deliverMessage($message);

        $this->assertSame('abandoned', $captured['status']);
        $this->assertSame(5, $captured['retryCount']);
        $this->assertNull($captured['nextAttempt']);
    }//end testNoRetryPolicyIsByteForByteUnchanged()


    /**
     * TC-23 / dead-letter-replay REQ-DLR-003: replaying an abandoned
     * synchronization-action message re-runs the synchronization, not an
     * HTTP call.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-audited-replay-returning-the-message-to-the-delivery-machine-req-dlr-003
     */
    public function testReplayActionAwareRerunsSynchronization(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync-1'], 'sync-uuid');
        $subscription    = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'style'  => 'push',
                'action' => ['kind' => 'synchronization', 'synchronizationId' => 'sync-uuid'],
            ],
            'sub-uuid'
        );
        $abandoned = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'abandoned', 'retryCount' => 5, 'subscriptionId' => 'sub-uuid'],
            'msg-uuid'
        );

        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($subscription, $synchronization, $abandoned) {
                if ($schema === 'event_subscription') {
                    return $subscription;
                }

                if ($schema === 'synchronization') {
                    return $synchronization;
                }

                return $abandoned;
            }
        );

        $this->synchronizationService->expects($this->once())
            ->method('synchronize')
            ->with($this->identicalTo($synchronization));
        $this->clientService->expects($this->never())->method('newClient');

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured, $abandoned) {
                if ($captured === null) {
                    $captured = $object;
                }

                return $abandoned;
            }
        );

        $this->service->replayMessage('msg-uuid', 'alice');

        $this->assertSame('pending', $captured['status']);
        $this->assertSame('alice', $captured['replayedBy']);
    }//end testReplayActionAwareRerunsSynchronization()
}//end class
