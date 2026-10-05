<?php

/**
 * Unit tests for the one opt-out decision function and the change record.
 *
 * Through the real registry, categories, recipient key and token service.
 * Only the tables, config, clock and random source are doubles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests decideMany(), the exempt floor, consent, replies, record() and erasure.
 */
class OptOutRegistryTest extends TestCase {

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
	 * A batch of five with two opt-outs: two refused, three allowed, one read.
	 *
	 * @return void
	 */
	public function testABatchIsAnsweredPerRecipient(): void {
		$registry = $this->fx->registry();
		$registry->add('b@example.org');
		$registry->add('d@example.org');
		$recipients = array_map(static fn (string $a): array => ['address' => $a], ['a@example.org', 'b@example.org', 'c@example.org', 'D@Example.org', 'e@example.org']);

		$decisions = $registry->decideMany('email', 'service', false, $recipients, 'openregister', 'c-0');

		$this->assertCount(5, $decisions);
		$codes = array_map(static fn (array $d): string => $d['code'], $decisions);
		$this->assertSame(
			['a@example.org' => 'allowed', 'b@example.org' => 'opted-out', 'c@example.org' => 'allowed', 'D@Example.org' => 'opted-out', 'e@example.org' => 'allowed'],
			$codes
		);
		$this->assertFalse($decisions['b@example.org']['send']);
		$this->assertNotNull($decisions['a@example.org']['unsubscribe']);
		$this->assertNull($decisions['b@example.org']['unsubscribe']);
		$this->assertSame(1, $this->fx->table->batchReads, 'one read for the batch');

	}//end testABatchIsAnsweredPerRecipient()

	/**
	 * Five hundred with one suppression write one suppressed and one count row.
	 *
	 * @return void
	 */
	public function testABatchWithOneSuppressionWritesTwoLogRows(): void {
		$registry = $this->fx->registry();
		$registry->add('r7@example.org');
		$recipients = [];
		for ($i = 0; $i < 500; $i++) {
			$recipients[] = ['address' => 'r' . $i . '@example.org'];
		}

		$started = hrtime(true);
		$registry->decideMany('email', 'service', false, $recipients, 'openregister', 'c-1');
		$elapsedMs = (hrtime(true) - $started) / 1e6;

		$rows = $this->fx->log->findPage(50, 0, 'c-1');
		$this->assertCount(2, $rows);
		$this->assertCount(1, $this->fx->log->ofKind(OptOutLogEntry::KIND_SUPPRESSED));
		$count = $this->fx->log->ofKind(OptOutLogEntry::KIND_ALLOWED_COUNT)[0];
		$this->assertSame(499, $count->detailArray()['count']);
		$this->assertSame('openregister', $count->getSourceApp());
		$this->assertLessThan(2000, $elapsedMs);

	}//end testABatchWithOneSuppressionWritesTwoLogRows()

