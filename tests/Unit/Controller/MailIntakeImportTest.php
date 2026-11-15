<?php

/**
 * Characterisation tests for MailIntakeController::import(): the source id
 * fallback, every upload refusal, the case pattern and the answer.
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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MailIntakeController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins import() from the source id to the 201 answer.
 */
class MailIntakeImportTest extends TestCase {

	/**
	 * Every intake() call, as [sourceId, subject, pattern].
	 *
	 * @var list<array>
	 */
	private array $intakes = [];

	/**
	 * Every source id looked up.
	 *
	 * @var list<string>
	 */
	private array $lookups = [];

	/**
	 * A controller over a request answering the given param and upload.
	 *
	 * @param string     $param         The `sourceId` request parameter.
	 * @param mixed      $upload        What getUploadedFile('file') answers.
	 * @param array|null $configuration The mailbox's configuration.
	 *
	 * @return MailIntakeController
	 */
	private function controller(string $param, mixed $upload, ?array $configuration = []): MailIntakeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, $default = null) => ($name === 'sourceId' ? $param : $default));
		$request->method('getUploadedFile')->willReturnCallback(static fn (string $name) => ($name === 'file' ? $upload : null));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$auth = $this->getMockBuilder(ActionAuthService::class)->disableOriginalConstructor()->onlyMethods(['requireAction'])->getMock();
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($configuration): ObjectEntity {
				$this->lookups[] = $args[0];
				return ObjectServiceMockBuilder::objectEntity($this, ['type' => 'Mailbox', 'configuration' => $configuration], 'src-uuid');
			}
		);
		$intake = $this->getMockBuilder(MailIntakeService::class)->disableOriginalConstructor()->onlyMethods(['intake', 'findByMessageId'])->getMock();
		$intake->method('intake')->willReturnCallback(
			function (string $sourceId, $message, ?string $pattern): ObjectEntity {
				$this->intakes[] = [$sourceId, $message->getSubject(), $pattern];
				return ObjectServiceMockBuilder::objectEntity($this, ['subject' => $message->getSubject()], 'msg-1');
			}
		);
		$mailbox = $this->getMockBuilder(MailboxSourceHandler::class)->disableOriginalConstructor()->onlyMethods(['poll'])->getMock();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new MailIntakeController('integriq', $request, $session, $auth, $objects, new MessageParser(new EmlParser(), new MsgParser()), $intake, $mailbox, $l10n);

	}//end controller()

	/**
	 * A temporary .eml upload.
	 *
	 * @param string $content The file content.
	 * @param array  $extra   More upload fields.
	 *
	 * @return array The upload.
	 */
	private function upload(string $content, array $extra = []): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'mail-import-');
		file_put_contents($path, $content);

		return array_merge(['tmp_name' => $path], $extra);

	}//end upload()

	/**
	 * Without a path id the request parameter names the source; the case
	 * pattern is read as a string, or null without one; the answer is 201.
	 *
	 * @return void
	 */
	public function testTheSourceIdFallsBackToTheParameterAndTheMessageIsTakenIn(): void {
		$eml = "From: a@example.org\r\nSubject: Hallo\r\n\r\nTekst\r\n";

		$response = $this->controller('from-param', $this->upload($eml, ['name' => 'x.eml']), ['casePattern' => 42])->import();
		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 'msg-1', 'message' => ['subject' => 'Hallo'], 'warning' => null], $response->getData());

		$this->controller('ignored', $this->upload($eml), null)->import('from-path');

		$this->assertSame(['from-param', 'from-path'], $this->lookups);
		$this->assertSame([['from-param', 'Hallo', '42'], ['from-path', 'Hallo', null]], $this->intakes);

	}//end testTheSourceIdFallsBackToTheParameterAndTheMessageIsTakenIn()

	/**
	 * No source id, a missing or path-less upload and an empty file are
	 * refused before anything is taken in.
	 *
	 * @return void
	 */
	public function testEveryUploadRefusal(): void {
		$cases = [
			[$this->controller('', ['tmp_name' => 'x'])->import(), 404, 'No mailbox source with that id.'],
			[$this->controller('s', 'not-an-array')->import(), 400, 'Attach the message file as "file".'],
			[$this->controller('s', ['tmp_name' => 7])->import(), 400, 'Attach the message file as "file".'],
			[$this->controller('s', $this->upload(''))->import(), 400, 'The uploaded file was empty.'],
		];
		foreach ($cases as $index => [$response, $status, $error]) {
			$this->assertSame([$status, ['error' => $error]], [$response->getStatus(), $response->getData()], (string)$index);
		}

		$this->assertSame([], $this->intakes);

	}//end testEveryUploadRefusal()

}//end class
