<?php

/**
 * Unit tests for MailIntakeController.
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MailIntakeController;
use OCA\Integriq\Exception\MailboxTransportException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests the HTTP surface, starting with who is refused.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
 */
class MailIntakeControllerTest extends TestCase {

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
	 * The OR object service double.
	 *
	 * @var OrObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The intake service double.
	 *
	 * @var MailIntakeService|MockObject
	 */
	private $intakeService;

	/**
	 * The mailbox handler double.
	 *
	 * @var MailboxSourceHandler|MockObject
	 */
	private $mailboxHandler;

	/**
	 * The controller under test.
	 *
	 * @var MailIntakeController
	 */
	private MailIntakeController $controller;

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
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->intakeService = $this->getMockBuilder(MailIntakeService::class)
			->disableOriginalConstructor()
			->onlyMethods(['intake', 'findByMessageId'])
			->getMock();
		$this->mailboxHandler = $this->getMockBuilder(MailboxSourceHandler::class)
			->disableOriginalConstructor()
			->onlyMethods(['poll'])
			->getMock();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new MailIntakeController(
			'integriq',
			$this->request,
			$this->userSession,
			$this->actionAuth,
			$this->objectService,
			new MessageParser(new EmlParser(), new MsgParser()),
			$this->intakeService,
			$this->mailboxHandler,
			$l10n,
		);

	}//end setUp()

	/**
	 * Nobody logged in imports nothing.
	 *
	 * @return void
	 */
	public function testAnonymousImportIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->intakeService->expects($this->never())->method('intake');

		$response = $this->controller->import('source-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousImportIsRefused()

	/**
	 * An authenticated account without the `mail.import` action is refused
	 * before anything is read or written.
	 *
	 * @return void
	 */
	public function testOrdinaryAccountWithoutTheActionIsRefused(): void {
		$this->signedInAs('burger');
		$this->actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'mail.import' requires admin rights")
		);
		$this->intakeService->expects($this->never())->method('intake');
		$this->objectService->expects($this->never())->method('find');

		$this->expectException(OCSForbiddenException::class);
		$this->controller->import('source-1');

	}//end testOrdinaryAccountWithoutTheActionIsRefused()

	/**
	 * A source that is not a mailbox is not an import target.
	 *
	 * @return void
	 */
	public function testImportOntoANonMailboxSourceIs404(): void {
		$this->signedInAs('admin');
		$this->objectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['type' => 'api'], 'source-9')
		);
		$this->intakeService->expects($this->never())->method('intake');

		$response = $this->controller->import('source-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testImportOntoANonMailboxSourceIs404()

	/**
	 * An import without a file says what is missing.
	 *
	 * @return void
	 */
	public function testImportWithoutAFileIs400(): void {
		$this->signedInAs('admin');
		$this->objectService->method('find')->willReturn($this->mailboxSource());
		$this->request->method('getUploadedFile')->willReturn(null);

		$response = $this->controller->import('source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testImportWithoutAFileIs400()

	/**
	 * An imported message is parsed and taken in, and the response names it.
	 *
	 * @return void
	 */
	public function testImportedMessageIsTakenIn(): void {
		$this->signedInAs('admin');
		$this->objectService->method('find')->willReturn($this->mailboxSource());

		$path = tempnam(sys_get_temp_dir(), 'mail-intake-');
		file_put_contents(
			(string)$path,
			"From: burger@example.org\r\nSubject: Vraag over ZAAK-2026-0042\r\n"
			. "Message-ID: <import-1@example.org>\r\n\r\nTekst\r\n"
		);
		$this->request->method('getUploadedFile')->willReturn(
			['tmp_name' => (string)$path, 'name' => 'bericht.eml']
		);

		$seen = [];
		$this->intakeService->method('intake')->willReturnCallback(
			function (string $sourceId, $message, ?string $pattern) use (&$seen): ObjectEntity {
				$seen = [
					'sourceId' => $sourceId,
					'subject' => $message->getSubject(),
					'pattern' => $pattern,
				];
				return ObjectServiceMockBuilder::objectEntity($this, $message->toObject(), 'message-1');
			}
		);

		$response = $this->controller->import('source-1');
		unlink((string)$path);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('message-1', $response->getData()['id']);
		$this->assertNull($response->getData()['warning']);
		$this->assertSame('source-1', $seen['sourceId']);
		$this->assertSame('Vraag over ZAAK-2026-0042', $seen['subject']);
		$this->assertSame('/ZAAK-\d{4}-\d{4}/', $seen['pattern']);

	}//end testImportedMessageIsTakenIn()

	/**
	 * A poll that cannot run answers why, as a 400 rather than a 500.
	 *
	 * @return void
	 */
	public function testPollRefusalIsReported(): void {
		$this->signedInAs('admin');
		$this->objectService->method('find')->willReturn($this->mailboxSource());
		$this->mailboxHandler->method('poll')->willThrowException(
			new MailboxTransportException('This host has no imap extension')
		);

		$response = $this->controller->poll('source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('imap extension', $response->getData()['error']);

	}//end testPollRefusalIsReported()

	/**
	 * A poll answers what it did.
	 *
	 * @return void
	 */
	public function testPollReportsWhatItDid(): void {
		$this->signedInAs('admin');
		$this->objectService->method('find')->willReturn($this->mailboxSource());
		$this->mailboxHandler->method('poll')->willReturn(
			['created' => 3, 'skipped' => 0, 'cursor' => '2026-09-15T12:00:00+02:00']
		);

		$response = $this->controller->poll('source-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['created']);

	}//end testPollReportsWhatItDid()

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
	 * A mailbox source with a case pattern.
	 *
	 * @return ObjectEntity The source.
	 */
	private function mailboxSource(): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'mailbox',
				'configuration' => ['mock' => true, 'casePattern' => '/ZAAK-\d{4}-\d{4}/'],
			],
			'source-1'
		);

	}//end mailboxSource()

}//end class
