<?php

/**
 * Integriq — sector, publication and WKPB gateway tests.
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

use OCA\Integriq\Gateway\Adapter\CorvGateway;
use OCA\Integriq\Gateway\Adapter\GgkGateway;
use OCA\Integriq\Gateway\Adapter\PublicationGateway;
use OCA\Integriq\Gateway\Adapter\WkpbGateway;
use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\ResolvedValue;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SG-003, REQ-SG-005 and REQ-SG-009. An invalid message never leaves, and
 * a refusal is a failed delivery with the other side's reason.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
class StatutoryGatewayAdapterTest extends TestCase {
	/**
	 * A transport double.
	 *
	 * @return GatewayTransport The double.
	 */
	private function transport(): GatewayTransport {
		return $this->createMock(GatewayTransport::class);
	}//end transport()

	/**
	 * A resolver double restricted to the methods the real class has.
	 *
	 * @return PropertySourceResolver The double.
	 */
	private function resolver(): PropertySourceResolver {
		return $this->getMockBuilder(PropertySourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['suggest', 'resolve', 'resolveSuggestion', 'manual'])
			->getMock();
	}//end resolver()

	/**
	 * A valid CORV message leaves over the configured route, and the attempt
	 * is recorded with its outcome.
	 *
	 * @return void
	 */
	public function testAValidCorvMessageLeavesAndIsRecorded(): void {
		$transport = $this->transport();
		$transport->expects($this->once())
			->method('send')
			->with('corv', $this->anything(), $this->anything())
			->willReturn(GatewayDelivery::delivered('corv', 'corv-2026-0001'));

		$outcome = (new CorvGateway($transport))->send(
			'zorgmelding',
			['bsn' => '999993653', 'melder' => 'gemeente', 'meldingsdatum' => '2026-09-18', 'aanleiding' => 'zorg']
		);

		$this->assertTrue($outcome->isDelivered());
		$this->assertSame('corv-2026-0001', $outcome->getIdentifier());
	}//end testAValidCorvMessageLeavesAndIsRecorded()

	/**
	 * A GGK message missing a required element never leaves, and the refusal
	 * names the element.
	 *
	 * @return void
	 */
	public function testAnInvalidGgkMessageNeverLeaves(): void {
		$transport = $this->transport();
		$transport->expects($this->never())->method('send');

		$outcome = (new GgkGateway($transport))->send(
			'toewijzing',
			['bsn' => '999993653', 'aanbieder' => 'Zorg BV', 'ingangsdatum' => '2026-09-18']
		);

		$this->assertFalse($outcome->isDelivered());
		$this->assertFalse($outcome->wasTransmitted(), 'Nothing may leave when validation refused the message.');
		$this->assertStringContainsString('productCode', $outcome->getReason());
	}//end testAnInvalidGgkMessageNeverLeaves()

	/**
	 * A message type the gateway does not carry is refused, naming the ones it
	 * does carry.
	 *
	 * @return void
	 */
	public function testAnUnknownMessageTypeIsRefusedNamingTheKnownOnes(): void {
		$refusals = (new CorvGateway($this->transport()))->validate('factuur', []);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('zorgmelding', $refusals[0]);
	}//end testAnUnknownMessageTypeIsRefusedNamingTheKnownOnes()

	/**
	 * A refusal from the other side is a failed delivery carrying its reason,
	 * and it is replayable.
	 *
	 * @return void
	 */
	public function testARefusalFromTheOtherSideIsAReplayableFailedDelivery(): void {
		$transport = $this->transport();
		$transport->method('send')->willReturn(GatewayDelivery::refused('ggk', 'Aanbieder onbekend'));

		$outcome = (new GgkGateway($transport))->send(
			'declaratie',
			['bsn' => '999993653', 'aanbieder' => 'Zorg BV', 'periode' => '2026-09', 'bedrag' => '120.00']
		);

		$this->assertFalse($outcome->isDelivered());
		$this->assertTrue($outcome->wasTransmitted());
		$this->assertTrue($outcome->isReplayable());
		$this->assertSame('Aanbieder onbekend', $outcome->getReason());
	}//end testARefusalFromTheOtherSideIsAReplayableFailedDelivery()

	/**
	 * A publication carries a reference, and the identifier the platform
	 * returns is recorded against the instruction.
	 *
	 * @return void
	 */
	public function testAPublicationTravelsAsAReferenceAndRecordsTheIdentifier(): void {
		$transport = $this->transport();
		$transport->expects($this->once())
			->method('send')
			->with(
				'publicatie',
				$this->callback(
					static function (array $payload): bool {
						// Only the reference and the instruction travel. No
						// copy of the document goes with them.
						return array_keys($payload) === ['reference', 'instruction']
							&& array_key_exists('document', $payload['reference']) === false;
					}
				),
				$this->anything()
			)
			->willReturn(GatewayDelivery::delivered('publicatie', 'gmb-2026-12345'));

		$outcome = (new PublicationGateway($transport))->publish(
			['app' => 'opencatalogi', 'id' => 'doc-1', 'url' => 'https://example.test/doc-1'],
			['publicationType' => 'besluit', 'effectiveDate' => '2026-09-18']
		);

		$this->assertSame('gmb-2026-12345', $outcome->getIdentifier());
	}//end testAPublicationTravelsAsAReferenceAndRecordsTheIdentifier()

	/**
	 * A publication carrying the document inline is refused: this gateway
	 * publishes by reference, and a copy here would be a second archive.
	 *
	 * @return void
	 */
	public function testAPublicationCarryingTheDocumentInlineIsRefused(): void {
		$transport = $this->transport();
		$transport->expects($this->never())->method('send');

		$outcome = (new PublicationGateway($transport))->publish(
			['app' => 'opencatalogi', 'id' => 'doc-1', 'url' => 'https://example.test/doc-1', 'document' => 'JVBERi0x'],
			['publicationType' => 'besluit', 'effectiveDate' => '2026-09-18']
		);

		$this->assertFalse($outcome->wasTransmitted());
		$this->assertStringContainsString('reference, not the document itself', $outcome->getReason());
	}//end testAPublicationCarryingTheDocumentInlineIsRefused()

	/**
	 * A refused publication is a failed delivery with the platform's reason,
	 * and it can be replayed.
	 *
	 * @return void
	 */
	public function testARefusedPublicationIsReplayable(): void {
		$transport = $this->transport();
		$transport->method('send')->willReturn(GatewayDelivery::refused('publicatie', 'Publicatietype onbekend'));

		$outcome = (new PublicationGateway($transport))->publish(
			['app' => 'opencatalogi', 'id' => 'doc-1', 'url' => 'https://example.test/doc-1'],
			['publicationType' => 'besluit', 'effectiveDate' => '2026-09-18']
		);

		$this->assertTrue($outcome->isReplayable());
		$this->assertSame('Publicatietype onbekend', $outcome->getReason());
	}//end testARefusedPublicationIsReplayable()

	/**
	 * A WKPB restriction on a resolvable property is registered, and the
	 * returned identifier is recorded.
	 *
	 * @return void
	 */
	public function testAWkpbRestrictionRecordsTheReturnedIdentifier(): void {
		$resolver = $this->resolver();
		$resolver->method('resolve')->willReturn(
			ResolvedValue::fromSource(['street' => 'Kerkstraat'], 'bag', 'adres-1', 1758182400)
		);

		$transport = $this->transport();
		$transport->method('send')->willReturn(GatewayDelivery::delivered('wkpb', 'wkpb-2026-77'));

		$outcome = (new WkpbGateway($transport, $resolver))->register(
			'adres-1',
			['restrictionType' => 'gemeentelijk monument', 'decisionDate' => '2026-09-18', 'decisionReference' => 'B-2026-1']
		);

		$this->assertTrue($outcome->isDelivered());
		$this->assertSame('wkpb-2026-77', $outcome->getIdentifier());
	}//end testAWkpbRestrictionRecordsTheReturnedIdentifier()

	/**
	 * A property reference that does not resolve is refused before sending,
	 * and the refusal names the reference.
	 *
	 * @return void
	 */
	public function testAnUnresolvablePropertyIsRefusedBeforeSending(): void {
		$resolver = $this->resolver();
		$resolver->method('resolve')->willReturn(ResolvedValue::unreachable('bag', 'adres-nope'));

		$transport = $this->transport();
		$transport->expects($this->never())->method('send');

		$outcome = (new WkpbGateway($transport, $resolver))->register(
			'adres-nope',
			['restrictionType' => 'gemeentelijk monument', 'decisionDate' => '2026-09-18', 'decisionReference' => 'B-2026-1']
		);

		$this->assertFalse($outcome->wasTransmitted());
		$this->assertStringContainsString('adres-nope', $outcome->getReason());
	}//end testAnUnresolvablePropertyIsRefusedBeforeSending()

	/**
	 * A restriction missing a required field is refused before the property is
	 * even looked up.
	 *
	 * @return void
	 */
	public function testAnIncompleteRestrictionIsRefusedBeforeTheLookup(): void {
		$resolver = $this->resolver();
		$resolver->expects($this->never())->method('resolve');

		$outcome = (new WkpbGateway($this->transport(), $resolver))->register(
			'adres-1',
			['restrictionType' => 'gemeentelijk monument']
		);

		$this->assertFalse($outcome->wasTransmitted());
		$this->assertStringContainsString('decisionDate', $outcome->getReason());
	}//end testAnIncompleteRestrictionIsRefusedBeforeTheLookup()
}//end class
