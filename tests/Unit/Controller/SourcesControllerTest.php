<?php

/**
 * Unit tests for SourcesController circuit-breaker endpoints.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\SourcesController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the circuit-breaker manual trip/reset REST surface.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
 */
class SourcesControllerTest extends TestCase
{

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var SourcesController
     */
    private SourcesController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = $this->createMock(OrObjectService::class);
        $this->callService     = $this->createMock(CallService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $userSession = $this->createMock(IUserSession::class);
        $actionAuth  = $this->createMock(ActionAuthService::class);

        $this->controller = new SourcesController(
            'openconnector',
            $this->createMock(IRequest::class),
            $this->orObjectService,
            $l,
            $userSession,
            $actionAuth,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()


    /**
     * REQ-009 — tripping a known source's breaker returns the open state.
     *
     * @return void
     */
    public function testTripCircuitBreakerReturnsOpenState(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'flaky-source'], 'source-1');
        $this->orObjectService->method('find')->willReturn($source);

        $tripped = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'circuitBreakerState'    => 'open',
                'circuitBreakerOpenedAt' => 1234567,
            ],
            'source-1'
        );
        $this->callService->expects($this->once())
            ->method('tripCircuitBreaker')
            ->with($source)
            ->willReturn($tripped);

        $response = $this->controller->tripCircuitBreaker($this->callService, 'source-1');
        $data     = $response->getData();

        $this->assertSame('open', $data['circuitBreakerState']);
        $this->assertSame(1234567, $data['circuitBreakerOpenedAt']);
        $this->assertSame('source-1', $data['uuid']);
    }//end testTripCircuitBreakerReturnsOpenState()


    /**
     * REQ-009 — resetting a known source's breaker returns the closed state.
     *
     * @return void
     */
    public function testResetCircuitBreakerReturnsClosedState(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'recovered-source'], 'source-2');
        $this->orObjectService->method('find')->willReturn($source);

        $reset = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'circuitBreakerState'        => 'closed',
                'circuitBreakerFailureCount' => 0,
            ],
            'source-2'
        );
        $this->callService->expects($this->once())
            ->method('resetCircuitBreaker')
            ->with($source)
            ->willReturn($reset);

        $response = $this->controller->resetCircuitBreaker($this->callService, 'source-2');
        $data     = $response->getData();

        $this->assertSame('closed', $data['circuitBreakerState']);
        $this->assertSame(0, $data['circuitBreakerFailureCount']);
    }//end testResetCircuitBreakerReturnsClosedState()


    /**
     * REQ-009 — an unknown source id returns 404 from trip, without ever
     * calling into CallService.
     *
     * @return void
     */
    public function testTripCircuitBreakerReturns404OnUnknownSource(): void
    {
        $this->orObjectService->method('find')->willThrowException(new DoesNotExistException('not found'));
        $this->callService->expects($this->never())->method('tripCircuitBreaker');

        $response = $this->controller->tripCircuitBreaker($this->callService, 'missing');

        $this->assertSame(404, $response->getStatus());
    }//end testTripCircuitBreakerReturns404OnUnknownSource()


    /**
     * REQ-009 — an unknown source id returns 404 from reset, without ever
     * calling into CallService.
     *
     * @return void
     */
    public function testResetCircuitBreakerReturns404OnUnknownSource(): void
    {
        $this->orObjectService->method('find')->willThrowException(new DoesNotExistException('not found'));
        $this->callService->expects($this->never())->method('resetCircuitBreaker');

        $response = $this->controller->resetCircuitBreaker($this->callService, 'missing');

        $this->assertSame(404, $response->getStatus());
    }//end testResetCircuitBreakerReturns404OnUnknownSource()

}//end class
