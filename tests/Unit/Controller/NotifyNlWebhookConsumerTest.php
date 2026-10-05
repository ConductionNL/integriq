<?php

/**
 * The NotifyNL status callback on the consumer model.
 *
 * Drives the real NotifyNlController and the real SmsDispatchService over
 * the connection world. A correctly signed status callback arrives WITHOUT a
 * session, as NotifyNL sends it. Before this change the controller read its
 * trust from the admin-only `sms` source with RBAC on, found nothing, and
 * answered 401 (proven live on 2026-10-04).
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
 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#requirement-the-status-callback-acts-as-the-notifynl-connections-account-req-020
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\NotifyNlController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Tests\Helpers\PhpInputStream;
use OCA\Integriq\Tests\Helpers\WebhookWorld;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A signed NotifyNL status callback is stored as the NotifyNL connection's account.
 */
class NotifyNlWebhookConsumerTest extends TestCase {
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
	private string $body = '{"providerMessageId":"nnl-1","status":"delivered","detail":"ok"}';

	/**
	 * A NotifyNL connection whose account may write sms messages; one sent message.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'notifynl-intake', grants: ['create', 'read', 'update']);
		$this->addWebhookConsumer(profile: WebhookProfiles::notifyNl(), userId: 'notifynl-intake');
		$this->addOther(
			schema: 'sms_message',
			uuid: 'sms-uuid-1',
			data: ['providerMessageId' => 'nnl-1', 'status' => 'sent', 'recipientMsisdn' => '+31600000000'],
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
	 * @param string $signature The signature header.
	 *
	 * @return NotifyNlController
	 */
	private function controller(string $signature): NotifyNlController {
		$service = new SmsDispatchService(
			objectService: $this->objectService,
			logProvider: $this->createMock(LogSmsProvider::class),
			notifyNlProvider: $this->createMock(RestNotifyNlProvider::class),
			eventService: $this->createMock(EventService::class),
			l: $this->webhookL10n(),
			logger: new NullLogger(),
			rawSourceResolver: $this->createMock(RawSourceResolver::class),
			gate: (new \OCA\Integriq\Tests\Helpers\OptOutFixture($this, $this->createMock(\OCP\IDBConnection::class)))->gate()
		);

		return new NotifyNlController(
			appName: 'integriq',
			request: $this->webhookRequest(body: $this->body, signature: $signature, params: (array)json_decode($this->body, true)),
			dispatchService: $service,
			gate: $this->buildWorldGate(objectService: $this->objectService),
			userSession: $this->createMock(IUserSession::class),
			actionAuth: $this->createMock(ActionAuthService::class),
			l: $this->webhookL10n(),
			logger: new NullLogger()
		);

	}//end controller()

	/**
	 * A signed callback without a session updates the message, as the connection's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#scenario-a-signed-status-callback-without-a-session-is-stored-as-the-connections-account
	 */
	public function testASignedCallbackIsStoredAsTheConnectionsAccount(): void {
		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['update by notifynl-intake'], $this->writesTo('sms_message'));
		$this->assertSame('delivered', $this->worldOthers['sms-uuid-1']['entity']->getObject()['status']);
		$this->assertNull($this->worldSession->getUser(), 'the account is restored after the delivery');

	}//end testASignedCallbackIsStoredAsTheConnectionsAccount()

	/**
	 * No connection at all: 503 and an alert, nothing written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#scenario-a-missing-connection-answers-503
	 */
	public function testNoConnectionAnswers503(): void {
		$this->worldConsumers = [];

		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('notifynl_connection_not_configured', $response->getData()['error']);
		$this->assertSame([], $this->writesTo('sms_message'));
		$this->assertSame(['notifynl/no_connection'], $this->webhookAlerts);

	}//end testNoConnectionAnswers503()

	/**
	 * An account without the update right is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#scenario-a-missing-connection-answers-503
	 */
	public function testAnAccountWithoutRightsAnswers503(): void {
		$this->addAccount(uid: 'notifynl-intake', grants: ['read']);

		$response = $this->controller(signature: $this->signWebhook($this->body))->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('notifynl_account_lacks_rights', $response->getData()['error']);
		$this->assertSame([], $this->writesTo('sms_message'));

	}//end testAnAccountWithoutRightsAnswers503()

	/**
	 * A wrong signature answers 401 and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notifynl-inbound-on-the-consumer-model/specs/notifynl-sms-channel/spec.md#scenario-a-wrong-signature-answers-401
	 */
	public function testAWrongSignatureAnswers401(): void {
		$response = $this->controller(signature: $this->signWebhook($this->body, secret: 'not-the-secret'))->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writesTo('sms_message'));

	}//end testAWrongSignatureAnswers401()
}//end class
