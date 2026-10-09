<?php

/**
 * The Peppol inbound webhook on the consumer model.
 *
 * Drives the real PeppolController and the real PeppolTransmissionService
 * over the connection world: a fake OpenRegister that honours RBAC and
 * records who wrote. A correctly signed callback arrives WITHOUT a session,
 * exactly as the access point sends it. Before this change the controller
 * looked its trust up in the admin-only `source` with RBAC on, found
 * nothing, and answered 401 (proven live on 2026-10-04).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#requirement-the-inbound-webhook-acts-as-the-peppol-connections-account-req-020
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\PeppolController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Peppol\LogPeppolAccessPointProvider;
use OCA\Integriq\Service\Peppol\RestPeppolAccessPointProvider;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Tests\Helpers\PhpInputStream;
use OCA\Integriq\Tests\Helpers\WebhookWorld;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A signed Peppol callback is stored as the Peppol connection's account.
 */
class PeppolWebhookConsumerTest extends TestCase {
	use WebhookWorld;

	/**
	 * The world's ObjectService.
	 *
	 * @var ORObjectService
	 */
	private ORObjectService $objectService;

	/**
	 * The callback body.
	 *
	 * @var string
	 */
	private string $body = '{"transmissionId":"tx-1","status":"delivered","detail":"ok"}';

	/**
	 * A Peppol connection whose account may write transmissions; one sent transmission.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'peppol-intake', grants: ['create', 'read', 'update']);
		$this->addWebhookConsumer(profile: WebhookProfiles::peppol(), userId: 'peppol-intake');
		$this->addOther(
			schema: 'peppol_transmission',
			uuid: 'tx-uuid-1',
			data: ['transmissionId' => 'tx-1', 'status' => 'sent', 'objectUri' => 'o', 'recipientPeppolId' => 'r', 'documentType' => 'd'],
			adminOnly: false
		);
		$this->objectService = $this->buildWorldObjectService();

	}//end setUp()

	/**
	 * Put php://input back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		PhpInputStream::restore();
		parent::tearDown();

	}//end tearDown()

	/**
	 * The real controller over the real service and the world.
	 *
	 * @param string            $signature The signature header.
	 * @param EventService|null $events    The event service, mocked unless given.
	 *
	 * @return PeppolController
	 */
	private function controller(string $signature, ?EventService $events = null): PeppolController {
		$service = new PeppolTransmissionService(
			objectService: $this->objectService,
			logProvider: $this->createMock(LogPeppolAccessPointProvider::class),
			restProvider: $this->createMock(RestPeppolAccessPointProvider::class),
			eventService: ($events ?? $this->createMock(EventService::class)),
			l: $this->webhookL10n(),
			logger: new NullLogger(),
			rootFolder: $this->createMock(IRootFolder::class)
		);

		return new PeppolController(
			appName: 'integriq',
			request: $this->webhookRequest(body: $this->body, signature: $signature, params: (array)json_decode($this->body, true)),
			transmissionService: $service,
			gate: $this->buildWorldGate(objectService: $this->objectService),
			userSession: $this->createMock(IUserSession::class),
			actionAuth: $this->createMock(ActionAuthService::class),
			l: $this->webhookL10n(),
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * A signed callback without a session updates the transmission, as the connection's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#scenario-a-signed-callback-without-a-session-is-stored-as-the-connections-account
	 */
	public function testASignedCallbackIsStoredAsTheConnectionsAccount(): void {
		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['update by peppol-intake'], $this->writesTo('peppol_transmission'));
		$this->assertSame('delivered', $this->worldOthers['tx-uuid-1']['entity']->getObject()['status']);
		$this->assertNull($this->worldSession->getUser(), 'the account is restored after the delivery');

	}//end testASignedCallbackIsStoredAsTheConnectionsAccount()

	/**
	 * Without an account the callback is refused with 503, nothing is written and the admins are alerted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#scenario-a-connection-without-a-usable-account-answers-503
	 */
	public function testAConnectionWithoutAnAccountAnswers503(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::peppol(), userId: '');

		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('peppol_account_unavailable', $response->getData()['error']);
		$this->assertSame([], $this->writesTo('peppol_transmission'));
		$this->assertSame(['peppol/no_account'], $this->webhookAlerts);

	}//end testAConnectionWithoutAnAccountAnswers503()

	/**
	 * A wrong signature answers 401 and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#scenario-a-wrong-signature-answers-401
	 */
	public function testAWrongSignatureAnswers401(): void {
		$response = $this->controller(signature: $this->signWebhook($this->body, secret: 'not-the-secret'))->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writesTo('peppol_transmission'));

	}//end testAWrongSignatureAnswers401()

	/**
	 * A write OpenRegister refuses answers 503 instead of a 200 that hides the loss.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-refused-write-is-answered-503
	 */
	public function testARefusedWriteAnswers503(): void {
		$this->worldRefuseWritesTo = ['peppol_transmission'];

		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('peppol_delivery_not_stored', $response->getData()['error']);
		$this->assertSame(['peppol/delivery_not_stored'], $this->webhookAlerts);

	}//end testARefusedWriteAnswers503()

	/**
	 * A signed inbound-document notification emits its event while the connection's account is active.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#scenario-a-signed-callback-without-a-session-is-stored-as-the-connections-account
	 */
	public function testAnInboundDocumentIsEmittedAsTheConnectionsAccount(): void {
		$this->body = '{"senderPeppolId":"0106:1","documentType":"invoice","payloadReference":"ap://doc-1"}';
		$actingDuringEmit = [];
		$events = $this->createMock(EventService::class);
		$events->expects($this->once())->method('emitCloudEvent')->willReturnCallback(
			function () use (&$actingDuringEmit): array {
				$actingDuringEmit[] = $this->worldSession->getUser()?->getUID();
				return [];
			}
		);

		$response = $this->controller(signature: $this->signWebhook($this->body), events: $events)->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['peppol-intake'], $actingDuringEmit);

	}//end testAnInboundDocumentIsEmittedAsTheConnectionsAccount()
}//end class
