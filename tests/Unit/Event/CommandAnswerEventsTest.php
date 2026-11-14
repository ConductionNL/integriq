<?php
/**
 * The document render events: a render request is answered with a job id or a refusal, and the rendered event reports the outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-rendering/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\DocumentRenderedEvent;
use OCA\Integriq\Event\DocumentRenderRequestedEvent;
use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use PHPUnit\Framework\TestCase;

/**
 * The answer slots of the render request and opt-out change commands, and
 * the defaults of the rendered event.
 *
 * @covers \OCA\Integriq\Event\DocumentRenderRequestedEvent
 * @covers \OCA\Integriq\Event\DocumentRenderedEvent
 * @covers \OCA\Integriq\Event\OptOutChangeRequestedEvent
 */
class CommandAnswerEventsTest extends TestCase {

	/**
	 * A render request starts unanswered and is answered with a job id.
	 *
	 * @return void
	 */
	public function testARenderRequestIsAnsweredWithAJob(): void {
		$event = new DocumentRenderRequestedEvent(
			sourceId: 'src-1',
			templateId: 'tpl-1',
			data: ['name' => 'Jan'],
			requestedBy: 'alice',
			requestedByApp: 'dossiq'
		);

		self::assertSame(expected: 'src-1', actual: $event->getSourceId());
		self::assertSame(expected: 'tpl-1', actual: $event->getTemplateId());
		self::assertSame(expected: ['name' => 'Jan'], actual: $event->getData());
		self::assertSame(expected: 'alice', actual: $event->getRequestedBy());
		self::assertSame(expected: 'dossiq', actual: $event->getRequestedByApp());
		self::assertNull(actual: $event->getJobId());
		self::assertNull(actual: $event->getRefusal());

		$event->setJobId(jobId: 'job-9');
		self::assertSame(expected: 'job-9', actual: $event->getJobId());
		self::assertNull(actual: $event->getRefusal());
	}//end testARenderRequestIsAnsweredWithAJob()

	/**
	 * A render request can be refused with a code and reason; filinq is the default asker.
	 *
	 * @return void
	 */
	public function testARenderRequestCanBeRefused(): void {
		$event = new DocumentRenderRequestedEvent(sourceId: 's', templateId: 't', data: []);
		$event->refuse(reason: 'Template not found', code: 'not_found');

		self::assertSame(expected: ['code' => 'not_found', 'reason' => 'Template not found'], actual: $event->getRefusal());
		self::assertNull(actual: $event->getJobId());
		self::assertSame(expected: '', actual: $event->getRequestedBy());
		self::assertSame(expected: 'filinq', actual: $event->getRequestedByApp());
	}//end testARenderRequestCanBeRefused()

	/**
	 * The rendered event defaults its optional fields to empty strings.
	 *
	 * @return void
	 */
	public function testTheRenderedEventDefaults(): void {
		$failed = new DocumentRenderedEvent(jobId: 'job-9', status: 'failed', error: 'timeout');
		self::assertSame(expected: 'job-9', actual: $failed->getJobId());
		self::assertSame(expected: 'failed', actual: $failed->getStatus());
		self::assertSame(expected: '', actual: $failed->getRequestedBy());
		self::assertSame(expected: '', actual: $failed->getFileReference());
		self::assertSame(expected: 'timeout', actual: $failed->getError());
	}//end testTheRenderedEventDefaults()

	/**
	 * An opt-out change is unhandled until a listener records it or refuses it.
	 *
	 * @return void
	 */
	public function testAnOptOutChangeIsRecordedOrRefused(): void {
		$event = new OptOutChangeRequestedEvent(sourceApp: 'hermiq', address: 'a@b.nl', state: 'opted_out', correlationId: 'corr-1');

		self::assertSame(expected: 'opted_out', actual: $event->getState());
		self::assertSame(expected: 'corr-1', actual: $event->getCorrelationId());
		self::assertFalse(condition: $event->isHandled());
		self::assertNull(actual: $event->getRecordId());
		self::assertNull(actual: $event->getRefusal());

		$event->setHandled(handled: true);
		$event->setRecordId(recordId: 17);
		self::assertTrue(condition: $event->isHandled());
		self::assertSame(expected: 17, actual: $event->getRecordId());

		$event->setRefusal(reason: 'No lawful basis');
		self::assertSame(expected: ['code' => 'refused', 'reason' => 'No lawful basis'], actual: $event->getRefusal());
		$event->setRefusal(reason: 'Unknown address', code: 'unknown');
		self::assertSame(expected: ['code' => 'unknown', 'reason' => 'Unknown address'], actual: $event->getRefusal());
	}//end testAnOptOutChangeIsRecordedOrRefused()
}//end class
