<?php

/**
 * Who a message went to, who was kept off it, and why the record says so.
 *
 * 🔴 THE RESOLVED LIST ALONE LOSES THE ONE FACT ANYBODY ASKS ABOUT LATER. From
 * "these three addresses received it", a recipient deliberately kept off a
 * beschikking, a recipient who was never on the list, and a bug that dropped
 * one all look identical. So all three parts are recorded and the suppressed
 * recipient stays in, marked.
 *
 * The refusals stop the send rather than correcting it, because every available
 * correction is a lie: defaulting a missing reason to "no reason given" makes
 * the record complete and useless, and quietly honouring a suppression of a
 * required recipient is integriq overriding a municipality's own legal advice
 * about its own letters.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Recipients
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Recipients;

use DateTimeImmutable;
use OCA\Integriq\Service\Recipients\RecipientDecision;
use OCA\Integriq\Service\Recipients\RecipientRefusedException;
use OCA\Integriq\Service\Recipients\RecipientResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RecipientResolver and the decision it records.
 */
class RecipientResolverTest extends TestCase {

	private RecipientResolver $resolver;

	/**
	 * Wire the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = new RecipientResolver();
	}//end setUp()

	/**
	 * Three standing recipients, one of them required by the caller.
	 *
	 * @return array<int, array<string, mixed>> The standing list.
	 */
	private function standing(): array {
		return [
			[
				'address' => 'aanvrager@example.nl',
				'name' => 'Fatima El-Amrani',
				'requiredBecause' => 'the applicant must receive the beschikking under Awb 3:41',
			],
			['address' => 'behandelaar@gemeente.nl', 'name' => 'J. de Vries'],
			['address' => 'archief@gemeente.nl', 'name' => 'Archief'],
		];
	}//end standing()

	/**
	 * A frozen moment.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-18T10:00:00+00:00');
	}//end now()

	/**
	 * 🔴 ALL THREE PARTS ARE RECORDED, AND THE SUPPRESSED RECIPIENT STAYS IN.
	 *
	 * @return void
	 */
	public function testTheRecordCarriesAllThreeParts(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			additions: [['address' => 'gemachtigde@advocaat.nl']],
			suppressions: [['address' => 'archief@gemeente.nl', 'reason' => 'the archive gets it through the ZTC run']],
			user: 'jdevries',
			now: $this->now()
		);

		$record = $decision->asRecord();

