<?php

/**
 * Unit tests for the two public opt-out events and their listeners.
 *
 * Each test builds the real event, dispatches it through a dispatcher that
 * resolves service listeners as Nextcloud's does, and reads the result slot.
 * The event is built from its class name as a string, the way a sibling app
 * that cannot type-depend on integriq builds it.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\EventListener\OptOutChangeRequestedListener;
use OCA\Integriq\EventListener\OutboundSendDecisionRequestedListener;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCA\Integriq\Tests\Helpers\ServiceListenerDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The decision event and the change event, end to end through a dispatcher.
 */
class OutboundSendDecisionRequestedListenerTest extends TestCase {

	/**
	 * The event class a sibling app names as a string.
	 *
	 * @var string
	 */
	private const DECISION_EVENT = 'OCA\\Integriq\\Event\\OutboundSendDecisionRequestedEvent';

	/**
	 * The change event class a sibling app names as a string.
	 *
	 * @var string
	 */
	private const CHANGE_EVENT = 'OCA\\Integriq\\Event\\OptOutChangeRequestedEvent';

	/**
	 * The services over in-memory tables.
	 *
	 * @var OptOutFixture
	 */
	private OptOutFixture $fx;

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));

	}//end setUp()

	/**
	 * Five addresses, two opted out: handled, two refused, three allowed.
	 *
	 * @return void
	 */
	public function testABatchIsAnsweredPerRecipient(): void {
		$registry = $this->fx->registry();
		$registry->add('b@example.org');
		$registry->add('d@example.org');
		$addresses = ['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org', 'e@example.org'];

		$class = self::DECISION_EVENT;
		$event = new $class(
			sourceApp: 'openregister',
			channel: 'email',
			category: 'service',
			recipients: array_map(static fn (string $a): array => ['address' => $a], $addresses),
			correlationId: 'flow-1'
		);
		$this->dispatcher(registry: $registry)->dispatchTyped($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('opted-out', $event->getDecision('b@example.org')['code']);
		$this->assertFalse($event->getDecision('d@example.org')['send']);
		$this->assertSame('allowed', $event->getDecision('a@example.org')['code']);
		$this->assertNotNull($event->getDecision('e@example.org')['unsubscribe']);
		$this->assertSame(
			['send', 'overridden', 'code', 'reason', 'unsubscribe'],
			array_keys($event->getDecision('c@example.org'))
		);

	}//end testABatchIsAnsweredPerRecipient()

	/**
	 * With no user session at all the answer is the same: the read is the table's.
	 *
	 * @return void
	 */
	public function testTheAnswerDoesNotDependOnWhoAsks(): void {
		$registry = $this->fx->registry();
		$registry->add('jan@example.org');
		$class = self::DECISION_EVENT;
		$event = new $class('dossiq', 'email', 'case-update', [['address' => 'jan@example.org']]);

		$this->dispatcher(registry: $registry)->dispatchTyped($event);

		$this->assertFalse($event->getDecision('jan@example.org')['send']);

	}//end testTheAnswerDoesNotDependOnWhoAsks()

	/**
	 * A table that cannot be read leaves the event unhandled, with no decisions.
	 *
	 * @return void
	 */
	public function testAListenerFailureLeavesTheEventUnhandled(): void {
		$this->fx->table->failReads = true;
		$class = self::DECISION_EVENT;
		$event = new $class('openregister', 'email', 'service', [['address' => 'jan@example.org']]);

		$this->dispatcher(registry: $this->fx->registry())->dispatchTyped($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getDecision('jan@example.org'));

	}//end testAListenerFailureLeavesTheEventUnhandled()

	/**
	 * The rollback flag leaves the event unhandled, so siblings fail closed.
	 *
	 * @return void
	 */
	public function testTheRollbackFlagLeavesTheEventUnhandled(): void {
		$this->fx->config[OptOutRegistry::CONFIG_AUTHORITY] = 'false';
		$class = self::DECISION_EVENT;
		$event = new $class('openregister', 'email', 'service', [['address' => 'jan@example.org']]);

		$this->dispatcher(registry: $this->fx->registry())->dispatchTyped($event);

		$this->assertFalse($event->isHandled());

	}//end testTheRollbackFlagLeavesTheEventUnhandled()

	/**
	 * A STOP keyword becomes a channel opt-out with one change entry; START lifts it.
	 *
	 * @return void
	 */
	public function testOptOutChangeStopThenStart(): void {
		$registry = $this->fx->registry();
		$dispatcher = $this->dispatcher(registry: $registry);
		$class = self::CHANGE_EVENT;

		$stop = new $class(sourceApp: 'pipelinq', address: '+31612345678', state: 'opted-out', scope: 'channel', channel: 'sms', source: 'keyword-stop');
		$dispatcher->dispatchTyped($stop);

		$this->assertTrue($stop->isHandled());
		$this->assertNotNull($stop->getRecordId());
		$this->assertSame(1, $this->fx->table->countAll());
		$this->assertSame('opted-out', array_values($this->fx->table->rows)[0]->getState());
		$changes = $this->fx->log->ofKind(OptOutLogEntry::KIND_CHANGE);
		$this->assertCount(1, $changes);
		$this->assertSame('pipelinq', $changes[0]->getSourceApp());

		$start = new $class(sourceApp: 'pipelinq', address: '+31612345678', state: 'opted-in', scope: 'channel', channel: 'sms', lawfulBasis: 'consent', source: 'keyword-start');
		$dispatcher->dispatchTyped($start);

		$this->assertSame($stop->getRecordId(), $start->getRecordId());
		$this->assertSame('opted-in', array_values($this->fx->table->rows)[0]->getState());
		$this->assertCount(2, $this->fx->log->ofKind(OptOutLogEntry::KIND_CHANGE));

	}//end testOptOutChangeStopThenStart()

	/**
	 * A migrated record is written once.
	 *
	 * @return void
	 */
	public function testOptOutChangeMigratedRecordIsWrittenOnce(): void {
		$dispatcher = $this->dispatcher(registry: $this->fx->registry());
		$class = self::CHANGE_EVENT;

		$first = new $class(sourceApp: 'pipelinq', address: 'jan@example.nl', state: 'opted-out', legacyRef: 'pq-123');
		$second = new $class(sourceApp: 'pipelinq', address: 'jan@example.nl', state: 'opted-out', scope: 'list', ref: 'nieuws', legacyRef: 'pq-123');
		$dispatcher->dispatchTyped($first);
		$dispatcher->dispatchTyped($second);

		$this->assertSame($first->getRecordId(), $second->getRecordId());
		$this->assertSame(1, $this->fx->table->countAll());

	}//end testOptOutChangeMigratedRecordIsWrittenOnce()

	/**
	 * An erasure through the event keeps the opt-out.
	 *
	 * @return void
	 */
	public function testOptOutChangeErasureKeepsTheOptOut(): void {
		$dispatcher = $this->dispatcher(registry: $this->fx->registry());
		$class = self::CHANGE_EVENT;
		$dispatcher->dispatchTyped(new $class(sourceApp: 'pipelinq', address: 'jan@example.nl', state: 'opted-out', contactRef: 'c-1', evidence: ['form' => 'x']));

		$erase = new $class(sourceApp: 'pipelinq', address: '', state: 'erase-contact', contactRef: 'c-1');
		$dispatcher->dispatchTyped($erase);

		$this->assertTrue($erase->isHandled());
		$row = array_values($this->fx->table->rows)[0];
		$this->assertSame('opted-out', $row->getState());
		$this->assertSame('jan@example.nl', $row->getAddress());
		$this->assertSame('', $row->getContactRef());
		$this->assertNull($row->getEvidence());

	}//end testOptOutChangeErasureKeepsTheOptOut()

	/**
	 * An incomplete request is handled with a refusal; nothing is written.
	 *
	 * @return void
	 */
	public function testOptOutChangeIncompleteRequestIsRefused(): void {
		$class = self::CHANGE_EVENT;
		$event = new $class(sourceApp: 'pipelinq', address: '+31612345678', state: 'opted-out', scope: 'channel');

		$this->dispatcher(registry: $this->fx->registry())->dispatchTyped($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('invalid-request', $event->getRefusal()['code']);
		$this->assertSame(0, $this->fx->table->countAll());

	}//end testOptOutChangeIncompleteRequestIsRefused()

	/**
	 * Application registers both listeners for their events.
	 *
	 * @return void
	 */
	public function testApplicationRegistersBothListeners(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertStringContainsString(
			'addServiceListener(eventName: OutboundSendDecisionRequestedEvent::class, className: OutboundSendDecisionRequestedListener::class)',
			$source
		);
		$this->assertStringContainsString(
			'addServiceListener(eventName: OptOutChangeRequestedEvent::class, className: OptOutChangeRequestedListener::class)',
			$source
		);

	}//end testApplicationRegistersBothListeners()

	/**
	 * A dispatcher with both listeners registered as Application registers them.
	 *
	 * @param OptOutRegistry $registry The registry.
	 *
	 * @return ServiceListenerDispatcher The dispatcher.
	 */
	private function dispatcher(OptOutRegistry $registry): ServiceListenerDispatcher {
		$dispatcher = new ServiceListenerDispatcher(
			[
				OutboundSendDecisionRequestedListener::class => new OutboundSendDecisionRequestedListener($registry, new NullLogger()),
				OptOutChangeRequestedListener::class => new OptOutChangeRequestedListener($registry, new NullLogger()),
			]
		);
		$dispatcher->addServiceListener(self::DECISION_EVENT, OutboundSendDecisionRequestedListener::class);
		$dispatcher->addServiceListener(self::CHANGE_EVENT, OptOutChangeRequestedListener::class);

		return $dispatcher;

	}//end dispatcher()

}//end class
