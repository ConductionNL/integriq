<?php

/**
 * Unit tests for the inbound ZGW Notificaties callback's authentication.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\NotificatiesSubscriberController;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\AuthorizationService;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `callback()` is the app's only `#[PublicPage]` write path: Nextcloud's
 * middleware admits the caller without a session, because the caller is a
 * remote Notificaties Routeer Component that has none. The API key echoed
 * back in the configured header is therefore the whole of its access control,
 * and these tests are about that key and nothing else.
 *
 * Each "is accepted" case is paired with the same request minus the one thing
 * that makes it legitimate, so none of them can pass by the endpoint simply
 * accepting everything.
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-callback-authentication-reuses-consumer-management-apikey-verification-req-002
 */
class NotificatiesSubscriberControllerCallbackTest extends TestCase {

	private const ABONNEMENT_ID = 'ab-1111-2222';

	private const CONSUMER_ID = 'cons-3333-4444';

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var NotificatiesSubscriberService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $subscriberService;

	/**
	 * @var AuthorizationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $authorizationService;

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Build the collaborators each test then configures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->subscriberService = $this->createMock(NotificatiesSubscriberService::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->orObjectService = $this->createMock(OrObjectService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->l->method('t')->willReturnArgument(0);

	}//end setUp()

	/**
	 * Construct the controller under test.
	 *
	 * @return NotificatiesSubscriberController
	 */
	private function controller(): NotificatiesSubscriberController {
		return new NotificatiesSubscriberController(
			'openconnector',
			$this->request,
			$this->subscriberService,
			$this->authorizationService,
			$this->orObjectService,
			$this->actionAuth,
			$this->userSession,
			$this->l,
			$this->logger
		);

	}//end controller()

