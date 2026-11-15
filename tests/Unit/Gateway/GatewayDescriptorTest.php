<?php

/**
 * Integriq — GatewayDescriptor accessor and rendering tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\GatewayDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * What a descriptor answers through its accessors and renders through toArray().
 *
 * @covers \OCA\Integriq\Gateway\GatewayDescriptor
 */
class GatewayDescriptorTest extends TestCase {
	/**
	 * A fully declared descriptor.
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	private function full(): GatewayDescriptor {
		return new GatewayDescriptor(
			'postex',
			'Postex',
			'Wet modernisering elektronisch bestuurlijk verkeer',
			GatewayDescriptor::CLAIM_CONFORMANT,
			'Exercised against the acceptance endpoint.',
			'NL',
			['receipt-confirmation'],
			[['obligation' => 'archiving', 'consumerDuty' => 'Store the message for the retention period.']],
			'digikoppeling'
		);
	}//end full()

	/**
	 * Every accessor returns what the constructor was given.
	 *
	 * @return void
	 */
	public function testAccessorsReturnTheDeclaredValues(): void {
		$descriptor = $this->full();

		$this->assertSame('postex', $descriptor->getId());
		$this->assertSame('Wet modernisering elektronisch bestuurlijk verkeer', $descriptor->getStandard());
		$this->assertSame('digikoppeling', $descriptor->getTransport());
		$this->assertSame('NL', $descriptor->getJurisdiction());
		$this->assertSame(['receipt-confirmation'], $descriptor->getWmebvMet());
		$this->assertSame(
			[['obligation' => 'archiving', 'consumerDuty' => 'Store the message for the retention period.']],
			$descriptor->getWmebvHandedToConsumer()
		);
	}//end testAccessorsReturnTheDeclaredValues()

	/**
	 * An undeclared jurisdiction reads as unknown and the transport defaults to https.
	 *
	 * @return void
	 */
	public function testDefaultsAreUnknownJurisdictionAndHttps(): void {
		$descriptor = new GatewayDescriptor('corv', 'CORV', 'CORV 2.0', GatewayDescriptor::CLAIM_PLANNED, 'Nothing yet.');

		$this->assertSame(GatewayDescriptor::JURISDICTION_UNKNOWN, $descriptor->getJurisdiction());
		$this->assertSame('unknown', $descriptor->getJurisdiction());
		$this->assertSame('https', $descriptor->getTransport());
		$this->assertSame([], $descriptor->getWmebvMet());
		$this->assertSame([], $descriptor->getWmebvHandedToConsumer());

		$rendered = $descriptor->toArray();
		$this->assertSame('unknown', $rendered['jurisdiction']);
		$this->assertFalse($rendered['jurisdictionDeclared']);
		$this->assertSame('https', $rendered['transport']);
	}//end testDefaultsAreUnknownJurisdictionAndHttps()

	/**
	 * The rendered entry carries every field, the claim wording and never a certificate.
	 *
	 * @return void
	 */
	public function testToArrayRendersTheClaimAsAClaimNotACertificate(): void {
		$this->assertSame(
			[
				'id' => 'postex',
				'label' => 'Postex',
				'kind' => 'gateway',
				'standard' => 'Wet modernisering elektronisch bestuurlijk verkeer',
				'claim' => [
					'level' => 'conformant',
					'evidence' => 'Exercised against the acceptance endpoint.',
					'wording' => 'Self-declared claim: conformant. It rests on: Exercised against the acceptance endpoint.. This is a claim, not a certification.',
					'certified' => false,
				],
				'jurisdiction' => 'NL',
				'jurisdictionDeclared' => true,
				'transport' => 'digikoppeling',
				'wmebv' => [
					'met' => ['receipt-confirmation'],
					'handedToConsumer' => [['obligation' => 'archiving', 'consumerDuty' => 'Store the message for the retention period.']],
				],
			],
			$this->full()->toArray()
		);
	}//end testToArrayRendersTheClaimAsAClaimNotACertificate()

	/**
	 * fromArray falls back to the id as label and casts a scalar jurisdiction to text.
	 *
	 * @return void
	 */
	public function testFromArrayFallsBackToTheIdAsLabelAndCastsJurisdiction(): void {
		$descriptor = GatewayDescriptor::fromArray(
			[
				'id' => 'berichtenbox',
				'standard' => 'MijnOverheid Berichtenbox',
				'claimLevel' => GatewayDescriptor::CLAIM_PARTIAL,
				'claimEvidence' => 'Sending only.',
				'jurisdiction' => 31,
				'wmebvMet' => ['first' => 'delivery'],
			]
		);

		$rendered = $descriptor->toArray();
		$this->assertSame('berichtenbox', $rendered['label']);
		$this->assertSame('31', $descriptor->getJurisdiction());
		$this->assertTrue($rendered['jurisdictionDeclared']);
		$this->assertSame(['delivery'], $descriptor->getWmebvMet());
		$this->assertSame('partial', $rendered['claim']['level']);
	}//end testFromArrayFallsBackToTheIdAsLabelAndCastsJurisdiction()

	/**
	 * The claim levels list exactly the three constants, in order.
	 *
	 * @return void
	 */
	public function testClaimLevelsAreTheThreeDeclaredLevels(): void {
		$this->assertSame(['conformant', 'partial', 'planned'], GatewayDescriptor::CLAIM_LEVELS);
	}//end testClaimLevelsAreTheThreeDeclaredLevels()
}//end class
