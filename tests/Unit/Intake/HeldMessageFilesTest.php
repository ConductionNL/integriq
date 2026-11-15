<?php

/**
 * Unit tests for HeldMessageFiles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Intake\HeldMessageFiles;
use OCA\Integriq\Intake\InboundMessage;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a held message's bytes are kept once and read back.
 *
 * @covers \OCA\Integriq\Intake\HeldMessageFiles
 * @uses   \OCA\Integriq\Intake\InboundMessage
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
class HeldMessageFilesTest extends TestCase {

	/**
	 * The OpenRegister file service double.
	 *
	 * @var FileService|MockObject
	 */
	private $fileService;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private $logger;

	/**
	 * Every upload stored, as [object, file name, content, tags].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $uploads = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->uploads = [];
		$this->fileService = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['addFile', 'getFile'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A photo and an attachment are each stored on the held object, tagged,
	 * and the payload records their file ids.
	 *
	 * @return void
	 */
	public function testEachFileIsStoredOnTheHeldObjectWithItsId(): void {
		$nextId = 40;
		$this->fileService->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use (&$nextId): File {
				$this->uploads[] = [$target->object, $upload->fileName, $upload->content, $upload->tags];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(++$nextId);

				return $file;
			}
		);
		$message = $this->reportWithFiles();

		$payload = (new HeldMessageFiles($this->fileService, $this->logger))->keep(
			uuid: 'held-1',
			message: $message,
			payload: $message->toObject()
		);

		$this->assertSame(
			[
				['held-1', 'brief.pdf', '%PDF-1.7', [HeldMessageFiles::TAG]],
				['held-1', 'lantaarnpaal.jpg', 'JPEGBYTES', [HeldMessageFiles::TAG]],
			],
			$this->uploads
		);
		$this->assertSame(['name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 8, 'fileId' => 41], $payload['attachments'][0]);
		$this->assertSame(['name' => 'lantaarnpaal.jpg', 'mime' => 'image/jpeg', 'size' => 9, 'fileId' => 42], $payload['media'][0]);
		$this->assertArrayNotHasKey('content', $payload['media'][0]);

	}//end testEachFileIsStoredOnTheHeldObjectWithItsId()

	/**
	 * A file already kept is not stored a second time when the message is
	 * held again, and a file without bytes is not stored at all.
	 *
	 * @return void
	 */
	public function testAKeptFileOrAnEmptyOneIsNotStoredAgain(): void {
		$this->fileService->expects($this->never())->method('addFile');
		$message = new InboundMessage(
			'public-space-report',
			'MOR-2',
			[],
			'Losliggende tegel.',
			[['name' => 'leeg.txt', 'mime' => 'text/plain', 'content' => '']],
			null,
			[['name' => 'tegel.jpg', 'mime' => 'image/jpeg', 'content' => 'JPEG']],
		);
		$payload = $message->toObject();
		$payload['media'][0]['fileId'] = 7;

		$kept = (new HeldMessageFiles($this->fileService, $this->logger))->keep(uuid: 'held-2', message: $message, payload: $payload);

		$this->assertSame(7, $kept['media'][0]['fileId']);
		$this->assertArrayNotHasKey('fileId', $kept['attachments'][0]);

	}//end testAKeptFileOrAnEmptyOneIsNotStoredAgain()

	/**
	 * A file that cannot be stored is logged and keeps its metadata, so the
	 * message is still held.
	 *
	 * @return void
	 */
	public function testAFileThatCannotBeStoredIsLoggedAndKeptAsMetadata(): void {
		$this->fileService->method('addFile')->willThrowException(new RuntimeException('quota exceeded'));
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->with('Integriq intake: a held message file could not be kept.', ['message' => 'held-3', 'reason' => 'quota exceeded']);
		$message = $this->reportWithFiles();

		$kept = (new HeldMessageFiles($this->fileService, $this->logger))->keep(uuid: 'held-3', message: $message, payload: $message->toObject());

		$this->assertSame(['name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 8], $kept['attachments'][0]);

	}//end testAFileThatCannotBeStoredIsLoggedAndKeptAsMetadata()

	/**
	 * Kept files are read back with their bytes; an entry without a kept file
	 * comes back as the metadata it was.
	 *
	 * @return void
	 */
	public function testKeptFilesAreReadBackWithTheirBytes(): void {
		$this->fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = ''): ?File {
				$this->assertSame('held-4', $object);
				$this->assertSame(42, $file);
				$stored = $this->createMock(File::class);
				$stored->method('getContent')->willReturn('JPEGBYTES');

				return $stored;
			}
		);

		$restored = (new HeldMessageFiles($this->fileService, $this->logger))->restore(
			uuid: 'held-4',
			object: [
				'attachments' => [['name' => 'oud.pdf', 'mime' => 'application/pdf', 'size' => 3]],
				'media' => [['name' => 'lantaarnpaal.jpg', 'mime' => 'image/jpeg', 'size' => 9, 'fileId' => 42]],
			]
		);

		$this->assertSame([['name' => 'oud.pdf', 'mime' => 'application/pdf', 'size' => 3]], $restored['attachments']);
		$this->assertSame('JPEGBYTES', $restored['media'][0]['content']);
		$this->assertSame('lantaarnpaal.jpg', $restored['media'][0]['name']);

	}//end testKeptFilesAreReadBackWithTheirBytes()

	/**
	 * A kept file that is gone is logged and handed on as metadata.
	 *
	 * @return void
	 */
	public function testAFileThatCannotBeReadBackIsLogged(): void {
		$this->fileService->method('getFile')->willThrowException(new RuntimeException('not found'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Integriq intake: a held message file could not be read back.', ['message' => 'held-5', 'reason' => 'not found']);

		$restored = (new HeldMessageFiles($this->fileService, $this->logger))->restore(
			uuid: 'held-5',
			object: ['media' => [['name' => 'x.jpg', 'fileId' => 9]], 'attachments' => 'not a list']
		);

		$this->assertSame(['attachments' => [], 'media' => [['name' => 'x.jpg', 'fileId' => 9]]], $restored);

	}//end testAFileThatCannotBeReadBackIsLogged()

	/**
	 * A report with one attachment and one photo.
	 *
	 * @return InboundMessage The message.
	 */
	private function reportWithFiles(): InboundMessage {
		return new InboundMessage(
			'public-space-report',
			'MOR-1',
			['name' => 'Jan Burger'],
			'De lantaarnpaal brandt niet.',
			[['name' => 'brief.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.7']],
			['latitude' => 52.0907, 'longitude' => 5.1214],
			[['name' => 'lantaarnpaal.jpg', 'mime' => 'image/jpeg', 'content' => 'JPEGBYTES']],
		);

	}//end reportWithFiles()

}//end class