	/**
	 * A stored abonnement with the given configuration.
	 *
	 * @param array $config Abonnement object fields.
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function abonnement(array $config) {
		$abonnement = $this->createMock(ObjectEntity::class);
		$abonnement->method('getObject')->willReturn($config);

		return $abonnement;
	}//end abonnement()

	/**
	 * A consumer whose UUID is $uuid.
	 *
	 * @param string $uuid The consumer UUID.
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function consumer(string $uuid) {
		$consumer = $this->createMock(ObjectEntity::class);
		$consumer->method('getUuid')->willReturn($uuid);

		return $consumer;
	}//end consumer()

	/**
	 * The happy path: the key authenticates, and it belongs to the consumer
	 * this abonnement is bound to.
	 *
	 * @return void
	 */
	public function testAValidKeyForThisAbonnementsConsumerIsAccepted(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(
			$this->abonnement(['consumerId' => self::CONSUMER_ID])
		);
		$this->request->method('getHeader')->with('Authorization')->willReturn('secret-key');
		$this->request->method('getParams')->willReturn(['_route' => 'x', 'channel' => 'zaken']);
		$this->authorizationService->method('getResolvedConsumer')->willReturn(
			$this->consumer(self::CONSUMER_ID)
		);

		$this->subscriberService->expects($this->once())
			->method('handleInboundNotification')
			->with(self::ABONNEMENT_ID, ['channel' => 'zaken']);

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['received' => true], $response->getData());

	}//end testAValidKeyForThisAbonnementsConsumerIsAccepted()

	/**
	 * The credential is read from the header the ABONNEMENT names, not from a
	 * hardcoded `Authorization`. Decision 4 makes it configurable, and a
	 * callback whose key arrives in `X-NLX-Api-Key` must authenticate.
	 *
	 * @return void
	 */
	public function testTheConfiguredHeaderNameIsTheOneRead(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(
			$this->abonnement(
				[
					'consumerId' => self::CONSUMER_ID,
					'authHeaderName' => 'X-NLX-Api-Key',
				]
			)
		);
		$this->request->method('getParams')->willReturn([]);
		$this->authorizationService->method('getResolvedConsumer')->willReturn(
			$this->consumer(self::CONSUMER_ID)
		);

		$this->request->expects($this->once())
			->method('getHeader')
			->with('X-NLX-Api-Key')
			->willReturn('secret-key');

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testTheConfiguredHeaderNameIsTheOneRead()

	/**
	 * The configured scheme prefix is removed before the key is checked —
	 * `Bearer secret-key` must authenticate as `secret-key`, not as the whole
	 * header value.
	 *
	 * @return void
	 */
	public function testTheConfiguredSchemePrefixIsStrippedBeforeTheKeyIsChecked(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(
			$this->abonnement(
				[
					'consumerId' => self::CONSUMER_ID,
					'authScheme' => 'Bearer ',
				]
			)
		);
		$this->request->method('getHeader')->willReturn('Bearer secret-key');
		$this->request->method('getParams')->willReturn([]);
		$this->authorizationService->method('getResolvedConsumer')->willReturn(
			$this->consumer(self::CONSUMER_ID)
		);

		$this->authorizationService->expects($this->once())
			->method('authorizeApiKey')
			->with('secret-key', []);

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testTheConfiguredSchemePrefixIsStrippedBeforeTheKeyIsChecked()

	/**
	 * A key that does not authenticate is refused, and nothing is processed.
	 *
	 * @return void
	 */
	public function testAnInvalidKeyIsRefusedBeforeAnyProcessing(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(
			$this->abonnement(['consumerId' => self::CONSUMER_ID])
		);
		$this->request->method('getHeader')->willReturn('wrong-key');
		$this->request->method('getParams')->willReturn(['channel' => 'zaken']);
		$this->authorizationService->method('authorizeApiKey')
			->willThrowException(new AuthenticationException('no such key', []));

		$this->subscriberService->expects($this->never())->method('handleInboundNotification');

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnInvalidKeyIsRefusedBeforeAnyProcessing()

	/**
	 * REQ-002's literal text asks for *a* matching consumer. This endpoint
	 * additionally requires it to be *this abonnement's own* consumer, so a
	 * key that authenticates a DIFFERENT tenant's consumer is refused — which
	 * is the difference between an authenticated caller and an authorised one.
	 *
	 * @return void
	 */
	public function testAKeyBelongingToAnotherConsumerIsRefused(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(
			$this->abonnement(['consumerId' => self::CONSUMER_ID])
		);
		$this->request->method('getHeader')->willReturn('some-other-tenants-key');
		$this->request->method('getParams')->willReturn([]);
		$this->authorizationService->method('getResolvedConsumer')->willReturn(
			$this->consumer('cons-9999-0000')
		);

		$this->subscriberService->expects($this->never())->method('handleInboundNotification');

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAKeyBelongingToAnotherConsumerIsRefused()

	/**
	 * An abonnement with no companion consumer cannot authorise anyone. The
	 * cross-check must fail closed rather than treat an empty configured id
	 * as "no constraint".
	 *
	 * @return void
	 */
	public function testAnAbonnementWithNoConsumerBoundToItAuthorisesNobody(): void {
		$this->subscriberService->method('findAbonnement')->willReturn($this->abonnement([]));
		$this->request->method('getHeader')->willReturn('secret-key');
		$this->request->method('getParams')->willReturn([]);
		$this->authorizationService->method('getResolvedConsumer')->willReturn(
			$this->consumer(self::CONSUMER_ID)
		);

		$this->subscriberService->expects($this->never())->method('handleInboundNotification');

		$response = $this->controller()->callback(self::ABONNEMENT_ID);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnAbonnementWithNoConsumerBoundToItAuthorisesNobody()

	/**
	 * An unknown abonnement is refused with the same undifferentiated 401 as a
	 * bad key, so the response cannot be used to enumerate which abonnement
	 * ids exist.
	 *
	 * @return void
	 */
	public function testAnUnknownAbonnementIsRefusedWithoutRevealingThat(): void {
		$this->subscriberService->method('findAbonnement')->willReturn(null);

		$this->request->expects($this->never())->method('getHeader');
		$this->subscriberService->expects($this->never())->method('handleInboundNotification');

		$response = $this->controller()->callback('no-such-abonnement');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthorized'], $response->getData());

	}//end testAnUnknownAbonnementIsRefusedWithoutRevealingThat()
}//end class
