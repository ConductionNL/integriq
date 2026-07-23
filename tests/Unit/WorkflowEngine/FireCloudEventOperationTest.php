<?php
/**
 * Unit tests for FireCloudEventOperation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\WorkflowEngine;

use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\WorkflowEngine\FireCloudEventOperation;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004
 */
class FireCloudEventOperationTest extends TestCase
{


    /**
     * Build an operation instance with the given (possibly mocked) event service.
     *
     * @param EventService         $eventService The event service.
     * @param LoggerInterface|null $logger       Optional logger mock.
     *
     * @return FireCloudEventOperation
     */
    private function makeOperation(EventService $eventService, ?LoggerInterface $logger=null): FireCloudEventOperation
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text) => $text);

        return new FireCloudEventOperation(
            $eventService,
            $l10n,
            $this->createMock(IURLGenerator::class),
            ($logger ?? $this->createMock(LoggerInterface::class))
        );

    }//end makeOperation()


    /**
     * getEntityId() returns NC core's only bundled IEntity.
     *
     * @return void
     */
    public function testGetEntityIdReturnsFileEntity(): void
    {
        $operation = $this->makeOperation($this->createMock(EventService::class));
        $this->assertSame('OCA\WorkflowEngine\Entity\File', $operation->getEntityId());

    }//end testGetEntityIdReturnsFileEntity()


    /**
     * isAvailableForScope() returns true only for SCOPE_ADMIN (REQ-005).
     *
     * @return void
     */
    public function testIsAvailableOnlyForAdminScope(): void
    {
        $operation = $this->makeOperation($this->createMock(EventService::class));
        $this->assertTrue($operation->isAvailableForScope(IManager::SCOPE_ADMIN));
        $this->assertFalse($operation->isAvailableForScope(IManager::SCOPE_USER));

    }//end testIsAvailableOnlyForAdminScope()


    /**
     * A matching flow fires the configured CloudEvent with type/source/subject.
     *
     * @return void
     */
    public function testOnEventFiresTheConfiguredCloudEvent(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->once())
            ->method('emitCloudEvent')
            ->with(
                type: 'nl.conduction.flow.file-tagged',
                source: '/openconnector/flow',
                subject: null,
                data: ['eventName' => 'OCP\Files::postWrite']
            );

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn(
            [['operation' => json_encode(['type' => 'nl.conduction.flow.file-tagged', 'source' => '/openconnector/flow'])]]
        );

        $operation = $this->makeOperation($eventService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);

    }//end testOnEventFiresTheConfiguredCloudEvent()


    /**
     * Static configured `data` merges into the emitted CloudEvent alongside `eventName`.
     *
     * @return void
     */
    public function testStaticDataMergesIntoEmittedCloudEvent(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->once())
            ->method('emitCloudEvent')
            ->with(
                type: 'com.example.foo',
                source: '/openconnector/flow',
                subject: null,
                data: [
                    'eventName' => 'OCP\Files::postWrite',
                    'reason'    => 'tagged for export',
                ]
            );

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn(
            [
                [
                    'operation' => json_encode(
                        [
                            'type'   => 'com.example.foo',
                            'source' => '/openconnector/flow',
                            'data'   => ['reason' => 'tagged for export'],
                        ]
                    ),
                ],
            ]
        );

        $operation = $this->makeOperation($eventService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);

    }//end testStaticDataMergesIntoEmittedCloudEvent()


    /**
     * A flow with malformed JSON settings is skipped (no crash, no dispatch).
     *
     * @return void
     */
    public function testMalformedJsonIsSkipped(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('emitCloudEvent');

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn([['operation' => 'not-json']]);

        $operation = $this->makeOperation($eventService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);
        $this->addToAssertionCount(1);

    }//end testMalformedJsonIsSkipped()


    /**
     * validateOperation() throws UnexpectedValueException on malformed JSON.
     *
     * @return void
     */
    public function testValidateOperationRejectsMalformedJson(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $operation = $this->makeOperation($this->createMock(EventService::class));
        $operation->validateOperation('rule', [], 'not-json');

    }//end testValidateOperationRejectsMalformedJson()


    /**
     * validateOperation() throws UnexpectedValueException when `type`/`source` is missing/empty.
     *
     * @return void
     */
    public function testValidateOperationRejectsMissingTypeOrSource(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $operation = $this->makeOperation($this->createMock(EventService::class));
        $operation->validateOperation('rule', [], json_encode(['type' => 'com.example.foo']));

    }//end testValidateOperationRejectsMissingTypeOrSource()


    /**
     * validateOperation() does not throw for valid `type`/`source` settings.
     *
     * @return void
     */
    public function testValidateOperationAcceptsValidSettings(): void
    {
        $operation = $this->makeOperation($this->createMock(EventService::class));
        $operation->validateOperation(
            'rule',
            [],
            json_encode(['type' => 'com.example.foo', 'source' => '/openconnector/flow'])
        );
        $this->addToAssertionCount(1);

    }//end testValidateOperationAcceptsValidSettings()
}//end class