	/**
	 * A phone opt-out matches the same number written another way.
	 *
	 * @return void
	 */
	public function testAPhoneOptOutMatchesEveryNotation(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'instance', 'sourceApp' => 'integriq']);

		$decision = $registry->decideMany('sms', 'service', false, [['address' => '06 1234 5678']], 'integriq', '')['06 1234 5678'];

		$this->assertFalse($decision['send']);
		$this->assertSame('opted-out', $decision['code']);
		$this->assertSame('+31612345678', $decision['address']);

	}//end testAPhoneOptOutMatchesEveryNotation()

	/**
	 * An address that does not normalise is refused, not sent.
	 *
	 * @return void
	 */
	public function testAnInvalidAddressIsRefused(): void {
		$decision = $this->fx->registry()->decideMany('sms', 'service', false, [['address' => 'not a number']], 'integriq', '')['not a number'];

		$this->assertFalse($decision['send']);
		$this->assertSame('invalid-address', $decision['code']);

	}//end testAnInvalidAddressIsRefused()

	/**
	 * Config cannot take besluit off the floor.
	 *
	 * @return void
	 */
	public function testConfigCannotRemoveBesluit(): void {
		$this->fx->config[OptOutCategories::CONFIG_PROTECTED] = json_encode(['statutory']);
		$registry = $this->fx->registry();
		$registry->add('jan@example.org');

		$decision = $registry->decideMany('email', 'besluit', false, [['address' => 'jan@example.org']], 'dossiq', 'c-2')['jan@example.org'];

		$this->assertTrue($decision['send']);
		$this->assertTrue($decision['overridden']);
		$this->assertSame('exempt-override', $decision['code']);
		$this->assertNull($decision['unsubscribe'], 'an exempt message carries no link');
		$this->assertCount(1, $this->fx->log->ofKind(OptOutLogEntry::KIND_OVERRIDE));

	}//end testConfigCannotRemoveBesluit()

	/**
	 * Config cannot make reminders exempt, and says so.
	 *
	 * @return void
	 */
	public function testConfigCannotMakeRemindersExempt(): void {
		$this->fx->config[OptOutCategories::CONFIG_PROTECTED] = json_encode(['reminder']);
		$registry = $this->fx->registry();
		$registry->add('jan@example.org');

		$decision = $registry->decideMany('email', 'reminder', false, [['address' => 'jan@example.org']], 'pipelinq', '')['jan@example.org'];

		$this->assertFalse($decision['send']);
		$this->assertNotEmpty(array_filter($this->fx->warnings, static fn (string $w): bool => str_contains($w, 'ignored a value')));

	}//end testConfigCannotMakeRemindersExempt()

	/**
	 * The aliases stay, and an unknown category reads as service with a warning.
	 *
	 * @return void
	 */
	public function testAliasesStayAndUnknownReadsAsService(): void {
		$categories = $this->fx->categories();

		$this->assertTrue($categories->isExempt('ontvangstbevestiging'));
		$this->assertTrue($categories->isExempt('invordering'));
		$this->assertTrue($categories->isExempt('account'));
		$this->assertTrue($categories->isExempt('security'));
		$this->assertFalse($categories->isExempt('case-update'));
		$this->assertSame('service', $categories->canonical('nieuwsbrief-ish', null, 'dossiq'));
		$this->assertNotEmpty(array_filter($this->fx->warnings, static fn (string $w): bool => str_contains($w, 'unknown message category')));

	}//end testAliasesStayAndUnknownReadsAsService()

	/**
	 * A list consent does not open the channel.
	 *
	 * @return void
	 */
	public function testAListConsentDoesNotOpenTheChannel(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'list', 'ref' => 'nieuws', 'channel' => 'email', 'lawfulBasis' => 'consent']);

		$noList = $registry->decideMany('email', 'marketing', true, [['address' => 'jan@example.nl']], 'pipelinq', '')['jan@example.nl'];
		$list = $registry->decideMany('email', 'marketing', true, [['address' => 'jan@example.nl', 'listRef' => 'nieuws']], 'pipelinq', '')['jan@example.nl'];

		$this->assertSame('no-consent', $noList['code']);
		$this->assertFalse($noList['send']);
		$this->assertTrue($list['send']);

	}//end testAListConsentDoesNotOpenTheChannel()

	/**
	 * A channel consent does not open a list.
	 *
	 * @return void
	 */
	public function testAChannelConsentDoesNotOpenAList(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);

		$this->assertTrue($registry->decideMany('email', 'marketing', true, [['address' => 'jan@example.nl']], 'pipelinq', '')['jan@example.nl']['send']);
		$this->assertFalse($registry->decideMany('email', 'marketing', true, [['address' => 'jan@example.nl', 'listRef' => 'nieuws']], 'pipelinq', '')['jan@example.nl']['send']);

	}//end testAChannelConsentDoesNotOpenAList()

	/**
	 * An imported consent never permits; a soft opt-in needs the objection.
	 *
	 * @return void
	 */
	public function testTheLawfulBasisMustPermitTheSend(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'imp@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'imported']);
		$registry->record(['address' => 'soft@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'soft-opt-in']);
		$registry->record(['address' => 'ok@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'soft-opt-in', 'evidence' => ['objectionOffered' => true]]);

		$decisions = $registry->decideMany(
			'email',
			'marketing',
			true,
			[['address' => 'imp@example.nl'], ['address' => 'soft@example.nl'], ['address' => 'ok@example.nl']],
			'pipelinq',
			''
		);

		$this->assertSame('no-consent', $decisions['imp@example.nl']['code']);
		$this->assertSame('no-consent', $decisions['soft@example.nl']['code']);
		$this->assertSame('allowed', $decisions['ok@example.nl']['code']);

	}//end testTheLawfulBasisMustPermitTheSend()

	/**
	 * An opt-out wins over an opt-in.
	 *
	 * @return void
	 */
	public function testAnOptOutWinsOverAConsent(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'list', 'ref' => 'nieuws', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$registry->add('jan@example.nl');

		$decision = $registry->decideMany('email', 'marketing', true, [['address' => 'jan@example.nl', 'listRef' => 'nieuws']], 'pipelinq', '')['jan@example.nl'];

		$this->assertSame('opted-out', $decision['code']);

	}//end testAnOptOutWinsOverAConsent()

	/**
	 * A reply to the citizen's own message passes an opt-out, with no link.
	 *
	 * @return void
	 */
	public function testAReplyWithInReplyToPassesAnOptOut(): void {
		$registry = $this->fx->registry();
		$registry->add('+31612345678');

		$decision = $registry->decideMany('messaging', 'reply', false, [['address' => '0612345678']], 'integriq', '', '', 'intake-1')['0612345678'];

		$this->assertTrue($decision['send']);
		$this->assertSame('reply', $decision['category']);
		$this->assertNull($decision['unsubscribe']);

	}//end testAReplyWithInReplyToPassesAnOptOut()

	/**
	 * A reply without a reference reads as service, so the opt-out stops it.
	 *
	 * @return void
	 */
	public function testAReplyWithoutAReferenceIsAnUpdate(): void {
		$registry = $this->fx->registry();
		$registry->add('jan@example.org');

		$decision = $registry->decideMany('email', 'reply', false, [['address' => 'jan@example.org']], 'dossiq', '')['jan@example.org'];

		$this->assertFalse($decision['send']);
		$this->assertSame('opted-out', $decision['code']);

	}//end testAReplyWithoutAReferenceIsAnUpdate()

	/**
	 * A case opt-out stops a case update on that case only; a contact match counts.
	 *
	 * @return void
	 */
	public function testCaseAndContactMatches(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'old@example.nl', 'state' => 'opted-out', 'scope' => 'case', 'ref' => 'Z-2026-001', 'contactRef' => 'c-9']);

		$decisions = $registry->decideMany(
			'email',
			'case-update',
			false,
			[
				['address' => 'old@example.nl', 'caseRef' => 'Z-2026-001'],
				['address' => 'old@example.nl', 'caseRef' => 'Z-2026-002'],
			],
			'dossiq',
			''
		);
		$this->assertCount(1, $decisions, 'keyed by address: the second overwrites the first');
		$this->assertTrue($decisions['old@example.nl']['send']);

		$moved = $registry->decideMany('email', 'case-update', false, [['address' => 'new@example.nl', 'caseRef' => 'Z-2026-001', 'contactRef' => 'c-9']], 'dossiq', '');
		$this->assertFalse($moved['new@example.nl']['send'], 'the contact still matches after the address changed');

	}//end testCaseAndContactMatches()

	/**
	 * STOP then START: one row reading opted-in, two change entries.
	 *
	 * @return void
	 */
	public function testStopThenStartLeavesOneOptedInRow(): void {
		$registry = $this->fx->registry();
		$stop = ['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms', 'source' => 'keyword-stop', 'sourceApp' => 'pipelinq'];

		$first = $registry->record($stop);
		$second = $registry->record(array_merge($stop, ['state' => 'opted-in', 'source' => 'keyword-start', 'lawfulBasis' => 'consent']));

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->fx->table->countAll());
		$row = array_values($this->fx->table->rows)[0];
		$this->assertSame(OptOut::STATE_OPTED_IN, $row->getState());
		$changes = $this->fx->log->ofKind(OptOutLogEntry::KIND_CHANGE);
		$this->assertCount(2, $changes);
		$this->assertSame('opted-out', $changes[0]->detailArray()['state']);
		$this->assertSame('pipelinq', $changes[0]->getSourceApp());

	}//end testStopThenStartLeavesOneOptedInRow()

	/**
	 * A migrated record is written once and returns its id the second time.
	 *
	 * @return void
	 */
	public function testALegacyRefIsWrittenOnce(): void {
		$registry = $this->fx->registry();
		$request = ['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'legacyRef' => 'pq-123', 'sourceApp' => 'pipelinq'];

		$first = $registry->record($request);
		$second = $registry->record(array_merge($request, ['state' => 'opted-in']));

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->fx->table->countAll());
		$this->assertSame(OptOut::STATE_OPTED_OUT, array_values($this->fx->table->rows)[0]->getState());
		$this->assertCount(1, $this->fx->log->ofKind(OptOutLogEntry::KIND_CHANGE));

	}//end testALegacyRefIsWrittenOnce()

	/**
	 * An erased contact stays opted out; its link and evidence go.
	 *
	 * @return void
	 */
	public function testAnErasedContactStaysOptedOut(): void {
		$registry = $this->fx->registry();
		$registry->record(['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'contactRef' => 'c-1', 'evidence' => ['form' => 'preference-centre']]);

		$cleared = $registry->record(['state' => 'erase-contact', 'contactRef' => 'c-1', 'sourceApp' => 'pipelinq']);

		$this->assertSame(1, $cleared);
		$row = array_values($this->fx->table->rows)[0];
		$this->assertSame(OptOut::STATE_OPTED_OUT, $row->getState());
		$this->assertSame('jan@example.nl', $row->getAddress());
		$this->assertSame('', $row->getContactRef());
		$this->assertNull($row->getEvidence());
		$entry = $this->fx->log->ofKind(OptOutLogEntry::KIND_CHANGE)[1];
		$this->assertSame('erase-contact', $entry->detailArray()['state']);
		$this->assertArrayNotHasKey('evidence', $entry->detailArray());
		$this->assertFalse($registry->decideMany('email', 'service', false, [['address' => 'jan@example.nl']], 'x', '')['jan@example.nl']['send']);

	}//end testAnErasedContactStaysOptedOut()

	/**
	 * A digital post BSN is stored, logged and minted as a hash only.
	 *
	 * @return void
	 */
	public function testADigitalPostOptOutHoldsNoBsn(): void {
		$registry = $this->fx->registry();
		$decision = $registry->decideMany('digital-post', 'case-update', false, [['address' => '999993653', 'caseRef' => 'Z-1']], 'dossiq', 'c-3')['999993653'];

		$this->assertStringStartsWith('bsn:', $decision['address']);
		$this->assertStringNotContainsString('999993653', $decision['address']);
		$token = basename((string)$decision['unsubscribe']['url']);
		$claim = $this->fx->tokens()->inspect($token);
		$this->assertSame($decision['address'], $claim['address']);
		$this->assertStringNotContainsString('999993653', (string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/')));

		$registry->record(['address' => $claim['address'], 'state' => 'opted-out', 'scope' => $claim['scope'], 'channel' => $claim['channel'], 'ref' => $claim['ref']]);
		$row = array_values($this->fx->table->rows)[0];
		$this->assertStringStartsWith('bsn:', $row->getAddress());
		$this->assertStringNotContainsString('999993653', $row->getAddress());
		foreach ($this->fx->log->rows as $entry) {
			$this->assertStringNotContainsString('999993653', json_encode($entry->jsonSerialize()));
		}

		$again = $registry->decideMany('digital-post', 'case-update', false, [['address' => '999993653', 'caseRef' => 'Z-1']], 'dossiq', '')['999993653'];
		$this->assertSame('opted-out', $again['code']);

	}//end testADigitalPostOptOutHoldsNoBsn()

	/**
	 * The SMS text is at most 50 characters and resolves to the token.
	 *
	 * @return void
	 */
	public function testTheSmsTextIsShort(): void {
		$this->fx->baseUrl = 'https://gemeente.nl';
		$decision = $this->fx->registry()->decideMany('sms', 'service', false, [['address' => '0612345678']], 'integriq', '')['0612345678'];

		$text = (string)$decision['unsubscribe']['smsText'];
		$this->assertLessThanOrEqual(UnsubscribeTokenService::SMS_TEXT_MAX, mb_strlen($text));
		$this->assertMatchesRegularExpression('#^Stop: gemeente\.nl/apps/integriq/u/[A-Za-z0-9]{10}$#', $text);
		$shortId = substr($text, -10);
		$token = $this->fx->tokens()->resolveShort($shortId);
		$this->assertNotNull($token);
		$this->assertSame('+31612345678', $this->fx->tokens()->inspect($token)['address']);
		$this->assertSame('channel', $this->fx->tokens()->inspect($token)['scope']);

	}//end testTheSmsTextIsShort()

	/**
	 * A host too long for 50 characters gets no SMS text and a warning, never a long one.
	 *
	 * @return void
	 */
	public function testALongHostGetsNoSmsTextRatherThanALongOne(): void {
		$this->fx->baseUrl = 'https://a-very-long-municipality-hostname.example.nl';
		$decision = $this->fx->registry()->decideMany('sms', 'service', false, [['address' => '0612345678']], 'integriq', '')['0612345678'];
		$this->assertNull($decision['unsubscribe']['smsText']);

		$this->fx->config[UnsubscribeTokenService::CONFIG_SHORT_BASE] = 'stop.gem.nl';
		$decision = $this->fx->registry()->decideMany('sms', 'service', false, [['address' => '0612345678']], 'integriq', '')['0612345678'];
		$this->assertMatchesRegularExpression('#^Stop: stop\.gem\.nl/u/[A-Za-z0-9]{10}$#', (string)$decision['unsubscribe']['smsText']);

	}//end testALongHostGetsNoSmsTextRatherThanALongOne()

	/**
	 * A broken table refuses a case update and still sends a besluit.
	 *
	 * @return void
	 */
	public function testOwnSendersFailClosed(): void {
		$this->fx->table->failReads = true;
		$registry = $this->fx->registry();

		$update = $registry->decideForSend('email', 'case-update', [['address' => 'jan@example.org']]);
		$besluit = $registry->decideForSend('email', 'besluit', [['address' => 'jan@example.org']]);

		$this->assertSame('authority-unavailable', $update['jan@example.org']['code']);
		$this->assertFalse($update['jan@example.org']['send']);
		$this->assertTrue($besluit['jan@example.org']['send']);

	}//end testOwnSendersFailClosed()

	/**
	 * The rollback flag turns the check off in integriq's own senders.
	 *
	 * @return void
	 */
	public function testTheRollbackFlagTurnsTheCheckOff(): void {
		$registry = $this->fx->registry();
		$registry->add('jan@example.org');
		$this->fx->config[OptOutRegistry::CONFIG_AUTHORITY] = 'false';

		$this->assertFalse($registry->isAuthorityEnabled());
		$this->assertTrue($registry->decideForSend('email', 'service', [['address' => 'jan@example.org']])['jan@example.org']['send']);

	}//end testTheRollbackFlagTurnsTheCheckOff()

}//end class
