<?php

/**
 * Signed by default, unsigned only by a decision somebody signs their name to.
 *
 * 🔴 THE OPPOSITE DEFAULT IS WHAT MOST OUTBOUND WEBHOOKS SHIP WITH: unsigned
 * unless configured, so every receiver that never got round to verification
 * keeps working and nobody finds out which ones those are until somebody asks.
 * A receiver cannot tell a request from integriq from a request that merely
 * says it is, and neither can integriq's own delivery log.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Subscriptions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Subscriptions;

use DateTimeImmutable;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicy;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SubscriptionSigningPolicy.
 */
class SubscriptionSigningPolicyTest extends TestCase {

	private SubscriptionSigningPolicy $policy;

	/**
	 * Wire the policy over the real signature service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$signatures = $this->getMockBuilder(WebhookSignatureService::class)
			->disableOriginalConstructor()
			->onlyMethods(['generateSecret'])
			->getMock();
		$signatures->method('generateSecret')->willReturn('whsec_testsecret');

		$this->policy = new SubscriptionSigningPolicy(signatures: $signatures);
	}//end setUp()

	/**
	 * A frozen moment.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-18T10:00:00+00:00');
	}//end now()

	/**
	 * 🔴 A NEW PUSH SUBSCRIPTION IS SIGNED WITHOUT ANYBODY ASKING.
	 *
	 * @return void
	 */
	public function testANewPushSubscriptionIsSignedByDefault(): void {
		$settings = $this->policy->settingsForNew(
			subscription: ['style' => 'push', 'sink' => 'https://ontvanger.nl/hook'],
			now: $this->now()
		);

		$this->assertSame('whsec_testsecret', $settings['signingSecret']);
	}//end testANewPushSubscriptionIsSignedByDefault()

	/**
	 * A pull subscription is left alone, so the default is scoped to push.
	 *
	 * @return void
	 */
	public function testAPullSubscriptionGetsNoSecret(): void {
		$settings = $this->policy->settingsForNew(subscription: ['style' => 'pull'], now: $this->now());

		$this->assertArrayNotHasKey('signingSecret', $settings);
	}//end testAPullSubscriptionGetsNoSecret()

	/**
	 * 🔴 UNSIGNED WITHOUT A REASON IS REFUSED. Turning signing off to make a
	 * stubborn receiver work is legitimate; leaving nothing to say why is how
	 * it is still off two years later when nobody remembers the receiver was
	 * supposed to be fixed.
	 *
	 * @return void
	 */
	public function testUnsignedWithoutAReasonIsRefused(): void {
		$refusal = $this->policy->refuse(
			subscription: ['style' => 'push', 'protocolSettings' => ['unsigned' => []]]
		);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('needs a reason', $refusal);
	}//end testUnsignedWithoutAReasonIsRefused()

	/**
	 * A blank reason is not a reason.
	 *
	 * @return void
	 */
	public function testABlankReasonIsRefused(): void {
		$this->assertNotNull(
			$this->policy->refuse(subscription: ['style' => 'push', 'protocolSettings' => ['unsigned' => ['reason' => '  ']]])
		);
	}//end testABlankReasonIsRefused()

	/**
	 * Unsigned WITH a reason is accepted, and the reason, user and time are
	 * stored, so the refusal is not a blanket ban on unsigned delivery.
	 *
	 * @return void
	 */
	public function testUnsignedWithAReasonIsAcceptedAndRecorded(): void {
		$subscription = [
			'style' => 'push',
			'protocolSettings' => ['unsigned' => ['reason' => 'receiver cannot verify HMAC yet']],
		];

		$this->assertNull($this->policy->refuse(subscription: $subscription));

		$settings = $this->policy->settingsForNew(subscription: $subscription, user: 'jdevries', now: $this->now());

		$this->assertSame('receiver cannot verify HMAC yet', $settings['unsigned']['reason']);
		$this->assertSame('jdevries', $settings['unsigned']['setBy']);
		$this->assertStringContainsString('2026-09-18', $settings['unsigned']['setAt']);
		$this->assertArrayNotHasKey('signingSecret', $settings);
	}//end testUnsignedWithAReasonIsAcceptedAndRecorded()

	/**
	 * A signed subscription is refused nothing.
	 *
	 * @return void
	 */
	public function testASignedSubscriptionIsNotRefused(): void {
		$this->assertNull($this->policy->refuse(subscription: ['style' => 'push', 'protocolSettings' => []]));
	}//end testASignedSubscriptionIsNotRefused()

	/**
	 * 🔴 AN EXISTING SUBSCRIPTION NEVER GAINS A SECRET ON UPGRADE. Retrofitting
	 * one starts signing to a receiver that was never given the secret: the
	 * strict ones begin rejecting deliveries, the lax ones ignore the header,
	 * and either way an operator now believes a channel is verified that is not.
	 *
	 * @return void
	 */
	public function testAnExistingSubscriptionDoesNotGainASecret(): void {
		$settings = $this->policy->settingsForExisting(
			existing: ['style' => 'push', 'protocolSettings' => []],
			incoming: ['style' => 'push', 'protocolSettings' => []],
			now: $this->now()
		);

		$this->assertArrayNotHasKey('signingSecret', $settings);
	}//end testAnExistingSubscriptionDoesNotGainASecret()

