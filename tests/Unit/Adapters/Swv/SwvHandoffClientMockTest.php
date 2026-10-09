<?php

/**
 * Unit tests for the SWV hand-off *ClientMock dormant implementation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Swv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Swv;

use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canned acknowledgement shape for the dormant SWV hand-off
 * client, against the recorded/representative fixture at
 * tests/fixtures/swv/fixture-swv-dossier.json.
 */
class SwvHandoffClientMockTest extends TestCase {
	/**
	 * @return array<string,mixed>
	 */
	private function loadFixtureDossier(): array {
		$path = __DIR__ . '/../../../fixtures/swv/fixture-swv-dossier.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		return $decoded['dossier'];
	}//end loadFixtureDossier()

	/**
	 * @return void
	 */
	public function testMockExtendsAbstractClient(): void {
		$mock = new SwvHandoffClientMock();

		$this->assertInstanceOf(SwvHandoffClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testMockExtendsAbstractClient()

	/**
	 * @return void
	 */
	public function testHandOffReturnsDeterministicAcknowledgementShape(): void {
		$mock = new SwvHandoffClientMock();

		$result = $mock->handOff(receiverId: 'swv-kindkans', dossier: $this->loadFixtureDossier());

		$this->assertArrayHasKey('referenceId', $result);
		$this->assertArrayHasKey('acceptedStatus', $result);
		$this->assertArrayHasKey('receivedAt', $result);
		$this->assertSame('received', $result['acceptedStatus']);
		$this->assertStringStartsWith('swv-mock-', $result['referenceId']);
	}//end testHandOffReturnsDeterministicAcknowledgementShape()

	/**
	 * @return void
	 */
	public function testHandOffNeverEchoesTheDossierBack(): void {
		$mock = new SwvHandoffClientMock();

		$dossier = $this->loadFixtureDossier();
		$result = $mock->handOff(receiverId: 'swv-ldos', dossier: $dossier);

		$encoded = json_encode($result);
		$this->assertIsString($encoded);
		$this->assertStringNotContainsString('leerling-mock-0001', $encoded);
	}//end testHandOffNeverEchoesTheDossierBack()

	/**
	 * @return void
	 */
	public function testHandOffWorksForBothReceiverIds(): void {
		$mock = new SwvHandoffClientMock();
		$dossier = $this->loadFixtureDossier();

		$kindkans = $mock->handOff(receiverId: 'swv-kindkans', dossier: $dossier);
		$ldos = $mock->handOff(receiverId: 'swv-ldos', dossier: $dossier);

		$this->assertSame('received', $kindkans['acceptedStatus']);
		$this->assertSame('received', $ldos['acceptedStatus']);
	}//end testHandOffWorksForBothReceiverIds()
}//end class
