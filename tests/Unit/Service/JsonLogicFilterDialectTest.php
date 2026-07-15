<?php
/**
 * Unit tests for the `jsonlogic` filter dialect in EventService::evaluateFilters.
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
 * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `evaluateFilters`/`doesEventMatchSubscription` are private, so the
 * `jsonlogic` dialect (and the pre-existing dialects, for regression) are
 * exercised indirectly through the public `processEvent()` entry point,
 * matching how this app already tests filter behaviour elsewhere.
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
 */
class JsonLogicFilterDialectTest extends TestCase
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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $logger              = $this->createMock(LoggerInterface::class);
        $clientService       = $this->createMock(IClientService::class);

        $this->service = new EventService(
            $this->objectService,
            $clientService,
            $logger,
            new WebhookSignatureService($logger),
            $this->createMock(SynchronizationService::class),
            $this->createMock(JobService::class),
        );
    }//end setUp()


    /**
     * A subscription's `jsonlogic` filter evaluating `true` (via
     * `JsonLogic::apply`) produces a matching `event_message` — a PULL
     * subscription is used so no HTTP delivery is attempted, isolating the
     * filter-match assertion from delivery machinery.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
     */
    public function testJsonLogicFilterMatchesWhenConditionTrue(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'  => 'active',
                'style'   => 'pull',
                'filters' => [['jsonlogic' => ['in' => ['invoice', ['var' => 'data.attributes.tags']]]]],
            ],
            'sub-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);

        $savedMessages = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$savedMessages) {
                $savedMessages[] = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'msg-uuid');
            }
        );

        $event = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'type' => 'com.nextcloud.files.node.tagged',
                'data' => ['attributes' => ['tags' => ['invoice', 'urgent']]],
            ],
            'event-uuid'
        );

        $messages = $this->service->processEvent($event);

        $this->assertCount(1, $messages);
    }//end testJsonLogicFilterMatchesWhenConditionTrue()


    /**
     * A subscription's `jsonlogic` filter evaluating `false` produces no
     * matching `event_message`.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/events-cloudevents/spec.md#requirement-cloudevent-fan-out-to-matching-subscriptions-req-001
     */
    public function testJsonLogicFilterRejectsWhenConditionFalse(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'  => 'active',
                'style'   => 'pull',
                'filters' => [['jsonlogic' => ['in' => ['invoice', ['var' => 'data.attributes.tags']]]]],
            ],
            'sub-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);

        $event = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'type' => 'com.nextcloud.files.node.tagged',
                'data' => ['attributes' => ['tags' => ['personal']]],
            ],
            'event-uuid'
        );

        $messages = $this->service->processEvent($event);

        $this->assertCount(0, $messages);
    }//end testJsonLogicFilterRejectsWhenConditionFalse()


    /**
     * Regression: the pre-existing `exact`/`prefix`/`suffix`/`expression`
     * dialects are unaffected by adding `jsonlogic` as a new case in the
     * same switch.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    public function testPreExistingDialectsStillMatch(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'  => 'active',
                'style'   => 'pull',
                'filters' => [
                    ['exact' => ['type' => 'com.example.created']],
                    ['prefix' => ['type' => 'com.example.']],
                    ['suffix' => ['type' => '.created']],
                    ['expression' => "type == 'com.example.created'"],
                ],
            ],
            'sub-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);

        $event = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['type' => 'com.example.created'],
            'event-uuid'
        );

        $messages = $this->service->processEvent($event);

        $this->assertCount(1, $messages);
    }//end testPreExistingDialectsStillMatch()


    /**
     * Regression: a failing pre-existing dialect still rejects the event
     * (short-circuit unaffected by the new `jsonlogic` case).
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-events-cloudevents/tasks.md#task-1
     */
    public function testPreExistingDialectStillRejects(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'  => 'active',
                'style'   => 'pull',
                'filters' => [['exact' => ['type' => 'com.example.other']]],
            ],
            'sub-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$subscription], 'total' => 1]);

        $event = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['type' => 'com.example.created'],
            'event-uuid'
        );

        $messages = $this->service->processEvent($event);

        $this->assertCount(0, $messages);
    }//end testPreExistingDialectStillRejects()
}//end class
