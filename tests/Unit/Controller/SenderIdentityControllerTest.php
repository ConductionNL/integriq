<?php

/**
 * Unit tests for SenderIdentityController.
 *
 * The identity list, the withdraw leg, the confirm refusals and the admin
 * opt-out pages. The alignment check has its own contract test
 * (SenderIdentityAlignmentContractTest) and the link pages are exercised in
 * tests/Unit/Outbound/UnsubscribeLinkTest.php.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SenderIdentityController;
use OCA\Integriq\Outbound\Identity\DomainAlignmentCheckerInterface;
use OCA\Integriq\Outbound\Identity\HoldQueueInterface;
use OCA\Integriq\Outbound\Identity\OptOutCategoriesInterface;
use OCA\Integriq\Outbound\Identity\OptOutRegistryInterface;
use OCA\Integriq\Outbound\Identity\SenderIdentityServiceInterface;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SenderIdentityController: index, withdraw, unsubscribeConfirm refusals, optOutLog, optOuts.
 *
 * @covers \OCA\Integriq\Controller\SenderIdentityController
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class SenderIdentityControllerTest extends TestCase {

	/**
	 * The session double.
	 *
	 * @var IUserSession|MockObject
	 */
	private $userSession;

	/**
	 * The action gate double.
	 *
	 * @var ActionAuthServiceInterface|MockObject
	 */
	private $actionAuth;

	/**
	 * The identities double.
	 *
	 * @var SenderIdentityServiceInterface|MockObject
	 */
	private $identities;

	/**
	 * The token service double.
	 *
	 * @var UnsubscribeTokenServiceInterface|MockObject
	 */
	private $tokens;

	/**
	 * The opt-out registry double.
	 *
	 * @var OptOutRegistryInterface|MockObject
	 */
	private $optOuts;

	/**
	 * The hold queue double.
	 *
	 * @var HoldQueueInterface|MockObject
	 */
	private $holdQueue;

	/**
	 * The controller under test.
	 *
	 * @var SenderIdentityController
	 */
	private SenderIdentityController $controller;

	/**
	 * Build the controller over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->identities = $this->createMock(SenderIdentityServiceInterface::class);
		$this->tokens = $this->createMock(UnsubscribeTokenServiceInterface::class);
		$this->optOuts = $this->createMock(OptOutRegistryInterface::class);
		$this->holdQueue = $this->createMock(HoldQueueInterface::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$this->controller = new SenderIdentityController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->actionAuth,
			$this->identities,
			$this->createMock(DomainAlignmentCheckerInterface::class),
			$this->tokens,
			$this->optOuts,
			$this->holdQueue,
			$l,
			$this->createMock(IURLGenerator::class)
		);

	}//end setUp()

	/**
	 * Nobody signed in reads no identities, and the list is never fetched.
	 *
	 * @return void
	 */
	public function testAnonymousCannotListIdentities(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->identities->expects($this->never())->method('all');

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCannotListIdentities()

	/**
	 * A signed-in caller without the identities action is refused by the gate.
	 *
	 * @return void
	 */
	public function testListingIdentitiesIsGatedByTheIdentitiesAction(): void {
		$user = $this->signedIn();
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($user, SenderIdentityController::ACTION_IDENTITIES)
			->willThrowException(new OCSForbiddenException('not allowed'));
		$this->identities->expects($this->never())->method('all');

		$this->expectException(OCSForbiddenException::class);
		$this->controller->index();

	}//end testListingIdentitiesIsGatedByTheIdentitiesAction()

	/**
	 * Each identity is shaped for the screen: a complete one keeps its values,
	 * a sparse one gets the documented defaults, and each carries its envelope.
	 *
	 * @return void
	 */
	public function testIdentitiesAreListedWithDefaultsAndEnvelopes(): void {
		$this->signedIn();
		$full = [
			'displayName' => 'Gemeente Voorbeeld',
			'address' => 'post@voorbeeld.nl',
			'isDefault' => true,
			'quotingLevel' => 'none',
			'holdWindowSeconds' => '30',
			'alignment' => ['spf' => ['state' => 'aligned']],
		];
		$this->identities->method('all')->willReturn(
			[
				ObjectServiceMockBuilder::objectEntity($this, $full, 'id-1'),
				ObjectServiceMockBuilder::objectEntity($this, [], 'id-2'),
			]
		);
		$this->identities->expects($this->exactly(2))
			->method('envelopeFor')
			->willReturnCallback(
				static fn (array $identity): array => ['mailFrom' => ($identity['address'] ?? 'bounce@instance')]
			);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'identities' => [
					[
						'id' => 'id-1',
						'displayName' => 'Gemeente Voorbeeld',
						'address' => 'post@voorbeeld.nl',
						'isDefault' => true,
						'quotingLevel' => 'none',
						'holdWindowSeconds' => 30,
						'alignment' => ['spf' => ['state' => 'aligned']],
						'envelope' => ['mailFrom' => 'post@voorbeeld.nl'],
					],
					[
						'id' => 'id-2',
						'displayName' => '',
						'address' => '',
						'isDefault' => false,
						'quotingLevel' => SenderIdentityServiceInterface::QUOTING_LAST,
						'holdWindowSeconds' => 0,
						'alignment' => null,
						'envelope' => ['mailFrom' => 'bounce@instance'],
					],
				],
			],
			$response->getData()
		);

	}//end testIdentitiesAreListedWithDefaultsAndEnvelopes()

	/**
	 * Nobody signed in withdraws nothing.
	 *
	 * @return void
	 */
	public function testAnonymousCannotWithdraw(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->holdQueue->expects($this->never())->method('withdraw');

		$response = $this->controller->withdraw('msg-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnonymousCannotWithdraw()

	/**
	 * A message inside its window is withdrawn in the caller's name, under the
	 * withdraw action.
	 *
	 * @return void
	 */
	public function testAMessageInsideItsWindowIsWithdrawn(): void {
		$user = $this->signedIn();
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($user, SenderIdentityController::ACTION_WITHDRAW);
		$this->holdQueue->expects($this->once())
			->method('withdraw')
			->with('msg-1', 'anna')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'withdrawn'], 'msg-1'));

		$response = $this->controller->withdraw('msg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'msg-1', 'status' => 'withdrawn'], $response->getData());

	}//end testAMessageInsideItsWindowIsWithdrawn()

	/**
	 * Once the window has closed the answer is 409 with the reason, not a
	 * success that recalled nothing.
	 *
	 * @return void
	 */
	public function testAMessageWhoseWindowClosedIsAConflict(): void {
		$this->signedIn();
		$this->holdQueue->method('withdraw')->willThrowException(new RuntimeException('The hold window has closed.'));

		$response = $this->controller->withdraw('msg-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'The hold window has closed.'], $response->getData());

	}//end testAMessageWhoseWindowClosedIsAConflict()

	/**
	 * Confirming with an expired link writes nothing and answers 410.
	 *
	 * @return void
	 */
	public function testConfirmingAnExpiredLinkWritesNothing(): void {
		$this->tokens->method('inspect')->with('tok')->willReturn(
			$this->claim(status: UnsubscribeTokenServiceInterface::STATUS_EXPIRED)
		);
		$this->optOuts->expects($this->never())->method('record');
		$this->optOuts->expects($this->never())->method('add');

		$response = $this->controller->unsubscribeConfirm('tok', 'all');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_GONE, $response->getStatus());
		$this->assertSame('expired', $response->getParams()['state']);
		$this->assertFalse($response->getParams()['stopped']);

	}//end testConfirmingAnExpiredLinkWritesNothing()

	/**
	 * Confirming a list link records exactly what it names and says the list stops.
	 *
	 * @return void
	 */
	public function testConfirmingAListLinkStopsThatList(): void {
		$this->tokens->method('inspect')->willReturn(
			$this->claim(scope: OptOutRegistryInterface::SCOPE_LIST, ref: 'nieuwsbrief', purpose: OptOutCategoriesInterface::PURPOSE_SERVICE)
		);
		$this->optOuts->expects($this->once())
			->method('record')
			->with(
				[
					'address' => 'jan@example.nl',
					'state' => 'opted-out',
					'scope' => OptOutRegistryInterface::SCOPE_LIST,
					'channel' => 'email',
					'ref' => 'nieuwsbrief',
					'purpose' => OptOutCategoriesInterface::PURPOSE_SERVICE,
					'source' => 'unsubscribe-link',
					'sourceApp' => 'integriq',
				]
			);

		$response = $this->controller->unsubscribeConfirm('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('stopped', $response->getParams()['state']);
		$this->assertSame(
			'You will no longer receive messages from this list. Statutory notices, such as a besluit, are still sent.',
			$response->getParams()['message']
		);

	}//end testConfirmingAListLinkStopsThatList()

	/**
	 * The decision log passes the paging and the correlation filter through.
	 *
	 * @return void
	 */
	public function testTheDecisionLogIsPagedAndFiltered(): void {
		$page = ['results' => [['decision' => 'suppressed', 'correlationId' => 'c-1']]];
		$this->optOuts->expects($this->once())
			->method('logPage')
			->with(20, 40, 'c-1')
			->willReturn($page);

		$response = $this->controller->optOutLog(20, 40, 'c-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($page, $response->getData());

	}//end testTheDecisionLogIsPagedAndFiltered()

	/**
	 * The opt-out list passes the paging through and answers the registry's page.
	 *
	 * @return void
	 */
	public function testTheOptOutListIsPaged(): void {
		$page = ['results' => [['address' => 'jan@example.nl']], 'total' => 1];
		$this->optOuts->expects($this->once())->method('page')->with(10, 0)->willReturn($page);

		$response = $this->controller->optOuts(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($page, $response->getData());

	}//end testTheOptOutListIsPaged()

	/**
	 * Put a user named anna in the session.
	 *
	 * @return IUser The user.
	 */
	private function signedIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->userSession->method('getUser')->willReturn($user);

		return $user;

	}//end signedIn()

	/**
	 * A v3 token claim.
	 *
	 * @param string $status  The verification status.
	 * @param string $scope   The scope the link names.
	 * @param string $ref     The reference the link names.
	 * @param string $purpose The purpose the link names.
	 *
	 * @return array<string,string> The claim.
	 */
	private function claim(
		string $status = UnsubscribeTokenServiceInterface::STATUS_VALID,
		string $scope = OptOutRegistryInterface::SCOPE_CASE,
		string $ref = '',
		string $purpose = '',
	): array {
		return [
			'status' => $status,
			'format' => UnsubscribeTokenServiceInterface::PREFIX_V3,
			'address' => 'jan@example.nl',
			'caseRef' => '',
			'scope' => $scope,
			'channel' => 'email',
			'ref' => $ref,
			'purpose' => $purpose,
		];

	}//end claim()
}//end class
