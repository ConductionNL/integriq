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
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the public inbound leg, which is where the refusals live.
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
	 * The source resolver double.
	 *
	 * @var IntakeChannelSourceResolver|MockObject
	 */
	private $sourceResolver;

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
	 * @var WebhookSignatureService|MockObject
	 */
	private $signatureService;

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
		$this->sourceResolver = $this->getMockBuilder(IntakeChannelSourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['sourceFor', 'configurationFor'])
			->getMock();
		$this->routingService = $this->getMockBuilder(IntakeRoutingService::class)
			->disableOriginalConstructor()
			->onlyMethods(['route', 'validateRule'])
			->getMock();
		$this->replyService = $this->getMockBuilder(IntakeReplyService::class)
			->disableOriginalConstructor()
			->onlyMethods(['reply'])
			->getMock();
		$this->signatureService = $this->getMockBuilder(WebhookSignatureService::class)
			->disableOriginalConstructor()
			->onlyMethods(['verify'])
			->getMock();

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
					$this->sourceResolver,
					$this->routingService,
					$this->replyService,
					$this->signatureService,
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
		$this->sourceResolver->method('configurationFor')->willReturn(
			['webhookSignature' => ['secret' => 'shh', 'scheme' => 'openconnector']]
		);
		$this->signatureService->method('verify')->willReturn(false);
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
		$this->sourceResolver->method('configurationFor')->willReturn(
			['webhookSignature' => ['secret' => 'shh']]
		);
		$this->signatureService->method('verify')->willReturn(false);

		$this->controller->inbound('messaging');

		$this->assertCount(1, $this->saved);
		$this->assertSame('rejected', $this->saved[0]['status']);
		$this->assertSame('messaging', $this->saved[0]['channelId']);

	}//end testARefusedDeliveryIsRecorded()

	/**
	 * A channel with no source is refused too: no secret means nothing to
	 * verify against, and an unverifiable payload is not an accepted one.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredChannelIsRefused(): void {
		$this->sourceResolver->method('configurationFor')->willReturn(null);
		$this->signatureService->expects($this->never())->method('verify');

		$response = $this->controller->inbound('messaging');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('no configured channel source', $this->saved[0]['reason']);

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
		$this->sourceResolver->method('configurationFor')->willReturn(
			['webhookSignature' => ['secret' => 'shh']]
		);
		$this->signatureService->method('verify')->willReturn(true);

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

	public function testASignedDeliveryIsRouted(): void {
		$this->sourceResolver->method('configurationFor')->willReturn(
			['webhookSignature' => ['secret' => 'shh']]
		);
		$this->signatureService->method('verify')->willReturn(true);
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

}//end class
