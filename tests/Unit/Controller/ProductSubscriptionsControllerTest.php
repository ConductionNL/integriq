<?php

/**
 * Unit tests for ProductSubscriptionsController — subscribe/approve/reject/
 * analytics for the API Products gateway.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\ProductSubscriptionsController;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the API Products subscription REST surface.
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */
class ProductSubscriptionsControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var ApprovalService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $approvalService;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var ProductSubscriptionsController
     */
    private ProductSubscriptionsController $controller;


    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->userSession     = $this->createMock(IUserSession::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn(string $text, array $params=[]) => vsprintf(str_replace('%s', '%1$s', $text), $params)
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new ProductSubscriptionsController(
            'openconnector',
            $this->request,
            $this->approvalService,
            $this->orObjectService,
            $this->userSession,
            $l,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * REQ-APG-003 scenario "subscribing to a tier that requires no approval
     * activates immediately" — HTTP 201, status active.
     *
     * @return void
     */
    public function testSubscribeToNoApprovalTierActivatesImmediately(): void
    {
        $product = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['tiers' => ['free' => ['rateLimit' => ['requestsPerWindow' => 60, 'windowSeconds' => 60]]]],
            'product-1'
        );

        $this->orObjectService->method('find')->willReturn($product);
        $this->request->method('getParam')->willReturnMap(
            [
                ['consumerId', '', 'consumer-1'],
                ['tier', '', 'free'],
            ]
        );

        $saved = [];
        $this->orObjectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) use (&$saved) {
                $saved[] = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'sub-1'));
            }
        );

        $response = $this->controller->subscribe(productId: 'product-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('active', $response->getData()['status']);
    }//end testSubscribeToNoApprovalTierActivatesImmediately()


    /**
     * REQ-APG-004 scenario "a gold tier requiring approval creates a
     * pending subscription" — HTTP 202, status pending_approval, an
     * approvalRequestId is present, and ApprovalService::
     * suspendForSubscription() is invoked.
     *
     * @return void
     */
    public function testSubscribeToApprovalGatedTierReturns202Pending(): void
    {
        $product = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['tiers' => ['gold' => ['requiresApproval' => true, 'approverGroup' => 'gateway-approvers']]],
            'product-1'
        );

        $this->orObjectService->method('find')->willReturn($product);
        $this->request->method('getParam')->willReturnMap(
            [
                ['consumerId', '', 'consumer-1'],
                ['tier', '', 'gold'],
            ]
        );

        $this->orObjectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) {
                return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'sub-1'));
            }
        );

        $approvalRequest = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-1');
        $this->approvalService->expects($this->once())
            ->method('suspendForSubscription')
            ->with('sub-1', 'gateway-approvers', 'error', ApprovalService::DEFAULT_TTL_SECONDS)
            ->willReturn($approvalRequest);

        $response = $this->controller->subscribe(productId: 'product-1');

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $this->assertSame('pending_approval', $response->getData()['status']);
        $this->assertSame('approval-1', $response->getData()['approvalRequestId']);
    }//end testSubscribeToApprovalGatedTierReturns202Pending()


    /**
     * REQ-APG-003 scenario "subscribing to an unknown tier is rejected" —
     * HTTP 400, no subscription created.
     *
     * @return void
     */
    public function testSubscribeToUnknownTierReturns400(): void
    {
        $product = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['tiers' => ['free' => [], 'gold' => []]],
            'product-1'
        );

        $this->orObjectService->method('find')->willReturn($product);
        $this->request->method('getParam')->willReturnMap(
            [
                ['consumerId', '', 'consumer-1'],
                ['tier', '', 'platinum'],
            ]
        );

        $this->orObjectService->expects($this->never())->method('saveObject');

        $response = $this->controller->subscribe(productId: 'product-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSubscribeToUnknownTierReturns400()


    /**
     * Subscribing to a non-existent product returns 404.
     *
     * @return void
     */
    public function testSubscribeToUnknownProductReturns404(): void
    {
        $this->orObjectService->method('find')->willThrowException(new DoesNotExistException('no such product'));

        $response = $this->controller->subscribe(productId: 'missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testSubscribeToUnknownProductReturns404()


    /**
     * REQ-APG-004 scenario "approving the request activates the
     * subscription" — the subscription's status becomes active with
     * activatedAt set, after ApprovalService::completeApproval() runs.
     *
     * @return void
     */
    public function testApproveActivatesSubscription(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'pending_approval', 'approvalRequestId' => 'approval-1', 'tier' => 'gold'],
            'sub-1'
        );
        $approvalRequest = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-1');

        $this->orObjectService->method('find')->willReturn($subscription);
        $this->approvalService->method('find')->willReturn($approvalRequest);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->approvalService->expects($this->once())->method('assertActionable');
        $this->approvalService->expects($this->once())
            ->method('completeApproval')
            ->with($approvalRequest, $this->anything(), 'success', null)
            ->willReturn($approvalRequest);

        $saved = null;
        $this->orObjectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) use (&$saved) {
                $saved = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'sub-1'));
            }
        );

        $response = $this->controller->approve(subscriptionId: 'sub-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('active', $saved['status']);
        $this->assertNotEmpty($saved['activatedAt']);
    }//end testApproveActivatesSubscription()


    /**
     * A subscription with no linked approval_request cannot be approved —
     * HTTP 400.
     *
     * @return void
     */
    public function testApproveWithoutApprovalRequestReturns400(): void
    {
        $subscription = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending_approval'], 'sub-1');
        $this->orObjectService->method('find')->willReturn($subscription);

        $response = $this->controller->approve(subscriptionId: 'sub-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testApproveWithoutApprovalRequestReturns400()


    /**
     * REQ-APG-007 scenario "a product with no recorded traffic reports
     * zero, not an error" — requestCount 0, errorRate 0, all percentiles 0.
     *
     * @return void
     */
    public function testAnalyticsForTrafficFreeProductReportsZero(): void
    {
        $product = ObjectServiceMockBuilder::objectEntity($this, [], 'product-1');

        $this->orObjectService->method('find')->willReturn($product);
        $this->orObjectService->method('findAll')->willReturn(['results' => []]);

        $response = $this->controller->analytics(productId: 'product-1');

        $data = $response->getData();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(0, $data['requestCount']);
        $this->assertSame(0, $data['errorRate']);
        $this->assertSame(0.0, $data['latency']['p50']);
        $this->assertSame(0.0, $data['latency']['p95']);
        $this->assertSame(0.0, $data['latency']['p99']);
    }//end testAnalyticsForTrafficFreeProductReportsZero()


    /**
     * REQ-APG-007 scenario "analytics reflect recent traffic" —
     * requestCount and errorRate reflect the recorded call_log rows.
     *
     * @return void
     */
    public function testAnalyticsReflectsRecentTraffic(): void
    {
        $product = ObjectServiceMockBuilder::objectEntity($this, [], 'product-1');
        $this->orObjectService->method('find')->willReturn($product);

        $rows = [];
        for ($i = 0; $i < 95; $i++) {
            $rows[] = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'responseTime' => 50], 'row-'.$i);
        }

        for ($i = 0; $i < 5; $i++) {
            $rows[] = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 500, 'responseTime' => 500], 'err-row-'.$i);
        }

        $this->orObjectService->method('findAll')->willReturn(['results' => $rows]);

        $response = $this->controller->analytics(productId: 'product-1');
        $data     = $response->getData();

        $this->assertSame(100, $data['requestCount']);
        $this->assertSame(0.05, $data['errorRate']);
    }//end testAnalyticsReflectsRecentTraffic()


    /**
     * `analytics()` is ADMIN ONLY, and nothing in the code says so positively.
     *
     * Nextcloud has no "admin required" attribute for a plain controller
     * method: admin is what you get when `#[NoAdminRequired]` is ABSENT. So the
     * posture is carried by a missing line, and a missing line is exactly what
     * somebody adds without noticing — one `#[NoAdminRequired]` here would
     * hand every subscriber the product-wide traffic and error figures of
     * every OTHER subscriber, silently, with no test failing.
     *
     * This pins the absence. It is the only form the assertion can take.
     *
     * @return void
     *
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
     */
    public function testAnalyticsIsAdminOnly(): void
    {
        $method     = new \ReflectionMethod(ProductSubscriptionsController::class, 'analytics');
        $attributes = array_map(
            static fn (\ReflectionAttribute $a): string => $a->getName(),
            $method->getAttributes()
        );

        $this->assertNotContains(
            \OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
            $attributes,
            'analytics() returns PRODUCT-WIDE traffic and error figures aggregated across every '
            .'consumer of the product. #[NoAdminRequired] would expose one subscriber\'s volume '
            .'and error rate to another. If this endpoint is meant to be reachable by '
            .'non-admins, it needs a per-consumer scope first, not this attribute.'
        );

        $this->assertNotContains(
            \OCP\AppFramework\Http\Attribute\PublicPage::class,
            $attributes,
            'analytics() must never be a public page — it reads operator telemetry.'
        );
    }//end testAnalyticsIsAdminOnly()

}//end class
