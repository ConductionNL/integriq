<?php

/**
 * The verdicts webhook and the intake channels on the consumer model.
 *
 * Drives the real VerdictController and IntakeChannelsController, with the
 * real VerdictService and IntakeRoutingService, over the connection world. A
 * correctly signed delivery arrives WITHOUT a session. Before this change
 * both read their trust from an admin-only `intake-channel` source with RBAC
 * on, found nothing, and answered 401 (proven live on 2026-10-04).
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
 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#requirement-a-channel-acts-as-its-connections-account-req-ic-020
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IntakeChannelsController;
use OCA\Integriq\Controller\VerdictController;
use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Outbound\Call\VerdictService;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Tests\Helpers\PhpInputStream;
use OCA\Integriq\Tests\Helpers\WebhookWorld;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verdicts and channel messages are stored as their connection's account.
 */
class IntakeWebhooksConsumerTest extends TestCase {
	use WebhookWorld;

	/**
	 * The world's ObjectService.
	 *
	 * @var ORObjectService
	 */
	private ORObjectService $objectService;

	/**
	 * A fresh world with an account that may write verdicts and intake messages.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resetWorld();
		$this->addAccount(uid: 'channel-intake', grants: ['create', 'read', 'update']);
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
	 * A signed verdict without a session is stored as the verdicts connection's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-a-signed-delivery-without-a-session-is-stored-as-the-channels-account
	 */
	public function testASignedVerdictIsStoredAsTheConnectionsAccount(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'verdicts'), userId: 'channel-intake');
		$body = '{"objectRef":"integriq/peppol_transmission/t-1","state":"pass","source":"checker","reason":"ok"}';

		$response = $this->verdictController(body: $body, signature: $this->signWebhook($body))->inbound();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['create by channel-intake'], $this->writesTo('verdict'));
		$this->assertNull($this->worldSession->getUser());

	}//end testASignedVerdictIsStoredAsTheConnectionsAccount()

	/**
	 * A verdict connection whose account cannot create verdicts answers 503 and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-an-account-without-the-create-right-answers-503
	 */
	public function testAVerdictAccountWithoutCreateAnswers503(): void {
		$this->addAccount(uid: 'channel-intake', grants: ['read']);
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'verdicts'), userId: 'channel-intake');
		$body = '{"objectRef":"o","state":"pass","source":"checker"}';

		$response = $this->verdictController(body: $body, signature: $this->signWebhook($body))->inbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('intakeverdicts_account_lacks_rights', $response->getData()['error']);
		$this->assertSame([], $this->worldWrites);

	}//end testAVerdictAccountWithoutCreateAnswers503()

	/**
	 * A signed form submission without a session is stored as the channel connection's account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-a-signed-delivery-without-a-session-is-stored-as-the-channels-account
	 */
	public function testASignedFormSubmissionIsStoredAsTheConnectionsAccount(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'form-submission'), userId: 'channel-intake');
		$body = '{"submissionId":"s-1","formId":"melding","data":{"omschrijving":"Losse tegel"},'
			. '"submitter":{"bsn":"999999990","name":"Test","email":"t@example.invalid"},"summary":"Losse tegel"}';

		$response = $this->channelsController(body: $body, signature: $this->signWebhook($body))->inbound(channel: 'form-submission');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$writes = $this->writesTo('intake_message');
		$this->assertNotSame([], $writes);
		$this->assertSame([], array_values(array_filter($writes, static fn (string $write): bool => str_ends_with($write, ' by channel-intake') === false)));

	}//end testASignedFormSubmissionIsStoredAsTheConnectionsAccount()

	/**
	 * Each channel has its own connection: the verdicts consumer does not open the form channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-each-channel-has-its-own-connection
	 */
	public function testEachChannelHasItsOwnConnection(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'verdicts'), userId: 'channel-intake');
		$body = '{"submissionId":"s-2","formId":"melding","data":{}}';

		$response = $this->channelsController(body: $body, signature: $this->signWebhook($body))->inbound(channel: 'form-submission');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('intakeformsubmission_connection_not_configured', $response->getData()['error']);
		$this->assertSame([], $this->writesTo('intake_message'));

	}//end testEachChannelHasItsOwnConnection()

	/**
	 * A wrong signature on a channel answers 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-a-wrong-signature-answers-401
	 */
	public function testAWrongSignatureAnswers401(): void {
		$this->addWebhookConsumer(profile: WebhookProfiles::intakeChannel(channelId: 'form-submission'), userId: 'channel-intake');
		$body = '{"submissionId":"s-3","formId":"melding","data":{}}';

		$response = $this->channelsController(body: $body, signature: $this->signWebhook($body, secret: 'nope'))->inbound(channel: 'form-submission');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writesTo('intake_message'), 'the anonymous rejection record is refused too; nothing is stored');

	}//end testAWrongSignatureAnswers401()

	/**
	 * The real verdicts controller over the world.
	 *
	 * @param string $body      The raw body.
	 * @param string $signature The signature header.
	 *
	 * @return VerdictController
	 */
	private function verdictController(string $body, string $signature): VerdictController {
		return new VerdictController(
			appName: 'integriq',
			request: $this->webhookRequest(body: $body, signature: $signature, params: (array)json_decode($body, true)),
			userSession: $this->createMock(IUserSession::class),
			verdicts: new VerdictService(objectService: $this->objectService),
			gate: $this->buildWorldGate(objectService: $this->objectService),
			l: $this->webhookL10n()
		);

	}//end verdictController()

	/**
	 * The real intake channels controller over the world, with the form-submission adapter.
	 *
	 * @param string $body      The raw body.
	 * @param string $signature The signature header.
	 *
	 * @return IntakeChannelsController
	 */
	private function channelsController(string $body, string $signature): IntakeChannelsController {
		$registry = new IntakeChannelRegistry(logger: new NullLogger(), adapters: [new FormSubmissionAdapter()]);

		return new IntakeChannelsController(
			appName: 'integriq',
			request: $this->webhookRequest(body: $body, signature: $signature),
			userSession: $this->createMock(IUserSession::class),
			actionAuth: $this->createMock(ActionAuthService::class),
			registry: $registry,
			routingService: new IntakeRoutingService(
				objectService: $this->objectService,
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: new NullLogger()
			),
			replyService: new IntakeReplyService(objectService: $this->objectService, registry: $registry),
			gate: $this->buildWorldGate(objectService: $this->objectService),
			orObjectService: $this->objectService,
			l: $this->webhookL10n()
		);

	}//end channelsController()
}//end class
