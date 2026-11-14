<?php

/**
 * Unit tests for keeping a held message's files and routing it again.
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

use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\HeldMessageFiles;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the held-message half of the routing engine: files kept on hold,
 * nothing kept on a route, and a held message routed again onto itself.
 *
 * @covers \OCA\Integriq\Intake\IntakeRoutingService
 * @uses   \OCA\Integriq\Intake\HeldMessageFiles
 * @uses   \OCA\Integriq\Intake\InboundMessage
 * @uses   \OCA\Integriq\Event\IntakeMessageRoutedEvent
 * @uses   \OCA\Integriq\Exception\IntakeRoutingException
 *
 * @spec openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
 */
class IntakeRerouteTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The event dispatcher double.
	 *
	 * @var IEventDispatcher|MockObject
	 */
	private $eventDispatcher;

	/**
	 * The OpenRegister file service double.
	 *
	 * @var FileService|MockObject
	 */
	private $fileService;

	/**
	 * Every [payload, uuid] the service saved, in order.
	 *
	 * @var array<int,array{0:array<string,mixed>,1:string|null}>
	 */
	private array $saved = [];

	/**
	 * The rules findAll() answers with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rules = [];

	/**
	 * Every file stored, as [object, file name, content].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $uploads = [];

	/**
	 * The service under test.
	 *
	 * @var IntakeRoutingService
	 */
	private IntakeRoutingService $service;

	/**
	 * Set up the service with recording doubles and real held-file keeping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->rules = [];
		$this->uploads = [];

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->rules as $index => $rule) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $rule, 'rule-' . $index);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = [$object, $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'new-uuid');
			}
		);

		$this->fileService = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['addFile', 'getFile'])
			->getMock();
		$this->fileService->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload): File {
				$this->uploads[] = [$target->object, $upload->fileName, $upload->content];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(70 + count($this->uploads));

				return $file;
			}
		);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new IntakeRoutingService(
			$this->objectService,
			$this->eventDispatcher,
			$this->createMock(SchemaMapper::class),
			$logger,
			new HeldMessageFiles($this->fileService, $logger),
		);

	}//end setUp()

	/**
	 * A report with a photo that matches no rule is held, and the photo is
	 * stored as a file on the held message.
	 *
	 * @return void
	 */
	public function testAHeldReportKeepsItsPhoto(): void {
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$held = $this->service->route($this->reportWithPhoto());

		$this->assertSame([['new-uuid', 'lantaarnpaal.jpg', 'JPEGBYTES']], $this->uploads);
		$this->assertSame('held', $held->getObject()['status']);
		$this->assertSame(71, $held->getObject()['media'][0]['fileId']);
		$this->assertSame('new-uuid', $this->saved[1][1]);

	}//end testAHeldReportKeepsItsPhoto()

	/**
	 * A report that routes on arrival stores no file on its message: its
	 * bytes went with the hand-off.
	 *
	 * @return void
	 */
	public function testARoutedReportStoresNoFile(): void {
		$this->rules = [$this->lightingRule()];
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function (IntakeMessageRoutedEvent $event): void {
				$event->setCreatedRef('zaak/1');
			}
		);

		$routed = $this->service->route($this->reportWithPhoto());

		$this->assertSame([], $this->uploads);
		$this->assertSame('routed', $routed->getObject()['status']);
		$this->assertArrayNotHasKey('fileId', $routed->getObject()['media'][0]);

	}//end testARoutedReportStoresNoFile()

	/**
	 * A report held before its rule existed is routed again onto the same
	 * message, names the rule and the case, and hands the kept photo over.
	 *
	 * @return void
	 */
	public function testAHeldReportRoutedAgainOpensACaseWithItsPhoto(): void {
		$this->heldReportIs(status: 'held');
		$this->rules = [$this->lightingRule()];
		$this->fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = ''): ?File {
				$stored = $this->createMock(File::class);
				$stored->method('getContent')->willReturn($object . ':' . $file . ':JPEGBYTES');

				return $stored;
			}
		);
		$handed = [];
		$this->eventDispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (IntakeMessageRoutedEvent $event) use (&$handed): void {
				$handed = ['files' => $event->getFiles(), 'uuid' => $event->getMessageUuid(), 'target' => $event->getTargetSchema()];
				$event->setCreatedRef('zaak/77');
			}
		);

		$routed = $this->service->reroute('held-uuid');

		$this->assertSame('held-uuid', $handed['uuid']);
		$this->assertSame('melding_openbare_ruimte', $handed['target']);
		$this->assertSame('held-uuid:71:JPEGBYTES', $handed['files'][0]['content']);
		$this->assertSame([], $this->uploads);
		$this->assertSame('held-uuid', $this->saved[0][1]);
		$this->assertSame(
			['routed', 'Straatverlichting', 'zaak/77', ''],
			[$routed->getObject()['status'], $routed->getObject()['ruleName'], $routed->getObject()['targetRef'], $routed->getObject()['reason']]
		);
		$this->assertArrayNotHasKey('@self', $this->saved[0][0]);

	}//end testAHeldReportRoutedAgainOpensACaseWithItsPhoto()

	/**
	 * A failed message routed again that still matches nothing is held, and
	 * its already-kept photo is not stored a second time.
	 *
	 * @return void
	 */
	public function testAMessageThatStillMatchesNothingStaysHeld(): void {
		$this->heldReportIs(status: 'failed');
		$this->fileService->method('getFile')->willReturn(null);
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$held = $this->service->reroute('held-uuid');

		$this->assertSame('held', $held->getObject()['status']);
		$this->assertSame('No routing rule matched channel "public-space-report".', $held->getObject()['reason']);
		$this->assertSame([], $this->uploads);
		$this->assertSame(71, $held->getObject()['media'][0]['fileId']);

	}//end testAMessageThatStillMatchesNothingStaysHeld()

	/**
	 * A routed message is not routed a second time.
	 *
	 * @return void
	 */
	public function testARoutedMessageIsNotRoutedAgain(): void {
		$this->heldReportIs(status: 'routed');
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(IntakeRoutingException::class);
		$this->expectExceptionMessage('Only a held or failed message can be routed again.');

		$this->service->reroute('held-uuid');

	}//end testARoutedMessageIsNotRoutedAgain()

	/**
	 * An unknown message is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownMessageIsRefused(): void {
		$this->objectService->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

		$this->expectException(IntakeRoutingException::class);
		$this->expectExceptionMessage('No intake message "missing".');

		$this->service->reroute('missing');

	}//end testAnUnknownMessageIsRefused()

	/**
	 * The stored held report find() answers with.
	 *
	 * @param string $status The stored status.
	 *
	 * @return void
	 */
	private function heldReportIs(string $status): void {
		$object = array_merge(
			$this->reportWithPhoto()->toObject(),
			['status' => $status, 'reason' => 'No routing rule matched channel "public-space-report".', '@self' => ['id' => 'held-uuid']]
		);
		$object['media'][0]['fileId'] = 71;
		$this->objectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, $object, 'held-uuid')
		);

	}//end heldReportIs()

	/**
	 * A rule that opens a lighting report.
	 *
	 * @return array<string,mixed> The rule.
	 */
	private function lightingRule(): array {
		return [
			'name' => 'Straatverlichting',
			'channelId' => 'public-space-report',
			'condition' => ['field' => 'text', 'operator' => 'contains', 'value' => 'lantaarnpaal'],
			'targetSchema' => 'melding_openbare_ruimte',
			'fieldMapping' => ['omschrijving' => 'text'],
			'isEnabled' => true,
			'order' => 10,
		];

	}//end lightingRule()

	/**
	 * A public space report with one photo.
	 *
	 * @return InboundMessage The message.
	 */
	private function reportWithPhoto(): InboundMessage {
		return new InboundMessage(
			'public-space-report',
			'MOR-1',
			['name' => 'Jan Burger'],
			'De lantaarnpaal brandt niet.',
			[],
			['latitude' => 52.0907, 'longitude' => 5.1214],
			[['name' => 'lantaarnpaal.jpg', 'mime' => 'image/jpeg', 'content' => 'JPEGBYTES']],
		);

	}//end reportWithPhoto()

}//end class
