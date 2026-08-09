<?php

/**
 * OpenConnector Product Subscriptions Controller.
 *
 * REST controller for the API Products gateway's subscription flow:
 * subscribe a Consumer to a product's tier (immediate activation or
 * approval-gated), approve/reject a pending subscription, and surface
 * per-product gateway analytics. `api_product`/`api_product_subscription`
 * CRUD otherwise goes through OpenRegister's generic object API exactly
 * like `endpoint`/`consumer` today (design.md API Design) — this
 * controller only exists for the handful of actions that are not plain
 * CRUD, mirroring `ApprovalsController`'s shape.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/archive/2026-07-15-api-product-gateway/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use DateTime;
use OCA\OpenConnector\Exception\ApprovalStateException;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * API Products subscription REST surface: subscribe/approve/reject/analytics.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */
class ProductSubscriptionsController extends Controller
{
    /**
     * OpenRegister register slug.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * Bounded row window for analytics (design.md Decision 3 / REQ-PROM-013).
     *
     * @var integer
     */
    private const ANALYTICS_ROW_LIMIT = 1000;

    /**
     * Constructor.
     *
     * @param string          $appName         The app id.
     * @param IRequest        $request         The current request.
     * @param ApprovalService $approvalService The approval state-machine service (subscription approval gate).
     * @param OrObjectService $orObjectService OpenRegister object service (product/subscription/call_log persistence).
     * @param IUserSession    $userSession     The user session.
     * @param IL10N           $l               The localization service.
     * @param LoggerInterface $logger          Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ApprovalService $approvalService,
        private readonly OrObjectService $orObjectService,
        private readonly IUserSession $userSession,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Subscribe a Consumer to an api_product at a named tier
     * (design.md `POST /api/products/{productId}/subscriptions`).
     * Creating a subscription is an administrative action, same posture as
     * Consumer create/edit today (`ConsumersController`) — `#[NoAdminRequired]`
     * is deliberately NOT used here (design.md Security Considerations).
     *
     * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting],
     * the same positive declaration `SourcesController` and `MappingsController`
     * already use for their administrative endpoints. A bare absence of
     * `#[NoAdminRequired]` is indistinguishable from a forgotten annotation, so
     * the gate is stated in an attribute the middleware enforces rather than in
     * a comment nothing reads.
     *
     * @param string $productId The api_product's id.
     *
     * @return JSONResponse 201 (active), 202 (pending_approval), 400 (unknown tier), or 404 (no such product).
     *
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function subscribe(string $productId): JSONResponse
    {
        try {
            $product = $this->orObjectService->find(
                id: $productId,
                register: self::REGISTER,
                schema: 'api_product',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('No such API product')], Http::STATUS_NOT_FOUND);
        }

        $consumerId = (string) $this->request->getParam('consumerId', '');
        $tierName   = (string) $this->request->getParam('tier', '');

        if ($consumerId === '' || $tierName === '') {
            return new JSONResponse(['error' => $this->l->t('consumerId and tier are required')], Http::STATUS_BAD_REQUEST);
        }

        $tiers = ($product->getObject()['tiers'] ?? []);
        if (is_array($tiers) === false || isset($tiers[$tierName]) === false || is_array($tiers[$tierName]) === false) {
            return new JSONResponse(['error' => $this->l->t('Unknown tier: %s', [$tierName])], Http::STATUS_BAD_REQUEST);
        }

        $tier = $tiers[$tierName];
        $requiresApproval = (bool) ($tier['requiresApproval'] ?? false);
        $now = new DateTime();

        $subscription = $this->orObjectService->saveObject(
            object: [
                'product'         => (string) $product->getUuid(),
                'consumer'        => $consumerId,
                'tier'            => $tierName,
                'status'          => 'pending_approval',
                'requesterUserId' => $this->userSession->getUser()?->getUID(),
                'createdAt'       => $now->format('c'),
            ],
            register: self::REGISTER,
            schema: 'api_product_subscription'
        );

        if ($requiresApproval === false) {
            try {
                $subscription = $this->activateSubscription(subscription: $subscription, activatedAt: $now);
            } catch (ValidationException $e) {
                return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            }

            return new JSONResponse($this->summarizeSubscription(subscription: $subscription), Http::STATUS_CREATED);
        }

        $approverGroup   = (string) ($tier['approverGroup'] ?? '');
        $approvalRequest = $this->approvalService->suspendForSubscription(
            subscriptionId: (string) $subscription->getUuid(),
            approverGroup: $approverGroup,
            onReject: 'error',
            ttlSeconds: ApprovalService::DEFAULT_TTL_SECONDS
        );

        $subscriptionData = $subscription->getObject();
        $subscriptionData['approvalRequestId'] = $approvalRequest->getUuid();
        $subscription = $this->orObjectService->saveObject(
            object: $subscriptionData,
            register: self::REGISTER,
            schema: 'api_product_subscription',
            uuid: $subscription->getUuid()
        );

        return new JSONResponse($this->summarizeSubscription(subscription: $subscription), Http::STATUS_ACCEPTED);

    }//end subscribe()

    /**
     * Approve a `pending_approval` subscription's linked `approval_request`
     * (design.md `POST /api/products/subscriptions/{subscriptionId}/approve`),
     * then flip the subscription's own `status` to `active`
     * (design.md Decision 4 — this orchestration is the CONTROLLER's job,
     * not `ApprovalService`'s).
     *
     * @param string $subscriptionId The api_product_subscription's id.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
     */
    #[NoAdminRequired]
    public function approve(string $subscriptionId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        [$subscription, $approvalRequest, $errorResponse] = $this->resolveActionableSubscription(subscriptionId: $subscriptionId, user: $user);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $comment = $this->request->getParam('comment');

        $this->approvalService->completeApproval(
            approvalRequest: $approvalRequest,
            approver: $user,
            resumeResult: 'success',
            comment: $comment
        );

        try {
            $subscription = $this->activateSubscription(subscription: $subscription, activatedAt: new DateTime());
        } catch (ValidationException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($this->summarizeSubscription(subscription: $subscription));

    }//end approve()

    /**
     * Reject a `pending_approval` subscription's linked `approval_request`
     * (design.md `POST /api/products/subscriptions/{subscriptionId}/reject`),
     * then flip the subscription's own `status` to `rejected`.
     *
     * @param string $subscriptionId The api_product_subscription's id.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
     */
    #[NoAdminRequired]
    public function reject(string $subscriptionId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        [$subscription, $approvalRequest, $errorResponse] = $this->resolveActionableSubscription(subscriptionId: $subscriptionId, user: $user);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $comment = (string) $this->request->getParam('comment', '');

        try {
            $this->approvalService->reject(approvalRequest: $approvalRequest, approver: $user, comment: $comment);
        } catch (ApprovalStateException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

        $data           = $subscription->getObject();
        $data['status'] = 'rejected';

        $subscription = $this->orObjectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: 'api_product_subscription',
            uuid: $subscription->getUuid()
        );

        return new JSONResponse($this->summarizeSubscription(subscription: $subscription));

    }//end reject()

    /**
     * Per-product gateway analytics — request count, error rate, and
     * p50/p95/p99 response-time latency percentiles computed from the most
     * recent inbound `call_log` rows carrying this product's uuid
     * (design.md `GET /api/products/{productId}/analytics`).
     *
     * The figures are PRODUCT-WIDE: they aggregate the call_log rows of every
     * consumer of this product, so one subscriber could read another's traffic
     * volume and error rate from them. That is operator information, not
     * subscriber information — a per-subscriber view would be a different
     * endpoint, scoped to the caller's own consumer.
     *
     * @param string $productId The api_product's id.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
     *
     * ADMIN ONLY, and now declared rather than implied. The previous revision
     * argued in prose that "absence of the attribute IS the admin gate" — but a
     * comment is not enforced by anything, and it reads identically to an
     * endpoint whose author simply forgot the annotation. These product-wide
     * figures aggregate every consumer's call_log rows, so the distinction is
     * load-bearing. #[AuthorizedAdminSetting] is the same positive, middleware-
     * enforced declaration `SourcesController` and `MappingsController` already
     * use, and it is what makes the posture readable to a reviewer and to CI.
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function analytics(string $productId): JSONResponse
    {
        try {
            $this->orObjectService->find(
                id: $productId,
                register: self::REGISTER,
                schema: 'api_product',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('No such API product')], Http::STATUS_NOT_FOUND);
        }

        $rows = $this->fetchProductCallLogRows(productId: $productId);

        $requestCount  = count($rows);
        $errorCount    = 0;
        $responseTimes = [];

        foreach ($rows as $row) {
            $statusCode = (int) ($row['statusCode'] ?? 0);
            if ($statusCode >= 400) {
                $errorCount++;
            }

            if (isset($row['responseTime']) === true && is_numeric($row['responseTime']) === true) {
                $responseTimes[] = (float) $row['responseTime'];
            }
        }

        sort($responseTimes);

        $errorRate = 0;
        if ($requestCount > 0) {
            $errorRate = round(($errorCount / $requestCount), 4);
        }

        return new JSONResponse(
            [
                'requestCount' => $requestCount,
                'errorRate'    => $errorRate,
                'latency'      => [
                    'p50' => $this->percentile(sortedValues: $responseTimes, fraction: 0.5),
                    'p95' => $this->percentile(sortedValues: $responseTimes, fraction: 0.95),
                    'p99' => $this->percentile(sortedValues: $responseTimes, fraction: 0.99),
                ],
            ]
        );

    }//end analytics()

    /**
     * Fetch the bounded most-recent inbound `call_log` rows for a product
     * (design.md Decision 3 / `REQ-PROM-013` — 1000-row window).
     *
     * NOTHING PROPAGATES OUT OF HERE. The lookup is wrapped in
     * `catch (Throwable)`, which subsumes every exception the caller could
     * translate — a `ValidationException` or `DoesNotExistException` from the
     * object service included. Analytics is best-effort telemetry, so a failed
     * lookup is logged and reported as no rows rather than failing the request:
     * a product with no measurable traffic and a product whose call_log could
     * not be read both answer zero, and only the log tells them apart.
     *
     * That is a deliberate choice and the reason this method has no `@throws`.
     *
     * @param string $productId The api_product's id.
     *
     * @return array<int, array<string, mixed>> Each row's own fields.
     */
    private function fetchProductCallLogRows(string $productId): array
    {
        try {
            $matches = $this->orObjectService->findAll(
                config: [
                    'filters' => [
                        'register'  => self::REGISTER,
                        'schema'    => 'call_log',
                        'product'   => $productId,
                        'direction' => 'inbound',
                    ],
                    'sort'    => ['created' => 'desc'],
                    'limit'   => self::ANALYTICS_ROW_LIMIT,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('openconnector: failed to fetch product call_log rows for analytics: '.$e->getMessage());
            return [];
        }

        $results = ($matches['results'] ?? $matches);
        if (is_array($results) === false) {
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            if ($row instanceof ObjectEntity) {
                $rows[] = $row->getObject();
            }
        }

        return $rows;

    }//end fetchProductCallLogRows()

    /**
     * Nearest-rank percentile of a pre-sorted (ascending) value list.
     *
     * @param array<int, float> $sortedValues Ascending-sorted values.
     * @param float             $fraction     The percentile as a fraction (e.g. 0.95 for p95).
     *
     * @return float The percentile value, or 0 when the list is empty (REQ-APG-007 "zero, not an error").
     */
    private function percentile(array $sortedValues, float $fraction): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }

        $index = ((int) ceil($fraction * $count) - 1);
        $index = max(0, min(($count - 1), $index));

        return (float) $sortedValues[$index];

    }//end percentile()

    /**
     * Resolve a subscription's linked, actionable `approval_request` and
     * verify the caller is authorized to act on it — shared preamble for
     * {@see approve()} and {@see reject()}.
     *
     * @param string $subscriptionId The api_product_subscription's id.
     * @param IUser  $user           The acting user.
     *
     * @return array{0: ObjectEntity|null, 1: ObjectEntity|null, 2: JSONResponse|null}
     */
    private function resolveActionableSubscription(string $subscriptionId, IUser $user): array
    {
        try {
            $subscription = $this->orObjectService->find(
                id: $subscriptionId,
                register: self::REGISTER,
                schema: 'api_product_subscription',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return [null, null, new JSONResponse(['error' => $this->l->t('No such subscription')], Http::STATUS_NOT_FOUND)];
        }

        $approvalRequestId = ($subscription->getObject()['approvalRequestId'] ?? null);
        if (empty($approvalRequestId) === true) {
            $message = $this->l->t('This subscription is not gated by an approval request');
            return [null, null, new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST)];
        }

        try {
            $approvalRequest = $this->approvalService->find(id: (string) $approvalRequestId);
        } catch (ApprovalStateException $e) {
            return [null, null, new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus())];
        }

        if ($this->approvalService->isAuthorizedApprover(approvalRequest: $approvalRequest, user: $user) === false) {
            $message = $this->l->t('You are not a member of this request\'s approver group');
            return [null, null, new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN)];
        }

