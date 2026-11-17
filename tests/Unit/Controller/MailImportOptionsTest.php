<?php

/**
 * Unit tests for MailIntakeController::importOptions().
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
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MailIntakeController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The inbox asks whether to offer "Import file" and under which mailbox.
 *
 * @covers \OCA\Integriq\Controller\MailIntakeController
 *
 * @spec openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MailImportOptionsTest extends TestCase {

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
	 * The OpenRegister object service double.
	 *
	 * @var OrObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The controller under test.
	 *
	 * @var MailIntakeController
	 */
	private MailIntakeController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['can', 'requireAction'])
			->getMock();
		$this->objectService = ObjectServiceMockBuilder::make($this);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new MailIntakeController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->actionAuth,
			$this->objectService,
			$this->createMock(MessageParser::class),
			$this->createMock(MailIntakeService::class),
			$this->createMock(MailboxSourceHandler::class),
			$l10n,
		);

	}//end setUp()

	/**
	 * Nobody signed in learns nothing.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->importOptions();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsRefused()

	/**
	 * A user without the import right is told so and sees no mailboxes.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheImportRightIsNotOfferedTheAction(): void {
		$user = $this->signedInAs('burger');
		$this->actionAuth->expects($this->once())->method('can')
			->with($user, MailIntakeController::ACTION_IMPORT)
			->willReturn(false);
		$this->objectService->expects($this->never())->method('findAll');

		$response = $this->controller->importOptions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['allowed' => false, 'mailboxes' => []], $response->getData());
	}//end testAUserWithoutTheImportRightIsNotOfferedTheAction()

	/**
	 * A user with the right gets the mailbox sources by name, and only mailboxes.
	 *
	 * @return void
	 */
	public function testAUserWithTheImportRightGetsTheMailboxesByName(): void {
		$this->signedInAs('admin');
		$this->actionAuth->method('can')->willReturn(true);
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'source', 'type' => 'mailbox']])
			->willReturn(
				[
					ObjectServiceMockBuilder::objectEntity($this, ['type' => 'mailbox', 'name' => 'Zaken postbus'], 'src-2'),
					ObjectServiceMockBuilder::objectEntity($this, ['type' => 'api', 'name' => 'Een API'], 'src-3'),
					['type' => 'Mailbox', 'name' => 'Algemene postbus', 'id' => 'src-1'],
					['type' => 'mailbox', 'name' => 'Zonder id'],
				]
			);

		$response = $this->controller->importOptions();

		$this->assertSame(
			[
				'allowed'   => true,
				'mailboxes' => [
					['id' => 'src-1', 'name' => 'Algemene postbus'],
					['id' => 'src-2', 'name' => 'Zaken postbus'],
				],
			],
			$response->getData()
		);
	}//end testAUserWithTheImportRightGetsTheMailboxesByName()

	/**
	 * A list wrapped in `results` is read the same way.
	 *
	 * @return void
	 */
	public function testAResultsEnvelopeIsUnwrapped(): void {
		$this->signedInAs('admin');
		$this->actionAuth->method('can')->willReturn(true);
		$this->objectService->method('findAll')->willReturn(
			['results' => [['type' => 'mailbox', 'uuid' => 'src-9']], 'total' => 1]
		);

		$response = $this->controller->importOptions();

		$this->assertSame([['id' => 'src-9', 'name' => 'src-9']], $response->getData()['mailboxes']);
	}//end testAResultsEnvelopeIsUnwrapped()

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The user.
	 */
	private function signedInAs(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signedInAs()
}//end class
