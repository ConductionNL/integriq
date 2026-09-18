<?php

/**
 * Integriq — on-premise bridge tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Bridge
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

namespace OCA\Integriq\Tests\Unit\Bridge;

use OCA\Integriq\Bridge\BridgeRegistry;
use OCA\Integriq\Bridge\BridgeTransport;
use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SG-007: a call travels over the bridge and is recorded with the bridge
 * as its transport; a revoked bridge passes no traffic at all.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-on-premise-bridge-reaches-a-system-behind-the-firewall-req-sg-007
 */
class BridgeTransportTest extends TestCase {
	/**
	 * In-memory app-config store.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * A bridge registry over the in-memory store.
	 *
	 * @return BridgeRegistry The registry.
	 */
	private function registry(): BridgeRegistry {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('a-bridge-token');

		return new BridgeRegistry($appConfig, $random, $this->createMock(LoggerInterface::class));
	}//end registry()

	/**
	 * A call to a system with no public endpoint travels over the bridge, and
	 * the call log names the bridge as its transport.
	 *
	 * @return void
	 */
	public function testACallOverABridgeIsRecordedWithTheBridgeAsItsTransport(): void {
		$registry = $this->registry();
		$registry->register('kantoor-nijmegen', 'Kantoor Nijmegen');

		$inner = $this->createMock(GatewayTransport::class);
		$inner->expects($this->once())->method('send')->willReturn(GatewayDelivery::delivered('stuf-zkn', 'zaak-1'));

		$outcome = (new BridgeTransport($registry, $inner, $this->createMock(LoggerInterface::class)))
			->send('stuf-zkn', ['a' => 'b'], ['bridge' => 'kantoor-nijmegen']);

		$this->assertTrue($outcome->isDelivered());
		$this->assertSame('bridge:kantoor-nijmegen', $outcome->toArray()['transport']);
	}//end testACallOverABridgeIsRecordedWithTheBridgeAsItsTransport()

	/**
	 * A revoked bridge stops answering: the call fails naming the revocation
	 * and no traffic passes.
	 *
	 * @return void
	 */
	public function testARevokedBridgeStopsAnsweringAndNoTrafficPasses(): void {
		$registry = $this->registry();
		$registry->register('kantoor-nijmegen');
		$this->assertTrue($registry->revoke('kantoor-nijmegen'));

		$inner = $this->createMock(GatewayTransport::class);
		$inner->expects($this->never())->method('send');

		$outcome = (new BridgeTransport($registry, $inner, $this->createMock(LoggerInterface::class)))
			->send('stuf-zkn', ['a' => 'b'], ['bridge' => 'kantoor-nijmegen']);

		$this->assertFalse($outcome->isDelivered());
		$this->assertFalse($outcome->wasTransmitted());
		$this->assertStringContainsString('kantoor-nijmegen', $outcome->getReason());
		$this->assertStringContainsString('revoked', $outcome->getReason());
	}//end testARevokedBridgeStopsAnsweringAndNoTrafficPasses()

	/**
	 * A gateway that selects the bridge transport but names no bridge sends
	 * nothing, rather than falling back to a direct call.
	 *
	 * @return void
	 */
	public function testAGatewayWithNoBridgeNamedFallsBackToNothing(): void {
		$inner = $this->createMock(GatewayTransport::class);
		$inner->expects($this->never())->method('send');

		$outcome = (new BridgeTransport($this->registry(), $inner, $this->createMock(LoggerInterface::class)))
			->send('stuf-zkn', ['a' => 'b'], []);

		$this->assertFalse($outcome->wasTransmitted());
		$this->assertStringContainsString('names no bridge', $outcome->getReason());
	}//end testAGatewayWithNoBridgeNamedFallsBackToNothing()

	/**
	 * A bridge authenticates with the token it was issued, and a revoked one
	 * cannot authenticate at all.
	 *
	 * @return void
	 */
	public function testABridgeAuthenticatesWithItsTokenUntilItIsRevoked(): void {
		$registry = $this->registry();
		$bridge = $registry->register('kantoor-nijmegen');

		$this->assertTrue($registry->authenticates('kantoor-nijmegen', $bridge['token']));
		$this->assertFalse($registry->authenticates('kantoor-nijmegen', 'een-ander-token'));

		$registry->revoke('kantoor-nijmegen');

		$this->assertFalse(
			$registry->authenticates('kantoor-nijmegen', $bridge['token']),
			'A revoked bridge must not authenticate with the token it used to hold.'
		);
	}//end testABridgeAuthenticatesWithItsTokenUntilItIsRevoked()

	/**
	 * The token is stored hashed, never in the clear.
	 *
	 * @return void
	 */
	public function testTheTokenIsStoredHashed(): void {
		$registry = $this->registry();
		$bridge = $registry->register('kantoor-nijmegen');

		$stored = $registry->all()['kantoor-nijmegen'];

		$this->assertArrayNotHasKey('token', $stored);
		$this->assertSame(hash('sha256', $bridge['token']), $stored['tokenHash']);
	}//end testTheTokenIsStoredHashed()

	/**
	 * Revoking a bridge nobody registered reports that, rather than pretending
	 * it worked.
	 *
	 * @return void
	 */
	public function testRevokingAnUnknownBridgeReportsIt(): void {
		$this->assertFalse($this->registry()->revoke('er-is-geen-brug'));
	}//end testRevokingAnUnknownBridgeReportsIt()
}//end class
