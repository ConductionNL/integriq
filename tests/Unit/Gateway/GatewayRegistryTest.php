<?php

/**
 * Integriq — statutory gateway catalogue and overview tests.
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

use InvalidArgumentException;
use OCA\Integriq\Gateway\GatewayCatalogue;
use OCA\Integriq\Gateway\GatewayDescriptor;
use OCA\Integriq\Gateway\GatewayRegistry;
use OCA\Integriq\Gateway\InboundRouteReceipt;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SG-001, REQ-SG-004 and REQ-SG-008.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
 */
class GatewayRegistryTest extends TestCase {
	/**
	 * A minimal valid entry.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed> The entry.
	 */
	private function entry(array $overrides = []): array {
		return ($overrides + [
			'id' => 'corv',
			'label' => 'CORV',
			'standard' => 'CORV koppelvlak',
			'claimLevel' => GatewayDescriptor::CLAIM_PLANNED,
			'claimEvidence' => 'Validated against a mock-mode fixture only.',
			'jurisdiction' => 'NL',
		]);
	}//end entry()

	/**
	 * A gateway with no standard is refused at registration, naming the entry
	 * and the missing field.
	 *
	 * @return void
	 */
	public function testAGatewayWithoutAStandardIsRefusedAtRegistration(): void {
		$entry = $this->entry();
		unset($entry['standard']);

		try {
			new GatewayRegistry([$entry]);
			$this->fail('An entry with no standard must not register.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('corv', $e->getMessage());
			$this->assertStringContainsString('standard', $e->getMessage());
		}
	}//end testAGatewayWithoutAStandardIsRefusedAtRegistration()

	/**
	 * A claim with no evidence is refused too: a level on its own says nothing
	 * anyone can check.
	 *
	 * @return void
	 */
	public function testAClaimWithNoEvidenceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		new GatewayRegistry([$this->entry(['claimEvidence' => '  '])]);
	}//end testAClaimWithNoEvidenceIsRefused()

