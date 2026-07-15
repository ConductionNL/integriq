<?php
/**
 * Integration test: an authenticated inbound ZGW Notificaties API
 * notification flows end-to-end through the REAL
 * NotificatiesSubscriberController -> NotificatiesSubscriberService ->
 * EventService::emitCloudEvent -> processEvent -> action.kind='synchronization'
 * dispatch chain, using real collaborator instances (only the outermost
 * boundaries — OpenRegister persistence, HTTP client, and
 * SynchronizationService — are test doubles). Mirrors the standalone-with-
 * OCP-stubs convention `tests/Integration/NextcloudEventDeliveryTest.php`
 * already uses; no live network call is made anywhere in this test.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration;

use OCA\OpenConnector\Controller\NotificatiesSubscriberController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\NotificatiesSubscriberService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
 */
class NotificatiesCallbackTest extends TestCase
{

    /**
     * TC-7 / REQ-003: an authenticated notification for an active abonnement,
     * matched by an event_subscription with action.kind='synchronization',
     * runs the synchronization end-to-end — no live network call.
     *
     * @return void
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
     */
    public function testAuthenticatedNotificationTriggersSynchronization(): void
    {
        $abonnement = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'name'           => 'Zaken kanaal',
                'sourceId'       => 'source-1',
                'kanalen'        => [['naam' => 'zaken', 'filters' => []]],
                'status'         => 'active',
                'consumerId'     => 'consumer-1',
                'authHeaderName' => 'Authorization',
                'authScheme'     => '',
            ],
            'abon-1'
        );

        $consumer = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'name'                        => 'Notificaties abonnement: zaken',
                'authorizationType'           => 'apiKey',
                'authorizationConfiguration'  => ['apiKey' => 'notif-secret-xyz'],
            ],
            'consumer-1'
        );

        $subscription = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status' => 'active',
                'style'  => 'push',
                'types'  => ['nl.conduction.zgw.notificatie.zaak'],
                'action' => ['kind' => 'synchronization', 'synchronizationId' => 'sync-1'],
            ],
            'sub-1'
        );

        $synchronization = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'zaken-sync'], 'sync-1');

        $orObjectService = ObjectServiceMockBuilder::make($this);
        $orObjectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($abonnement, $synchronization) {
                if ($schema === 'notificaties_abonnement') {
                    return $abonnement;
                }

                if ($schema === 'synchronization') {
                    return $synchronization;
                }

                return null;
            }
        );
        $orObjectService->method('findAll')->willReturnCallback(
            function (array $config=[], ...$rest) use ($consumer, $subscription) {
                $schema = ($config['filters']['schema'] ?? null);
                if ($schema === 'consumer') {
                    return ['results' => [$consumer], 'total' => 1];
                }

                if ($schema === 'event_subscription') {
                    return ['results' => [$subscription], 'total' => 1];
                }

                return ['results' => [], 'total' => 0];
            }
        );
        $orObjectService->method('saveObject')->willReturnCallback(
            fn (array $object, string $register, string $schema, ?string $uuid=null) => ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? ('generated-'.$schema)))
        );

        $logger = $this->createMock(LoggerInterface::class);

        // Real EventService — the pipe under test (processEvent/attemptDelivery/dispatchSynchronizationAction unchanged).
        $synchronizationService = $this->createMock(SynchronizationService::class);
        $synchronizationService->expects($this->once())
            ->method('synchronize')
            ->with($this->identicalTo($synchronization));

        $eventService = new EventService(
            $orObjectService,
            $this->createMock(IClientService::class),
            $logger,
            new WebhookSignatureService($logger),
            $synchronizationService,
            $this->createMock(JobService::class),
            $this->createMock(CallService::class),
            $this->createMock(FlowRunnerService::class)
        );

        // Real NotificatiesSubscriberService — the capability under test.
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/openconnector/api/notificaties/callback/abon-1');

        $subscriberService = new NotificatiesSubscriberService(
            $orObjectService,
            $this->createMock(CallService::class),
            $eventService,
            new WebhookSignatureService($logger),
            $urlGenerator,
            $logger
        );

        // Real AuthorizationService — the reused REQ-CON-001/REQ-CON-002 auth path.
        $cache        = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $authorizationService = new AuthorizationService(
            $this->createMock(IUserManager::class),
            $this->createMock(IUserSession::class),
            $orObjectService,
            $this->createMock(IGroupManager::class),
            $cacheFactory,
            $this->createMock(IRequest::class)
        );

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            fn (string $name) => ($name === 'Authorization') ? 'notif-secret-xyz' : ''
        );
        $request->method('getParams')->willReturn(
            [
                'kanaal'       => 'zaken',
                'hoofdObject'  => 'https://zaken.example/api/v1/zaken/uuid-1',
                'resource'     => 'zaak',
                'resourceUrl'  => 'https://zaken.example/api/v1/zaken/uuid-1',
                'actie'        => 'create',
                'aanmaakdatum' => '2026-07-15T10:00:00Z',
                'kenmerken'    => ['bronorganisatie' => '123443210'],
            ]
        );

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $controller = new NotificatiesSubscriberController(
            'openconnector',
            $request,
            $subscriberService,
            $authorizationService,
            $orObjectService,
            $this->createMock(ActionAuthService::class),
            $this->createMock(IUserSession::class),
            $l,
            $logger
        );

        $response = $controller->callback('abon-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue(($response->getData()['received'] ?? false));

    }//end testAuthenticatedNotificationTriggersSynchronization()

    /**
     * TC-5 / REQ-002: a mismatched auth header is rejected with HTTP 401
     * before any side effect — no event persisted, synchronize NOT invoked.
     *
     * @return void
     *
     * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
     */
    public function testMismatchedAuthHeaderRejectedWithNoSideEffect(): void
    {
        $abonnement = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'status'         => 'active',
                'consumerId'     => 'consumer-1',
                'authHeaderName' => 'Authorization',
                'authScheme'     => '',
            ],
            'abon-2'
        );
        $consumer = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['authorizationType' => 'apiKey', 'authorizationConfiguration' => ['apiKey' => 'the-real-secret']],
            'consumer-1'
        );

        $orObjectService = ObjectServiceMockBuilder::make($this);
        $orObjectService->method('find')->willReturnCallback(
            fn (string $id, string $register, string $schema) => ($schema === 'notificaties_abonnement') ? $abonnement : null
        );
        $orObjectService->method('findAll')->willReturnCallback(
            function (array $config=[], ...$rest) use ($consumer) {
                $schema = ($config['filters']['schema'] ?? null);
                return ($schema === 'consumer') ? ['results' => [$consumer], 'total' => 1] : ['results' => [], 'total' => 0];
            }
        );
        $orObjectService->expects($this->never())->method('saveObject');

        $logger                 = $this->createMock(LoggerInterface::class);
        $synchronizationService = $this->createMock(SynchronizationService::class);
        $synchronizationService->expects($this->never())->method('synchronize');

        $eventService = new EventService(
            $orObjectService,
            $this->createMock(IClientService::class),
            $logger,
            new WebhookSignatureService($logger),
            $synchronizationService,
            $this->createMock(JobService::class),
            $this->createMock(CallService::class),
            $this->createMock(FlowRunnerService::class)
        );

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $subscriberService = new NotificatiesSubscriberService(
            $orObjectService,
            $this->createMock(CallService::class),
            $eventService,
            new WebhookSignatureService($logger),
            $urlGenerator,
            $logger
        );

        $cache        = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $authorizationService = new AuthorizationService(
            $this->createMock(IUserManager::class),
            $this->createMock(IUserSession::class),
            $orObjectService,
            $this->createMock(IGroupManager::class),
            $cacheFactory,
            $this->createMock(IRequest::class)
        );

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('totally-wrong-secret');

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $controller = new NotificatiesSubscriberController(
            'openconnector',
            $request,
            $subscriberService,
            $authorizationService,
            $orObjectService,
            $this->createMock(ActionAuthService::class),
            $this->createMock(IUserSession::class),
            $l,
            $logger
        );

        $response = $controller->callback('abon-2');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testMismatchedAuthHeaderRejectedWithNoSideEffect()
}//end class