        try {
            $this->approvalService->assertActionable(approvalRequest: $approvalRequest);
        } catch (ApprovalStateException $e) {
            return [null, null, new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus())];
        }

        return [$subscription, $approvalRequest, null];

    }//end resolveActionableSubscription()

    /**
     * Flip a subscription's status to `active` and stamp `activatedAt`
     * (REQ-APG-003/REQ-APG-004 — used both by the no-approval subscribe path
     * and by {@see approve()}).
     *
     * Propagates rather than translating: this returns an ObjectEntity, so it
     * has no JSONResponse to answer with. Both callers translate — see
     * `subscribe()` and `approve()`. They did not until now, and a
     * schema-resolution failure reached NC's dispatcher as a bare 500 on a
     * request that should answer with a reason (#1167).
     *
     * @param ObjectEntity $subscription The subscription to activate.
     * @param DateTime     $activatedAt  The activation timestamp.
     *
     * @return ObjectEntity The updated subscription.
     *
     * @throws ValidationException When the schema cannot be resolved for the
     *                             subscription — OpenRegister's saveObject()
     *                             raises this so a controller can answer 400
     *                             rather than emit a raw TypeError 500.
     */
    private function activateSubscription(ObjectEntity $subscription, DateTime $activatedAt): ObjectEntity
    {
        $data           = $subscription->getObject();
        $data['status'] = 'active';
        $data['activatedAt'] = $activatedAt->format('c');

        return $this->orObjectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: 'api_product_subscription',
            uuid: $subscription->getUuid()
        );

    }//end activateSubscription()

    /**
     * Summarize a subscription row for the subscribe/approve/reject response bodies.
     *
     * @param ObjectEntity $subscription The subscription.
     *
     * @return array
     */
    private function summarizeSubscription(ObjectEntity $subscription): array
    {
        $data = $subscription->getObject();

        return [
            'uuid'              => $subscription->getUuid(),
            'status'            => ($data['status'] ?? 'pending_approval'),
            'tier'              => ($data['tier'] ?? null),
            'product'           => ($data['product'] ?? null),
            'consumer'          => ($data['consumer'] ?? null),
            'approvalRequestId' => ($data['approvalRequestId'] ?? null),
        ];

    }//end summarizeSubscription()
}//end class