	/**
	 * A claim level the vocabulary does not have is refused, naming the three
	 * it accepts.
	 *
	 * @return void
	 */
	public function testAnUnknownClaimLevelIsRefusedNamingTheAcceptedOnes(): void {
		try {
			new GatewayRegistry([$this->entry(['claimLevel' => 'certified'])]);
			$this->fail('An unknown claim level must not register.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('conformant', $e->getMessage());
			$this->assertStringContainsString('partial', $e->getMessage());
			$this->assertStringContainsString('planned', $e->getMessage());
		}
	}//end testAnUnknownClaimLevelIsRefusedNamingTheAcceptedOnes()

	/**
	 * A claim is rendered as a claim, with its evidence, and never as a
	 * certification.
	 *
	 * @return void
	 */
	public function testAClaimIsNotACertificate(): void {
		$registry = new GatewayRegistry([$this->entry(['claimLevel' => GatewayDescriptor::CLAIM_CONFORMANT])]);

		$claim = $registry->get('corv')->toArray()['claim'];

		$this->assertFalse($claim['certified']);
		$this->assertStringContainsString('Self-declared claim', $claim['wording']);
		$this->assertStringContainsString('not a certification', $claim['wording']);
		$this->assertStringContainsString($claim['evidence'], $claim['wording']);
	}//end testAClaimIsNotACertificate()

	/**
	 * The catalogue filters by standard, and reports the facet.
	 *
	 * @return void
	 */
	public function testTheCatalogueFiltersByStandard(): void {
		$registry = new GatewayRegistry(
			[
				$this->entry(),
				$this->entry(['id' => 'ggk', 'standard' => 'GGK koppelvlak']),
				$this->entry(['id' => 'ggk-2', 'standard' => 'GGK koppelvlak']),
			]
		);

		$this->assertSame(['CORV koppelvlak', 'GGK koppelvlak'], $registry->standards());
		$this->assertCount(2, $registry->all('GGK koppelvlak'));
		$this->assertCount(3, $registry->all());
	}//end testTheCatalogueFiltersByStandard()

	/**
	 * An undeclared jurisdiction reads `unknown`, and says it was never
	 * declared, so it cannot be read as a checked answer.
	 *
	 * @return void
	 */
	public function testAnUndeclaredJurisdictionReadsUnknown(): void {
		$entry = $this->entry();
		unset($entry['jurisdiction']);
		$registry = new GatewayRegistry([$entry]);

		$row = $registry->overview()[0];

		$this->assertSame('unknown', $row['jurisdiction']);
		$this->assertFalse($row['jurisdictionDeclared']);
	}//end testAnUndeclaredJurisdictionReadsUnknown()

	/**
	 * The overview lists every gateway with its jurisdiction, and exports.
	 *
	 * @return void
	 */
	public function testTheOverviewListsEveryGatewayAndExports(): void {
		$registry = new GatewayRegistry(
			[$this->entry(), $this->entry(['id' => 'ggk', 'jurisdiction' => 'EU'])]
		);

		$export = $registry->exportOverview();

		$this->assertCount(2, $registry->overview());
		$this->assertStringContainsString('id,label,standard,claimLevel,jurisdiction,transport', $export);
		$this->assertStringContainsString('"corv"', $export);
		$this->assertStringContainsString('"EU"', $export);
	}//end testTheOverviewListsEveryGatewayAndExports()

	/**
	 * The shipped catalogue registers, which is the check that every entry in
	 * it carries a standard, a claim level and evidence.
	 *
	 * @return void
	 */
	public function testTheShippedCatalogueRegisters(): void {
		$registry = new GatewayRegistry(GatewayCatalogue::entries());

		$this->assertNotEmpty($registry->all());
		$this->assertContains('Wmebv', $registry->standards());
		$this->assertContains('Wkpb', $registry->standards());
	}//end testTheShippedCatalogueRegisters()

	/**
	 * A route states what it meets and what it hands on, and the handed
	 * obligation names the duty.
	 *
	 * @return void
	 */
	public function testARouteStatesWhatItMeetsAndWhatItHandsOn(): void {
		$registry = new GatewayRegistry(GatewayCatalogue::entries());

		$wmebv = $registry->get('berichtenbox')->toArray()['wmebv'];

		$this->assertNotEmpty($wmebv['met']);
		$this->assertNotEmpty($wmebv['handedToConsumer']);
		$this->assertArrayHasKey('consumerDuty', $wmebv['handedToConsumer'][0]);
		$this->assertNotSame('', $wmebv['handedToConsumer'][0]['consumerDuty']);
	}//end testARouteStatesWhatItMeetsAndWhatItHandsOn()

	/**
	 * A received message carries the route it arrived on and the obligations
	 * that route met at that moment.
	 *
	 * @return void
	 */
	public function testAReceivedMessageCarriesItsRouteAndItsObligations(): void {
		$registry = new GatewayRegistry(GatewayCatalogue::entries());
		$receipt = (new InboundRouteReceipt($registry))->record('berichtenbox', 'msg-1', 1758182400);

		$this->assertSame('berichtenbox', $receipt['route']);
		$this->assertTrue($receipt['routeKnown']);
		$this->assertSame('msg-1', $receipt['messageId']);
		$this->assertNotEmpty($receipt['wmebvMet']);
		$this->assertNotEmpty($receipt['wmebvHandedToConsumer']);
	}//end testAReceivedMessageCarriesItsRouteAndItsObligations()

	/**
	 * A message on a route nobody declared is recorded as an unknown route,
	 * not as a route that met nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownRouteIsRecordedAsUnknown(): void {
		$receipt = (new InboundRouteReceipt(new GatewayRegistry([])))->record('smoke-signal', 'msg-2');

		$this->assertFalse($receipt['routeKnown']);
		$this->assertSame([], $receipt['wmebvMet']);
	}//end testAnUnknownRouteIsRecordedAsUnknown()
}//end class
