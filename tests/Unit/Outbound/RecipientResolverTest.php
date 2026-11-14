<?php

/**
 * Unit tests for RecipientResolver.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
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

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\RecipientResolver;
use OCA\Integriq\Outbound\RecipientSelectionRefusedException;
use OCA\Integriq\Outbound\RecipientState;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a message's recipient list is a recorded decision.
 *
 * @covers \OCA\Integriq\Outbound\RecipientResolver
 * @uses   \OCA\Integriq\Outbound\RecipientSelectionRefusedException
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
 */
class RecipientResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var RecipientResolver
	 */
	private RecipientResolver $resolver;

	/**
	 * Build the resolver over an IL10N that formats like Nextcloud's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->resolver = new RecipientResolver($l10n);

	}//end setUp()

	/**
	 * Three standing, one added, one suppressed: three parts recorded, three addresses resolved.
	 *
	 * @return void
	 */
	public function testThreePartsAreKeptAndTheTransportGetsTheResolvedList(): void {
		$result = $this->resolver->resolve($this->decision(), 'behandelaar');

		$this->assertSame(['aanvrager@example.org', 'buur@example.org', 'derde@example.org'], array_column($result['standing'], 'address'));
		$this->assertSame(['gemachtigde@example.org'], array_column($result['added'], 'address'));
		$this->assertSame(['buur@example.org'], array_column($result['suppressed'], 'address'));
		$this->assertSame(
			['aanvrager@example.org', 'derde@example.org', 'gemachtigde@example.org'],
			array_column($result['resolved'], 'address')
		);

	}//end testThreePartsAreKeptAndTheTransportGetsTheResolvedList()

	/**
	 * A suppressed recipient reads suppressed, with reason, user and time, and no delivery state.
	 *
	 * @return void
	 */
	public function testASuppressedRecipientIsRecordedAsSuppressed(): void {
		$suppressed = $this->resolver->resolve($this->decision(), 'behandelaar')['suppressed'][0];

		$this->assertSame(RecipientState::SUPPRESSED, $suppressed['state']);
		$this->assertSame('Buur is geen belanghebbende meer', $suppressed['reason']);
		$this->assertSame('behandelaar', $suppressed['user']);
		$this->assertNotSame('', $suppressed['at']);
		$this->assertSame('Buur', $suppressed['name']);
		$this->assertArrayNotHasKey('deliveryState', $suppressed);

	}//end testASuppressedRecipientIsRecordedAsSuppressed()

	/**
	 * A suppression keeps its own user and time when it names them.
	 *
	 * @return void
	 */
	public function testASuppressionKeepsTheUserAndTimeItNames(): void {
		$decision = $this->decision();
		$decision['suppressed'][0]['user'] = 'teamleider';
		$decision['suppressed'][0]['at'] = '2026-11-14T09:00:00+01:00';

		$suppressed = $this->resolver->resolve($decision, 'behandelaar')['suppressed'][0];

		$this->assertSame('teamleider', $suppressed['user']);
		$this->assertSame('2026-11-14T09:00:00+01:00', $suppressed['at']);

	}//end testASuppressionKeepsTheUserAndTimeItNames()

	/**
	 * A second message for the same subject without the addition resolves to the standing list.
	 *
	 * @return void
	 */
	public function testAnAdditionDoesNotCarryToTheNextMessage(): void {
		$first = $this->resolver->resolve(['standing' => ['aanvrager@example.org'], 'added' => [['address' => 'gemachtigde@example.org']]]);
		$second = $this->resolver->resolve(['standing' => ['aanvrager@example.org']]);

		$this->assertSame(['aanvrager@example.org', 'gemachtigde@example.org'], array_column($first['resolved'], 'address'));
		$this->assertSame(['aanvrager@example.org'], array_column($second['resolved'], 'address'));
		$this->assertSame([], $second['added']);

	}//end testAnAdditionDoesNotCarryToTheNextMessage()

	/**
	 * An added external address is marked external; one already standing is not sent twice.
	 *
	 * @return void
	 */
	public function testAnAdditionIsExternalAndNeverDuplicated(): void {
		$result = $this->resolver->resolve(
			[
				'standing' => [['address' => 'aanvrager@example.org', 'accountId' => 'aanvrager']],
				'added' => [['address' => 'Aanvrager@example.org'], ['address' => 'gemachtigde@example.org', 'name' => 'Gemachtigde']],
			]
		);

		$this->assertTrue($result['added'][1]['external']);
		$this->assertSame('Gemachtigde', $result['added'][1]['name']);
		$this->assertSame(['aanvrager@example.org', 'gemachtigde@example.org'], array_column($result['resolved'], 'address'));
		$this->assertSame('aanvrager', $result['resolved'][0]['accountId']);

	}//end testAnAdditionIsExternalAndNeverDuplicated()

	/**
	 * A suppression without a reason is refused and says a reason is required.
	 *
	 * @return void
	 */
	public function testASuppressionWithoutAReasonIsRefused(): void {
		$decision = $this->decision();
		$decision['suppressed'][0]['reason'] = '   ';

		$refusal = $this->refusal($decision);

		$this->assertSame(RecipientResolver::REFUSED_NO_REASON, $refusal->getRefusalCode());
		$this->assertSame('Leaving buur@example.org out of this message needs a reason.', $refusal->getMessage());
		$this->assertSame('buur@example.org', $refusal->getAddress());

	}//end testASuppressionWithoutAReasonIsRefused()

	/**
	 * A required recipient cannot be suppressed, and the refusal repeats the caller's text.
	 *
	 * @return void
	 */
	public function testARequiredRecipientCannotBeSuppressed(): void {
		$decision = $this->decision();
		$decision['suppressed'] = [['address' => 'aanvrager@example.org', 'reason' => 'Niet nodig']];

		$refusal = $this->refusal($decision);

		$this->assertSame(RecipientResolver::REFUSED_REQUIRED, $refusal->getRefusalCode());
		$this->assertSame('aanvrager@example.org cannot be left out of this message: Awb 3:41, the applicant receives the decision', $refusal->getMessage());

	}//end testARequiredRecipientCannotBeSuppressed()

	/**
	 * A suppression of somebody who is not a standing recipient is refused, naming them.
	 *
	 * @return void
	 */
	public function testASuppressionOfAnUnknownRecipientIsRefused(): void {
		$decision = $this->decision();
		$decision['suppressed'] = [['address' => 'onbekend@example.org', 'reason' => 'Geen idee']];

		$refusal = $this->refusal($decision);

		$this->assertSame(RecipientResolver::REFUSED_UNKNOWN, $refusal->getRefusalCode());
		$this->assertStringContainsString('onbekend@example.org', $refusal->getMessage());
		$this->assertSame('onbekend@example.org', $refusal->getAddress());

	}//end testASuppressionOfAnUnknownRecipientIsRefused()

	/**
	 * A required marker that does not say what requires it is refused, never invented.
	 *
	 * @return void
	 */
	public function testARequiredMarkerMustNameItsSource(): void {
		$refusal = $this->refusal(['standing' => [['address' => 'aanvrager@example.org', 'required' => true]]]);

		$this->assertSame(RecipientResolver::REFUSED_REQUIRED_UNNAMED, $refusal->getRefusalCode());

	}//end testARequiredMarkerMustNameItsSource()

	/**
	 * An addition without an address is refused.
	 *
	 * @return void
	 */
	public function testAnAdditionWithoutAnAddressIsRefused(): void {
		$refusal = $this->refusal(['standing' => ['aanvrager@example.org'], 'added' => [['name' => 'Niemand']]]);

		$this->assertSame(RecipientResolver::REFUSED_ADDITION_WITHOUT_ADDRESS, $refusal->getRefusalCode());

	}//end testAnAdditionWithoutAnAddressIsRefused()

	/**
	 * Whether a decision asks for anything.
	 *
	 * @return void
	 */
	public function testIsDecision(): void {
		$this->assertFalse($this->resolver->isDecision([]));
		$this->assertFalse($this->resolver->isDecision(['standing' => [], 'added' => 'x']));
		$this->assertTrue($this->resolver->isDecision(['added' => ['a@example.org']]));

	}//end testIsDecision()

	/**
	 * The refusal a decision produces.
	 *
	 * @param array<string,mixed> $decision The decision.
	 *
	 * @return RecipientSelectionRefusedException The refusal.
	 */
	private function refusal(array $decision): RecipientSelectionRefusedException {
		try {
			$this->resolver->resolve($decision, 'behandelaar');
		} catch (RecipientSelectionRefusedException $e) {
			return $e;
		}

		$this->fail('The decision was not refused.');

	}//end refusal()

	/**
	 * Three standing recipients (the applicant required), one addition, one suppression.
	 *
	 * @return array<string,mixed> The decision.
	 */
	private function decision(): array {
		return [
			'standing' => [
				['address' => 'aanvrager@example.org', 'name' => 'Aanvrager', 'required' => true, 'requiredBy' => 'Awb 3:41, the applicant receives the decision'],
				['address' => 'buur@example.org', 'name' => 'Buur'],
				'derde@example.org',
			],
			'added' => [['address' => 'gemachtigde@example.org', 'name' => 'Gemachtigde']],
			'suppressed' => [['address' => 'BUUR@example.org', 'reason' => 'Buur is geen belanghebbende meer']],
		];

	}//end decision()
}//end class