		$this->assertCount(2, $record['standing']);
		$this->assertCount(1, $record['added']);
		$this->assertCount(1, $record['suppressed']);
		$this->assertSame('archief@gemeente.nl', $record['suppressed'][0]['address']);
	}//end testTheRecordCarriesAllThreeParts()

	/**
	 * The transport gets standing minus suppressed, plus added.
	 *
	 * @return void
	 */
	public function testTheTransportReceivesTheResolvedList(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			additions: [['address' => 'gemachtigde@advocaat.nl']],
			suppressions: [['address' => 'archief@gemeente.nl', 'reason' => 'the archive gets it through the ZTC run']],
			now: $this->now()
		);

		$this->assertSame(
			['aanvrager@example.nl', 'behandelaar@gemeente.nl', 'gemachtigde@advocaat.nl'],
			$decision->forTransport()
		);
	}//end testTheTransportReceivesTheResolvedList()

	/**
	 * 🔴 A SUPPRESSED RECIPIENT CARRIES NO DELIVERY STATE, AND ABOVE ALL NOT
	 * "not reported". That reads as a transport that never answered, and sends
	 * somebody chasing a provider about a message nobody sent.
	 *
	 * @return void
	 */
	public function testASuppressedRecipientHasNoDeliveryState(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			suppressions: [['address' => 'archief@gemeente.nl', 'reason' => 'the archive gets it through the ZTC run']],
			now: $this->now()
		);

		$suppressed = $decision->suppressed[0];

		$this->assertNull($suppressed['deliveryState']);
		$this->assertSame(RecipientDecision::SUPPRESSED, $suppressed['part']);
		$this->assertNotContains('not_reported', array_values($suppressed));
	}//end testASuppressedRecipientHasNoDeliveryState()

	/**
	 * A year later the record says why, who and when.
	 *
	 * @return void
	 */
	public function testTheSuppressionCarriesItsReasonUserAndTime(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			suppressions: [['address' => 'archief@gemeente.nl', 'reason' => 'the archive gets it through the ZTC run']],
			user: 'jdevries',
			now: $this->now()
		);

		$suppressed = $decision->suppressed[0];

		$this->assertSame('the archive gets it through the ZTC run', $suppressed['reason']);
		$this->assertSame('jdevries', $suppressed['suppressedBy']);
		$this->assertStringContainsString('2026-09-18', $suppressed['suppressedAt']);
	}//end testTheSuppressionCarriesItsReasonUserAndTime()

	/**
	 * 🔴 A SUPPRESSION WITHOUT A REASON STOPS THE SEND. Defaulting it to "no
	 * reason given" would make the record complete and useless.
	 *
	 * @return void
	 */
	public function testASuppressionWithoutAReasonIsRefused(): void {
		$this->expectException(RecipientRefusedException::class);
		$this->expectExceptionMessageMatches('/needs a reason/');

		$this->resolver->resolve(
			standing: $this->standing(),
			suppressions: [['address' => 'archief@gemeente.nl', 'reason' => '   ']],
			now: $this->now()
		);
	}//end testASuppressionWithoutAReasonIsRefused()

	/**
	 * 🔴 A REQUIRED RECIPIENT CANNOT BE DROPPED, AND THE REFUSAL REPEATS THE
	 * CALLER'S OWN WORDS. "This recipient is required" gives a handler nothing
	 * to argue with or escalate.
	 *
	 * @return void
	 */
	public function testARequiredRecipientCannotBeSuppressedAndTheReasonIsRepeated(): void {
		try {
			$this->resolver->resolve(
				standing: $this->standing(),
				suppressions: [['address' => 'aanvrager@example.nl', 'reason' => 'she asked us not to write']],
				now: $this->now()
			);
			$this->fail('the resolver should have refused');
		} catch (RecipientRefusedException $refusal) {
			$this->assertStringContainsString('Awb 3:41', $refusal->getMessage());
		}
	}//end testARequiredRecipientCannotBeSuppressedAndTheReasonIsRepeated()

	/**
	 * 🔴 INTEGRIQ DOES NOT DECIDE WHAT IS REQUIRED. Only a recipient the CALLER
	 * marked is protected; an unmarked one may be suppressed with a reason.
	 *
	 * @return void
	 */
	public function testAnUnmarkedRecipientMayBeSuppressed(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			suppressions: [['address' => 'behandelaar@gemeente.nl', 'reason' => 'on leave, colleague is handling it']],
			now: $this->now()
		);

		$this->assertCount(1, $decision->suppressed);
		$this->assertNotContains('behandelaar@gemeente.nl', $decision->forTransport());
	}//end testAnUnmarkedRecipientMayBeSuppressed()

	/**
	 * Suppressing somebody who is not on the list is refused: it would record a
	 * decision that changed nothing while reading as one that was carried out.
	 *
	 * @return void
	 */
	public function testSuppressingSomebodyNotOnTheListIsRefused(): void {
		$this->expectException(RecipientRefusedException::class);
		$this->expectExceptionMessageMatches('/not on this message/');

		$this->resolver->resolve(
			standing: $this->standing(),
			suppressions: [['address' => 'iemand@anders.nl', 'reason' => 'a reason']],
			now: $this->now()
		);
	}//end testSuppressingSomebodyNotOnTheListIsRefused()

	/**
	 * Every refusal is reported, not the first, so a handler correcting one
	 * does not meet the next on the retry.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsReportedNotOnlyTheFirst(): void {
		$refusals = $this->resolver->refusals(
			standing: $this->standing(),
			suppressions: [
				['address' => 'aanvrager@example.nl', 'reason' => ''],
				['address' => 'iemand@anders.nl', 'reason' => 'x'],
			]
		);

		// The applicant is both unreasoned and required; the stranger is absent.
		$this->assertCount(3, $refusals);
	}//end testEveryRefusalIsReportedNotOnlyTheFirst()

	/**
	 * 🔴 AN ADDITION APPLIES TO ONE MESSAGE ONLY. A second message for the same
	 * subject resolves without it, and the resolver has nothing to write to, so
	 * no standing list, party record or subject can have been touched.
	 *
	 * @return void
	 */
	public function testAnAdditionDoesNotSurviveToTheNextMessage(): void {
		$standing = $this->standing();

		$first = $this->resolver->resolve(
			standing: $standing,
			additions: [['address' => 'gemachtigde@advocaat.nl']],
			now: $this->now()
		);
		$second = $this->resolver->resolve(standing: $standing, now: $this->now());

		$this->assertContains('gemachtigde@advocaat.nl', $first->forTransport());
		$this->assertNotContains('gemachtigde@advocaat.nl', $second->forTransport());
		$this->assertSame($this->standing(), $standing, 'the caller\'s standing list must come back unchanged');
	}//end testAnAdditionDoesNotSurviveToTheNextMessage()

	/**
	 * 🔴 THE PREVIEW RUNS THE SEND'S RESOLUTION. A second implementation drifts,
	 * and the first time anybody notices is when a handler has approved a list
	 * the message did not go to.
	 *
	 * @return void
	 */
	public function testThePreviewIsTheSameAnswerAsTheSend(): void {
		$arguments = [
			'standing' => $this->standing(),
			'additions' => [['address' => 'gemachtigde@advocaat.nl']],
			'suppressions' => [['address' => 'archief@gemeente.nl', 'reason' => 'via the ZTC run']],
			'user' => 'jdevries',
			'now' => $this->now(),
		];

		$preview = $this->resolver->preview(...$arguments);
		$sent = $this->resolver->resolve(...$arguments)->asRecord();

		$this->assertSame($sent, $preview);
	}//end testThePreviewIsTheSameAnswerAsTheSend()

	/**
	 * The preview refuses exactly what the send refuses, so nothing can be
	 * approved that would then be rejected.
	 *
	 * @return void
	 */
	public function testThePreviewRefusesWhatTheSendRefuses(): void {
		$this->expectException(RecipientRefusedException::class);

		$this->resolver->preview(
			standing: $this->standing(),
			suppressions: [['address' => 'aanvrager@example.nl', 'reason' => 'x']],
			now: $this->now()
		);
	}//end testThePreviewRefusesWhatTheSendRefuses()

	/**
	 * A clean request is refused nothing, so the refusals are not a blanket.
	 *
	 * @return void
	 */
	public function testACleanRequestIsAccepted(): void {
		$this->assertSame([], $this->resolver->refusals(standing: $this->standing(), suppressions: []));
	}//end testACleanRequestIsAccepted()

	/**
	 * One address appearing twice reaches the transport once, so nobody gets a
	 * beschikking in duplicate because they were added as well as standing.
	 *
	 * @return void
	 */
	public function testAnAddressIsNotDeliveredTwice(): void {
		$decision = $this->resolver->resolve(
			standing: $this->standing(),
			additions: [['address' => 'behandelaar@gemeente.nl']],
			now: $this->now()
		);

		$addresses = $decision->forTransport();

		$this->assertSame(count($addresses), count(array_unique($addresses)));
	}//end testAnAddressIsNotDeliveredTwice()
}//end class
