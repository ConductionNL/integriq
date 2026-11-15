<?php

/**
 * Unit tests for how an inbound message is stored and read back.
 *
 * The stored intake_message must never carry file bytes (only name, type and
 * size), and reading a stored object back must narrow whatever was written to
 * the shapes the message promises, keeping "no location" apart from "an
 * empty location".
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Intake\InboundMessage;
use PHPUnit\Framework\TestCase;

/**
 * Storing without bytes, and reading back with narrowed shapes.
 *
 * @covers \OCA\Integriq\Intake\InboundMessage
 */
class InboundMessageStorageTest extends TestCase {

	/**
	 * Attachments and media are stored as name, type and size only, with defaults for what is missing.
	 *
	 * @return void
	 */
	public function testFilesAreStoredWithoutTheirBytes(): void {
		$message = new InboundMessage(
			'public-space-report',
			'MOR-7',
			['name' => 'Jan'],
			'Losse stoeptegel',
			[['name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size' => 2048, 'content' => 'JPEGBYTES']],
			['latitude' => 52.09, 'longitude' => 5.12],
			[['content' => 'abcde']],
			['id' => 'MOR-7'],
			'2026-09-15T10:00:00+02:00',
			['category' => 'bestrating'],
		);

		$object = $message->toObject();

		$this->assertSame([['name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size' => 2048]], $object['attachments']);
		$this->assertSame([['name' => 'attachment', 'mime' => 'application/octet-stream', 'size' => 5]], $object['media']);
		$this->assertStringNotContainsString('JPEGBYTES', json_encode($object));
		$this->assertSame(['id' => 'MOR-7'], $object['rawPayload']);
		$this->assertSame('2026-09-15T10:00:00+02:00', $object['receivedAt']);
	}//end testFilesAreStoredWithoutTheirBytes()

	/**
	 * A stored object reads back with its payload, time and fields.
	 *
	 * @return void
	 */
	public function testAStoredObjectReadsBack(): void {
		$message = InboundMessage::fromObject([
			'channelId' => 'messaging',
			'externalId' => 'WA-1',
			'correspondent' => ['phone' => '+31612345678'],
			'text' => 'Hallo',
			'attachments' => ['first' => ['name' => 'a.pdf']],
			'location' => ['latitude' => 52.0],
			'rawPayload' => ['from' => '+31612345678'],
			'receivedAt' => 1757930400,
			'fields' => ['text' => 'Hallo'],
		]);

		$this->assertSame('messaging', $message->getChannelId());
		$this->assertSame([['name' => 'a.pdf']], $message->getAttachments());
		$this->assertSame(['latitude' => 52.0], $message->getLocation());
		$this->assertSame(['from' => '+31612345678'], $message->getRawPayload());
		$this->assertSame('1757930400', $message->getReceivedAt());
		$this->assertSame(['text' => 'Hallo'], $message->getFields());
	}//end testAStoredObjectReadsBack()

	/**
	 * Values of the wrong shape read back as empty, and a location that is not a map as none.
	 *
	 * @return void
	 */
	public function testWrongShapesReadBackAsEmptyAndNoLocation(): void {
		$message = InboundMessage::fromObject([
			'correspondent' => 'Jan',
			'attachments' => 'a.pdf',
			'media' => null,
			'rawPayload' => 'raw',
			'fields' => 5,
			'location' => '52.0,5.1',
		]);

		$this->assertSame('', $message->getChannelId());
		$this->assertSame([], $message->getCorrespondent());
		$this->assertSame([], $message->getAttachments());
		$this->assertSame([], $message->getMedia());
		$this->assertSame([], $message->getRawPayload());
		$this->assertSame([], $message->getFields());
		$this->assertNull($message->getLocation());
		$this->assertNull($message->getReceivedAt());
	}//end testWrongShapesReadBackAsEmptyAndNoLocation()
}//end class
