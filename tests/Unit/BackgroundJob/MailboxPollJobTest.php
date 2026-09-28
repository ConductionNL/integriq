<?php

/**
 * The mailbox poll runs on its own, not only when somebody presses the button.
 *
 * mail-intake-creates-cases REQ-MAIL-001 says the mailbox synchronization runs
 * as a background job. Until this test nothing scheduled it: the only caller of
 * MailboxSourceHandler::poll() was MailIntakeController::poll(), so mail arrived
 * in integriq only when an administrator polled by hand. These tests drive the
 * real handler and the real mock transport from the job, and read the job's
 * registration from appinfo/info.xml, the file Nextcloud schedules from.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\MailboxPollJob;
use OCA\Integriq\Service\Mail\EmlParser;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MsgParser;
use OCA\Integriq\Service\Mail\ParsedMessage;
use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport;
use OCA\Integriq\Service\Mail\Transport\MockMailboxTransport;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Drives MailboxPollJob over the real MailboxSourceHandler.
 */
class MailboxPollJobTest extends TestCase {

	/**
	 * Message ids handed to intake(), in order.
	 *
	 * @var string[]
	 */
	private array $taken = [];

	/**
	 * The filters the sweep asked OpenRegister for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A mock-mode mailbox source with one fixture message.
	 *
	 * @param string $uuid      The source uuid.
	 * @param string $messageId The fixture message id.
	 * @param string $protocol  The protocol the configuration names.
	 *
	 * @return ObjectEntity
	 */
	private function mailbox(string $uuid, string $messageId, string $protocol='imap'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type'          => 'mailbox',
				'isEnabled'     => true,
				'configuration' => [
					'mock'     => ($protocol !== 'pop3'),
					'protocol' => $protocol,
					'folder'   => 'INBOX',
					'fixture'  => [
						'messages' => [
							[
								'messageId'  => $messageId,
								'from'       => 'burger@example.org',
								'subject'    => 'Vraag over ZAAK-2026-0042',
								'receivedAt' => '2026-09-15T10:00:00+02:00',
								'bodyText'   => 'Eerste bericht.',
							],
						],
					],
				],
			],
			$uuid
		);
	}//end mailbox()

	/**
	 * Build the job over a real handler whose register holds the given sources.
	 *
	 * @param ObjectEntity[] $sources The enabled mailbox sources the register answers with.
	 *
	 * @return MailboxPollJob
	 */
	private function makeJob(array $sources): MailboxPollJob {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($sources): array {
				$this->queries[] = ($config['filters'] ?? []);
				return ['results' => $sources];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn ($object = [], $register = null, $schema = null, $uuid = null) => ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid)
		);

		$intakeService = $this->getMockBuilder(MailIntakeService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByMessageId', 'intake'])
			->getMock();
		$intakeService->method('findByMessageId')->willReturn(null);
		$intakeService->method('intake')->willReturnCallback(
			function (string $sourceId, ParsedMessage $message): ObjectEntity {
				$this->taken[] = $sourceId . ':' . $message->getMessageId();
				return ObjectServiceMockBuilder::objectEntity($this, $message->toObject(), $message->getMessageId());
			}
		);

		$parser  = new MessageParser(new EmlParser(), new MsgParser());
		$handler = new MailboxSourceHandler(
			$objectService,
			$intakeService,
			new MockMailboxTransport($parser),
			new ImapMailboxTransport($parser),
			new GraphMailboxTransport($this->createMock(IClientService::class)),
			new NullLogger(),
		);

		return new MailboxPollJob($this->createMock(ITimeFactory::class), $handler, new NullLogger());
	}//end makeJob()

	/**
	 * Nextcloud schedules the job from appinfo/info.xml.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		$this->assertContains(MailboxPollJob::class, $jobs);
	}//end testTheJobIsRegisteredInInfoXml()

	/**
	 * A run polls every enabled mailbox source and asks only for those.
	 *
	 * @return void
	 */
	public function testARunPollsEveryEnabledMailbox(): void {
		$job = $this->makeJob([$this->mailbox('box-1', 'm-1'), $this->mailbox('box-2', 'm-2')]);

		$job->run(null);

		$this->assertSame(['box-1:m-1', 'box-2:m-2'], $this->taken);
		$this->assertSame('mailbox', $this->queries[0]['type'] ?? null);
		$this->assertTrue($this->queries[0]['isEnabled'] ?? null);
		$this->assertSame('source', $this->queries[0]['schema'] ?? null);
	}//end testARunPollsEveryEnabledMailbox()

	/**
	 * One unusable mailbox does not stop the others.
	 *
	 * @return void
	 */
	public function testOneBrokenMailboxDoesNotStopTheRest(): void {
		$job = $this->makeJob([$this->mailbox('broken', 'm-0', 'pop3'), $this->mailbox('box-2', 'm-2')]);

		$job->run(null);

		$this->assertSame(['box-2:m-2'], $this->taken);
	}//end testOneBrokenMailboxDoesNotStopTheRest()
}//end class
