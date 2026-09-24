<?php

/**
 * Unit tests for OutboundLogController.
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OutboundLogController;
use OCA\Integriq\Outbound\ForwardService;
use OCA\Integriq\Outbound\LastContactQuery;
use OCA\Integriq\Outbound\MessageBodyReader;
use OCA\Integriq\Outbound\OutboundRetryService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests who is refused, which is what this controller mostly does.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002
 */
class OutboundLogControllerTest extends TestCase {

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
	 * The body reader double.
	 *
	 * @var MessageBodyReader|MockObject
	 */
	private $bodyReader;

	/**
	 * The retry service double.
	 *
	 * @var OutboundRetryService|MockObject
	 */
	private $retryService;

	/**
	 * The forward service double.
	 *
	 * @var ForwardService|MockObject
	 */
	private $forwardService;

	/**
	 * The last-contact query double.
	 *
	 * @var LastContactQuery|MockObject
	 */
	private $lastContactQuery;

	/**
	 * The controller under test.
	 *
	 * @var OutboundLogController
	 */
	private OutboundLogController $controller;

	/**
	 * Set up the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		$this->bodyReader = $this->getMockBuilder(MessageBodyReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
		$this->retryService = $this->getMockBuilder(OutboundRetryService::class)
			->disableOriginalConstructor()
			->onlyMethods(['retry', 'retryAll'])
			->getMock();
		$this->forwardService = $this->getMockBuilder(ForwardService::class)
			->disableOriginalConstructor()
			->onlyMethods(['forward'])
			->getMock();
		$this->lastContactQuery = $this->getMockBuilder(LastContactQuery::class)
			->disableOriginalConstructor()
			->onlyMethods(['lastContact'])
			->getMock();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new OutboundLogController(
			'integriq',
			$this->request,
			$this->userSession,
			$this->actionAuth,
			$this->bodyReader,
			$this->retryService,
			$this->forwardService,
			$this->lastContactQuery,
			$l10n,
		);

	}//end setUp()

	/**
	 * Nobody signed in reads no body.
	 *
	 * @return void
	 */
	public function testAnonymousReadsNoBody(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->bodyReader->expects($this->never())->method('read');

		$response = $this->controller->body('message-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousReadsNoBody()

	/**
	 * An account without the retry action retries nothing.
	 *
	 * @return void
	 */
	public function testAnOrdinaryAccountCannotRetry(): void {
		$this->signedInAs('burger');
		$this->actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'outbound.retry' requires admin rights")
		);
		$this->retryService->expects($this->never())->method('retryAll');

		$this->expectException(OCSForbiddenException::class);
		$this->controller->retry('message-1');

	}//end testAnOrdinaryAccountCannotRetry()

	/**
	 * A retry answers per item, so nothing is silently skipped.
	 *
	 * @return void
	 */
	public function testARetryAnswersPerItem(): void {
		$this->signedInAs('beheerder');
		$this->retryService->method('retryAll')->willReturn(
			[
				'succeeded' => 7,
				'failed' => 2,
				'items' => array_fill(0, 9, ['message' => 'm', 'succeeded' => true, 'detail' => '']),
			]
		);

		$response = $this->controller->retry('message-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(7, $response->getData()['succeeded']);
		$this->assertCount(9, $response->getData()['items']);

	}//end testARetryAnswersPerItem()

	/**
	 * A last-contact question needs both halves, and says so.
	 *
	 * @return void
	 */
	public function testLastContactNeedsBothSubjectAndRecipient(): void {
		$this->signedInAs('behandelaar');
		$this->request->method('getParam')->willReturn('');
		$this->lastContactQuery->expects($this->never())->method('lastContact');

		$response = $this->controller->lastContact();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLastContactNeedsBothSubjectAndRecipient()

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