	/**
	 * 🔴 AND A SAVE THAT DOES NOT MENTION THE SECRET DOES NOT LOSE IT. An
	 * ordinary edit of a sink or a filter must never silently unsign a channel
	 * that was signed a moment ago.
	 *
	 * @return void
	 */
	public function testAnOrdinaryEditDoesNotUnsignASignedSubscription(): void {
		$settings = $this->policy->settingsForExisting(
			existing: ['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_existing']],
			incoming: ['style' => 'push', 'protocolSettings' => []],
			now: $this->now()
		);

		$this->assertSame('whsec_existing', $settings['signingSecret']);
	}//end testAnOrdinaryEditDoesNotUnsignASignedSubscription()

	/**
	 * The stored secret is redacted on every read.
	 *
	 * @return void
	 */
	public function testTheSecretIsRedactedOnRead(): void {
		$read = $this->policy->forReading(
			subscription: ['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_realsecret']]
		);

		$this->assertSame(SubscriptionSigningPolicy::REDACTED, $read['protocolSettings']['signingSecret']);
		$this->assertStringNotContainsString('whsec_realsecret', (string)json_encode($read));
	}//end testTheSecretIsRedactedOnRead()

	/**
	 * 🔴 THE LIST MARKS THE UNSIGNED ONE. A list showing only "push" beside both
	 * leaves an operator to open every subscription in turn to find the unsigned
	 * ones, which is the same as not telling them.
	 *
	 * @return void
	 */
	public function testTheReadMarksAnUnsignedSubscriptionWithItsReason(): void {
		$read = $this->policy->forReading(
			subscription: [
				'style' => 'push',
				'protocolSettings' => ['unsigned' => ['reason' => 'receiver cannot verify HMAC yet']],
			]
		);

		$this->assertSame(SubscriptionSigningPolicy::ATTEMPT_UNSIGNED, $read['signingPosture']);
		$this->assertSame('receiver cannot verify HMAC yet', $read['unsignedReason']);
	}//end testTheReadMarksAnUnsignedSubscriptionWithItsReason()

	/**
	 * A signed one is not marked, so the mark means something.
	 *
	 * @return void
	 */
	public function testASignedSubscriptionIsNotMarked(): void {
		$read = $this->policy->forReading(
			subscription: ['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_x']]
		);

		$this->assertSame(SubscriptionSigningPolicy::ATTEMPT_SIGNED, $read['signingPosture']);
		$this->assertNull($read['unsignedReason']);
	}//end testASignedSubscriptionIsNotMarked()

	/**
	 * 🔴 EVERY ATTEMPT RECORDS ITS SIGNING, INCLUDING RETRIES AND REPLAYS. A log
	 * that records it only on the first try cannot answer the one question a
	 * receiver asks after a bad night: was the request I got at 03:14 signed?
	 *
	 * @return void
	 */
	public function testEveryKindOfAttemptRecordsWhetherItWasSigned(): void {
		$unsigned = ['style' => 'push', 'protocolSettings' => ['unsigned' => ['reason' => 'x']]];

		foreach (['immediate', 'retry', 'replay'] as $kind) {
			$record = $this->policy->attemptRecord(subscription: $unsigned, kind: $kind);

			$this->assertSame($kind, $record['kind']);
			$this->assertFalse($record['signed']);
			$this->assertSame(SubscriptionSigningPolicy::ATTEMPT_UNSIGNED, $record['signingPosture']);
		}
	}//end testEveryKindOfAttemptRecordsWhetherItWasSigned()

	/**
	 * A signed subscription's attempts record that they were signed.
	 *
	 * @return void
	 */
	public function testASignedAttemptRecordsThatItWasSigned(): void {
		$record = $this->policy->attemptRecord(
			subscription: ['style' => 'push', 'protocolSettings' => ['signingSecret' => 'whsec_x']],
			kind: 'replay'
		);

		$this->assertTrue($record['signed']);
	}//end testASignedAttemptRecordsThatItWasSigned()

	/**
	 * 🔴 THE RECIPE IS AVAILABLE WHETHER OR NOT THE SECRET IS REVEALED. The
	 * person integrating the receiving end is usually not the person who
	 * created the subscription and will never see the reveal, so a recipe that
	 * appears only at creation is a recipe nobody reads.
	 *
	 * @return void
	 */
	public function testTheVerificationRecipeIsAlwaysAvailableAndComplete(): void {
		$recipe = $this->policy->verificationRecipe();

		$this->assertSame('X-OpenConnector-Signature', $recipe['header']);
		$this->assertSame('t=<unix-ts>,v1=<hex>', $recipe['valueShape']);
		$this->assertStringContainsString('HMAC-SHA256', $recipe['v1']);
		$this->assertStringContainsString('exactly as received', $recipe['bodyNote']);
		$this->assertStringContainsString('your own timestamp tolerance', $recipe['toleranceNote']);
		$this->assertStringContainsString('two v1 pairs', $recipe['rotationNote']);
	}//end testTheVerificationRecipeIsAlwaysAvailableAndComplete()
}//end class
