<?php

/**
 * Tests for SourceOwnedDeleteGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\SourceOwnedDeleteGuardListener;
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\OwnershipState;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A delete through OpenRegister's own path meets the ownership refusal.
 */
class SourceOwnedDeleteGuardListenerTest extends TestCase {

	/**
	 * The listener over an ownership answer.
	 *
	 * @param OwnershipState|RuntimeException $answer What forObject() answers, or throws.
	 *
	 * @return SourceOwnedDeleteGuardListener The listener.
	 */
	private function listener(OwnershipState|RuntimeException $answer): SourceOwnedDeleteGuardListener {
		$ownership = $this->getMockBuilder(RecordOwnershipService::class)
			->disableOriginalConstructor()
			->onlyMethods(['forObject'])
			->getMock();
		if ($answer instanceof RuntimeException) {
			$ownership->method('forObject')->willThrowException($answer);
		} else {
			$ownership->method('forObject')->willReturn($answer);
		}

		return new SourceOwnedDeleteGuardListener($ownership, new LocalDeleteGuard(), new NullLogger());

	}//end listener()

	/**
	 * The event OpenRegister dispatches before deleting this object.
	 *
	 * @param array<string,mixed> $data The object's data.
	 *
	 * @return ObjectDeletingEvent The event.
	 */
	private function deleting(array $data = ['naam' => 'Jansen']): ObjectDeletingEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-2222-4333-8444-555555555555');
		$entity->setObject($data);

		return new ObjectDeletingEvent($entity);

	}//end deleting()

	/**
	 * A record the BRP synchronisation owns.
	 *
	 * @return OwnershipState The state.
	 */
	private function owned(): OwnershipState {
		return new OwnershipState(OwnershipState::MODE_SOURCE, 'brp-haalcentraal', '999993653', null, true, false, null, 'sync-1', 'BRP personen');

	}//end owned()

	/**
	 * The delete button on any page no longer removes a source-owned record.
	 *
	 * @return void
	 */
	public function testAnOrdinaryDeleteOfASourceOwnedRecordIsStopped(): void {
		$event = $this->deleting();

		$this->listener($this->owned())->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('source_owned', $event->getErrors()['code']);
		$this->assertStringContainsString('BRP personen', $event->getErrors()['message']);

	}//end testAnOrdinaryDeleteOfASourceOwnedRecordIsStopped()

	/**
	 * A local record is deleted as before.
	 *
	 * @return void
	 */
	public function testALocalRecordIsDeletedAsBefore(): void {
		$event = $this->deleting();

		$this->listener(OwnershipState::local())->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testALocalRecordIsDeletedAsBefore()

	/**
	 * The override OwnershipController writes before deleting lets it through.
	 *
	 * @return void
	 */
	public function testADeleteWithAStatedReasonGoesAhead(): void {
		$event = $this->deleting([LocalDeleteGuard::OVERRIDE_KEY => ['reason' => 'duplicate, merged into 999993654', 'user' => 'behandelaar1']]);

		$this->listener($this->owned())->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testADeleteWithAStatedReasonGoesAhead()

	/**
	 * The engine deleting what the source removed is the owner acting.
	 *
	 * @return void
	 */
	public function testTheEnginesOwnDeleteGoesAhead(): void {
		$event = $this->deleting();
		$listener = $this->listener($this->owned());

		SourceOwnedDeleteGuardListener::whileTheEngineDeletes(fn () => $listener->handle($event));

		$this->assertFalse($event->isPropagationStopped());

		$after = $this->deleting();
		$listener->handle($after);
		$this->assertTrue($after->isPropagationStopped(), 'the bypass ends with the engine delete');

	}//end testTheEnginesOwnDeleteGoesAhead()

	/**
	 * A guard that cannot read the contracts does not block every delete.
	 *
	 * @return void
	 */
	public function testAGuardThatFailsDoesNotBlockDeletes(): void {
		$event = $this->deleting();

		$this->listener(new RuntimeException('no contracts table'))->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAGuardThatFailsDoesNotBlockDeletes()

}//end class
