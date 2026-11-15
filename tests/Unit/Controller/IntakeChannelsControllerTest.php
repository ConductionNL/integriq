<?php

/**
 * Unit tests for IntakeChannelsController.
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IntakeChannelsController;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Intake\IntakeRoutingServiceInterface;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the public inbound leg, which is where the refusals live.
 *
 * @covers \OCA\Integriq\Controller\IntakeChannelsController
 * @uses   \OCA\Integriq\Intake\InboundMessage
 * @uses   \OCA\Integriq\Intake\ReplyResult
 * @uses   \OCA\Integriq\Service\Dso\DsoIdentity
 * @uses   \OCA\Integriq\Exception\IntakeChannelException
 * @uses   \OCA\Integriq\Exception\IntakeRoutingException
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
 */
class IntakeChannelsControllerTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest|MockObject
	 */
	private $request;

	/**
	 * The session double.
	 *
	 * @var IUserSession|MockObject
	 */
	private $userSession;

	/**
	 * The action gate double.
	 *
	 * @var ActionAuthService|MockObject
	 */
	private $actionAuth;

	/**
	 * The channel registry double.
	 *
	 * @var IntakeChannelRegistry|MockObject
	 */
	private $registry;

	/**
	 * The routing service double.
	 *
	 * @var IntakeRoutingService|MockObject
	 */
	private $routingService;

	/**
	 * The reply service double.
	 *
	 * @var IntakeReplyService|MockObject
	 */
	private $replyService;

	/**
	 * The signature verifier double.
	 *
	 * @var WebhookGate|MockObject
	 */
	private $gate;

	/**
	 * The OR object service double.
	 *
	 * @var OrObjectService|MockObject
	 */
	private $objectService;

	/**
	 * Everything the controller stored.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The controller under test, with the raw body under the test's control.
	 *
	 * @var IntakeChannelsController|MockObject
	 */
	private $controller;

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		$this->registry = $this->getMockBuilder(IntakeChannelRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['get', 'has', 'describeAll'])
			->getMock();
		$this->routingService = $this->getMockBuilder(IntakeRoutingService::class)
			->disableOriginalConstructor()
			->onlyMethods(['route', 'validateRule'])
			->getMock();
		$this->replyService = $this->getMockBuilder(IntakeReplyService::class)
			->disableOriginalConstructor()
			->onlyMethods(['reply'])
			->getMock();
		$this->gate = $this->createMock(WebhookGate::class);

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'saved-uuid');
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// getRawContent() reads php://input, which a unit test cannot fill, so
		// it is the one method stubbed on the controller itself.
		$this->controller = $this->getMockBuilder(IntakeChannelsController::class)
			->setConstructorArgs(
				[
					'integriq',
					$this->request,
					$this->userSession,
					$this->actionAuth,
					$this->registry,
					$this->routingService,
					$this->replyService,
					$this->gate,
					$this->objectService,
					$l10n,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$this->controller->method('getRawContent')->willReturn('{"messageId":"WA-1"}');

	}//end setUp()

	/**
	 * An unsigned delivery is refused before the payload is ever read: the
	 * adapter is never asked to parse it.
	 *
	 * @return void
	 */
	public function testAnUnsignedDeliveryIsRefusedBeforeTheBodyIsRead(): void {
		$this->signatureRefused();
		$this->registry->expects($this->never())->method('get');
		$this->routingService->expects($this->never())->method('route');

		$response = $this->controller->inbound('messaging');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testAnUnsignedDeliveryIsRefusedBeforeTheBodyIsRead()

	/**
	 * The refusal is recorded, so a sender with a stale secret is visible
	 * rather than merely quiet.
	 *
	 * @return void
	 */
	public function testARefusedDeliveryIsRecorded(): void {
		$this->signatureRefused();

		$this->controller->inbound('messaging');

		$this->assertCount(1, $this->saved);
		$this->assertSame('rejected', $this->saved[0]['status']);
		$this->assertSame('messaging', $this->saved[0]['channelId']);

	}//end testARefusedDeliveryIsRecorded()

	/**
	 * A channel with no connection answers 503 (the gate's answer), and the
	 * adapter never sees the payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-on-the-consumer-model/specs/intake-channels/spec.md#scenario-each-channel-has-its-own-connection
	 */
	public function testAnUnconfiguredChannelIsRefused(): void {
		$this->gate->method('identify')->willReturn(
			new JSONResponse(['error' => 'intakemessaging_connection_not_configured'], Http::STATUS_SERVICE_UNAVAILABLE)
		);
		$this->registry->expects($this->never())->method('get');

		$response = $this->controller->inbound('messaging');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame([], $this->saved);

	}//end testAnUnconfiguredChannelIsRefused()

	/**
	 * A correctly signed delivery is routed, and the answer says what happened.
	 *
	 * @return void
	 */
	/**
	 * The adapter is handed the SIGNED BYTES, not the framework's merged params.
	 *
	 * Nextcloud builds `getParams()` as
	 * `array_merge($get, $post, $urlParams, $params)` with the JSON body merged
	 * last (`Request.php:123`, `:412`). Body keys therefore win a collision, but
	 * a key ABSENT from the signed body could be injected through the query
	 * string and reached the adapter — which stores what it is given verbatim as
	 * `rawPayload`, and for Teams that payload later names an outbound
	 * destination carrying a bearer token.
	 *
	 * So the signature covered the body while the action ran on something else.
	 * Asserting on what `receive()` actually gets, because that is the desync.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public function testTheAdapterOnlyEverSeesTheSignedBody(): void {
		$this->deliveryAllowed();

		// The merged set the framework would hand over: the signed body plus a
		// key that was never signed, as a query string supplies it.
		$this->request->method('getParams')->willReturn(
			[
				'messageId' => 'WA-1',
				'serviceUrl' => 'https://attacker.example',
			]
		);

		$seen = null;
		$adapter = $this->createMock(\OCA\Integriq\Intake\IntakeChannelAdapterInterface::class);
		$adapter->method('receive')->willReturnCallback(
			function (array $payload) use (&$seen) {
				$seen = $payload;
				return new \OCA\Integriq\Intake\InboundMessage('messaging', 'WA-1', [], 'Hallo');
			}
		);
		$this->registry->method('get')->willReturn($adapter);

		$this->routingService->method('route')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'routed', 'targetRef' => 'zaak/1', 'reason' => ''],
				'intake-uuid'
			)
		);

		$this->controller->inbound('messaging');

		$this->assertSame(
			['messageId' => 'WA-1'],
			$seen,
			'The adapter must receive the decoded signed body, with no unsigned key merged in.'
		);
		$this->assertArrayNotHasKey('serviceUrl', (array)$seen);

	}//end testTheAdapterOnlyEverSeesTheSignedBody()

	/**
	 * A form-encoded sender still works, decoded from the same signed bytes.
	 *
	 * The fix for the params desync must not turn a signature bug into a broken
	 * integration: a sender that posts `application/x-www-form-urlencoded` is
	 * still signed over its body, so the body is what is parsed — with
	 * `parse_str`, not with the framework's merged parameter set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
	 */
	public function testAFormEncodedBodyIsDecodedFromTheSignedBytes(): void {
		$controller = $this->controllerWithRawBody(rawBody: 'messageId=WA-2&text=hallo');

		$this->deliveryAllowed();
		$this->request->method('getParams')->willReturn(['injected' => 'no']);

		$seen = null;
		$adapter = $this->createMock(\OCA\Integriq\Intake\IntakeChannelAdapterInterface::class);
		$adapter->method('receive')->willReturnCallback(
			function (array $payload) use (&$seen) {
				$seen = $payload;
				return new \OCA\Integriq\Intake\InboundMessage('messaging', 'WA-2', [], 'hallo');
			}
		);
		$this->registry->method('get')->willReturn($adapter);
		$this->routingService->method('route')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'routed', 'targetRef' => 'zaak/1', 'reason' => ''],
				'intake-uuid'
			)
		);

		$controller->inbound('messaging');

		$this->assertSame(['messageId' => 'WA-2', 'text' => 'hallo'], $seen);

	}//end testAFormEncodedBodyIsDecodedFromTheSignedBytes()

	/**
	 * A controller whose raw body is the one this test wants.
	 *
	 * `getRawContent()` reads php://input, which a unit test cannot fill, so it
	 * is stubbed — the same seam setUp() uses, with a per-test body.
	 *
	 * @param string $rawBody The body bytes.
	 *
	 * @return IntakeChannelsController The controller.
	 */
	private function controllerWithRawBody(string $rawBody): IntakeChannelsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = $this->getMockBuilder(IntakeChannelsController::class)
			->setConstructorArgs(
				[
					'integriq',
					$this->request,
					$this->userSession,
					$this->actionAuth,
					$this->registry,
					$this->routingService,
					$this->replyService,
					$this->gate,
					$this->objectService,
					$l10n,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$controller->method('getRawContent')->willReturn($rawBody);

		return $controller;

	}//end controllerWithRawBody()

	public function testASignedDeliveryIsRouted(): void {
		$this->deliveryAllowed();
		$this->request->method('getParams')->willReturn(['messageId' => 'WA-1']);

		$adapter = $this->createMock(\OCA\Integriq\Intake\IntakeChannelAdapterInterface::class);
		$message = new \OCA\Integriq\Intake\InboundMessage('messaging', 'WA-1', ['phone' => '+316'], 'Hallo');
		$adapter->method('receive')->willReturn($message);
		$this->registry->method('get')->willReturn($adapter);

		$this->routingService->method('route')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'routed', 'targetRef' => 'zaak/1', 'reason' => ''],
				'intake-uuid'
			)
		);

		$response = $this->controller->inbound('messaging');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame('routed', $response->getData()['status']);
		$this->assertSame('zaak/1', $response->getData()['targetRef']);

	}//end testASignedDeliveryIsRouted()

	/**
	 * A rule whose mapping the case type cannot hold is refused at save,
	 * naming the field, and nothing is stored.
	 *
	 * @return void
	 */
	public function testARuleWithABadMappingIsRefusedAtSave(): void {
		$this->signedInAs('admin');
		$this->request->method('getParams')->willReturn(
			[
				'name' => 'Meldingen',
				'channelId' => 'public-space-report',
				'targetSchema' => 'melding_openbare_ruimte',
				'fieldMapping' => ['spoedeisend' => 'fields.category'],
			]
		);
		$this->routingService->method('validateRule')->willThrowException(
			new IntakeRoutingException('Case type "melding_openbare_ruimte" has no field "spoedeisend".')
		);

		$response = $this->controller->saveRule();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('spoedeisend', $response->getData()['error']);
		$this->assertSame([], $this->saved);

	}//end testARuleWithABadMappingIsRefusedAtSave()

	/**
	 * An account without the rules action cannot change routing, which is a
	 * decision about what opens a case.
	 *
	 * @return void
	 */
	public function testAnOrdinaryAccountCannotSaveARule(): void {
		$this->signedInAs('burger');
		$this->actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'intake.rules' requires admin rights")
		);
		$this->routingService->expects($this->never())->method('validateRule');

		$this->expectException(OCSForbiddenException::class);
		$this->controller->saveRule();

	}//end testAnOrdinaryAccountCannotSaveARule()

	/**
	 * A reply on a channel that cannot carry one reports it, and the answer
	 * says so rather than claiming a send.
	 *
	 * @return void
	 */
	public function testAReplyOnAOneWayChannelSaysUnsupported(): void {
		$this->signedInAs('admin');
		$this->request->method('getParam')->willReturn('Dank voor uw melding.');
		$this->replyService->method('reply')->willReturn(
			ReplyResult::unsupported('public-space-report', 'no reply leg')
		);

		$response = $this->controller->reply('intake-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(ReplyResult::STATUS_UNSUPPORTED, $response->getData()['status']);

	}//end testAReplyOnAOneWayChannelSaysUnsupported()

	/**
	 * Nobody signed in reads no channel list.
	 *
	 * @return void
	 */
	public function testAnonymousCannotReadTheChannelList(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->registry->expects($this->never())->method('describeAll');

		$response = $this->controller->channels();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousCannotReadTheChannelList()

	/**
	 * A payload the adapter cannot read is the sender's fault: 400 with the
	 * adapter's reason, and nothing is routed.
	 *
	 * @return void
	 */
	public function testAPayloadTheAdapterRejectsIsABadRequest(): void {
		$this->deliveryAllowed();
		$adapter = $this->createMock(IntakeChannelAdapterInterface::class);
		$adapter->method('receive')->willThrowException(new IntakeChannelException('Payload has no messageId.'));
		$this->registry->method('get')->willReturn($adapter);
		$this->routingService->expects($this->never())->method('route');
		$this->gate->expects($this->never())->method('notStored');

		$response = $this->controller->inbound('messaging');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Payload has no messageId.'], $response->getData());

	}//end testAPayloadTheAdapterRejectsIsABadRequest()

	/**
	 * A write the account was refused is handed to the gate's notStored(),
	 * whose 503 makes the channel deliver again.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsAnsweredByTheGateSoTheChannelRetries(): void {
		$this->deliveryAllowed();
		$adapter = $this->createMock(IntakeChannelAdapterInterface::class);
		$adapter->method('receive')->willReturn(new InboundMessage('messaging', 'WA-1', [], 'Hallo'));
		$this->registry->method('get')->willReturn($adapter);
		$this->routingService->method('route')->willThrowException(new RuntimeException('write refused'));

		$retry = new JSONResponse(['error' => 'not stored'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())
			->method('notStored')
			->with(WebhookProfilesInterface::INTAKE_CHANNEL_PREFIX . 'messaging', 'write refused')
			->willReturn($retry);

		$response = $this->controller->inbound('messaging');

		$this->assertSame($retry, $response);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testAFailedWriteIsAnsweredByTheGateSoTheChannelRetries()

	/**
	 * A storage failure while recording a refusal does not turn the refusal
	 * into anything else: still 401, still the undifferentiated message.
	 *
	 * @return void
	 */
	public function testARefusalStaysARefusalWhenItsRecordCannotBeStored(): void {
		$this->signatureRefused();
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				[
					'channelId' => 'messaging',
					'externalId' => '',
					'status' => 'rejected',
					'reason' => 'invalid signature',
				],
				IntakeRoutingServiceInterface::REGISTER,
				IntakeRoutingServiceInterface::SCHEMA_MESSAGE
			)
			->willThrowException(new RuntimeException('database down'));

		$l10n = $this->createMock(IL10N::class);
		$controller = $this->getMockBuilder(IntakeChannelsController::class)
			->setConstructorArgs(
				[
					'integriq',
					$this->request,
					$this->userSession,
					$this->actionAuth,
					$this->registry,
					$this->routingService,
					$this->replyService,
					$this->gate,
					$objectService,
					$l10n,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$controller->method('getRawContent')->willReturn('{}');

		$response = $controller->inbound('messaging');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'invalid signature'], $response->getData());

	}//end testARefusalStaysARefusalWhenItsRecordCannotBeStored()

	/**
	 * Without the seam, the controller reads php://input itself; under the CLI
	 * that is empty, and the empty string is what the gate is asked to verify.
	 *
	 * @return void
	 */
	public function testTheRealRawBodyReaderHandsTheGateTheInputBytes(): void {
		$controller = new IntakeChannelsController(
			'integriq',
			$this->request,
			$this->userSession,
			$this->actionAuth,
			$this->registry,
			$this->routingService,
			$this->replyService,
			$this->gate,
			$this->objectService,
			$this->createMock(IL10N::class)
		);
		$unconfigured = new JSONResponse(['error' => 'not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())
			->method('identify')
			->with(WebhookProfilesInterface::INTAKE_CHANNEL_PREFIX . 'sms', '', $this->request)
			->willReturn($unconfigured);

		$this->assertSame($unconfigured, $controller->inbound('sms'));

	}//end testTheRealRawBodyReaderHandsTheGateTheInputBytes()

	/**
	 * A signed-in account reads what every channel can do.
	 *
	 * @return void
	 */
	public function testASignedInAccountReadsTheChannelList(): void {
		$this->signedInAs('anna');
		$described = [['id' => 'messaging', 'canReply' => true]];
		$this->registry->expects($this->once())->method('describeAll')->willReturn($described);

		$response = $this->controller->channels();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['channels' => $described], $response->getData());

	}//end testASignedInAccountReadsTheChannelList()

	/**
	 * Nobody signed in saves no rule, and the action gate is not even asked.
	 *
	 * @return void
	 */
	public function testAnonymousCannotSaveARule(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller->saveRule();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		$this->assertSame([], $this->saved);

	}//end testAnonymousCannotSaveARule()

	/**
	 * A new rule is created (201) without a uuid, and the route bookkeeping
	 * the framework adds to the params is not stored with it.
	 *
	 * @return void
	 */
	public function testANewRuleIsCreated(): void {
		$this->signedInAs('admin');
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), IntakeChannelsController::ACTION_RULES);
		$this->request->method('getParams')->willReturn(
			['id' => 'ignored', '_route' => 'integriq.intakeChannels.saveRule', 'name' => 'Meldingen', 'channelId' => 'messaging']
		);
		$this->routingService->expects($this->once())
			->method('validateRule')
			->with(['name' => 'Meldingen', 'channelId' => 'messaging'], $this->registry);

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				['name' => 'Meldingen', 'channelId' => 'messaging'],
				IntakeRoutingServiceInterface::REGISTER,
				IntakeRoutingServiceInterface::SCHEMA_RULE,
				null
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Meldingen'], 'rule-new'));

		$response = $this->controllerOver(objectService: $objectService)->saveRule();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'rule-new', 'rule' => ['name' => 'Meldingen']], $response->getData());

	}//end testANewRuleIsCreated()

	/**
	 * An existing rule is saved under its own uuid and answers 200.
	 *
	 * @return void
	 */
	public function testAnExistingRuleIsUpdatedInPlace(): void {
		$this->signedInAs('admin');
		$this->request->method('getParams')->willReturn(['name' => 'Meldingen v2']);

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				['name' => 'Meldingen v2'],
				IntakeRoutingServiceInterface::REGISTER,
				IntakeRoutingServiceInterface::SCHEMA_RULE,
				'rule-7'
			)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Meldingen v2'], 'rule-7'));

		$response = $this->controllerOver(objectService: $objectService)->saveRule('rule-7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('rule-7', $response->getData()['id']);
		$this->assertSame(['name' => 'Meldingen v2'], $response->getData()['rule']);

	}//end testAnExistingRuleIsUpdatedInPlace()

	/**
	 * Nobody signed in replies to nobody.
	 *
	 * @return void
	 */
	public function testAnonymousCannotReply(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->replyService->expects($this->never())->method('reply');

		$response = $this->controller->reply('intake-uuid');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCannotReply()

	/**
	 * A reply of only whitespace is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testAnEmptyReplyIsRefused(): void {
		$this->signedInAs('admin');
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), IntakeChannelsController::ACTION_REPLY);
		$this->request->method('getParam')->with('text', '')->willReturn("  \n ");
		$this->replyService->expects($this->never())->method('reply');

		$response = $this->controller->reply('intake-uuid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A reply needs something to say.'], $response->getData());

	}//end testAnEmptyReplyIsRefused()

	/**
	 * A reply to a message that does not exist is a 404 carrying the reason.
	 *
	 * @return void
	 */
	public function testAReplyToAnUnknownMessageIsNotFound(): void {
		$this->signedInAs('admin');
		$this->request->method('getParam')->willReturn('Dank u.');
		$this->replyService->expects($this->once())
			->method('reply')
			->with('missing-uuid', 'Dank u.')
			->willThrowException(new IntakeChannelException('No intake message "missing-uuid".'));

		$response = $this->controller->reply('missing-uuid');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No intake message "missing-uuid".'], $response->getData());

	}//end testAReplyToAnUnknownMessageIsNotFound()

	/**
	 * A controller over a given object service, raw body stubbed as in setUp().
	 *
	 * @param MockObject $objectService The object service double.
	 *
	 * @return IntakeChannelsController The controller.
	 */
	private function controllerOver(MockObject $objectService): IntakeChannelsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = $this->getMockBuilder(IntakeChannelsController::class)
			->setConstructorArgs(
				[
					'integriq',
					$this->request,
					$this->userSession,
					$this->actionAuth,
					$this->registry,
					$this->routingService,
					$this->replyService,
					$this->gate,
					$objectService,
					$l10n,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$controller->method('getRawContent')->willReturn('{}');

		return $controller;

	}//end controllerOver()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The account id.
	 *
	 * @return void
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signedInAs()

	/**
	 * The gate refuses the signature.
	 *
	 * @return void
	 */
	private function signatureRefused(): void {
		$this->gate->method('identify')->willReturn(new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED));

	}//end signatureRefused()

	/**
	 * The gate accepts the delivery and runs the work.
	 *
	 * @return void
	 */
	private function deliveryAllowed(): void {
		$this->gate->method('identify')->willReturn(new DsoIdentity(account: $this->createMock(IUser::class), consumerUuid: 'consumer-1'));
		$this->gate->method('deliver')->willReturnCallback(
			static fn (DsoIdentity $identity, callable $operation): mixed => $operation()
		);

	}//end deliveryAllowed()
}//end class
