<?php

/**
 * The credential bridge on an OpenRegister from before #4361 refuses every call.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-an-openregister-without-the-public-checks-is-refused-req-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use PHPUnit\Framework\TestCase;

/**
 * Runs Fixtures/old-openregister-probe.php in its own PHP process: this
 * process already holds the current OpenRegister classes and cannot unload
 * them, so "an older OpenRegister" can only be staged in a fresh one.
 */
class OpenRegisterCredentialBridgeOldOpenRegisterTest extends TestCase {

	/**
	 * Every entry point is refused with a clear message; nothing falls back.
	 *
	 * @return void
	 */
	public function testEveryCheckIsRefusedOnAnOpenRegisterWithoutThePublicEntryPoints(): void {
		$probe = __DIR__ . '/Fixtures/old-openregister-probe.php';
		$output = [];
		$exitCode = 0;
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $output, $exitCode);
		$this->assertSame(0, $exitCode, implode("\n", $output));

		$result = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);

		foreach (['jwt', 'apiKey', 'basic', 'oauth', 'ncSession', 'payload'] as $entryPoint) {
			$outcome = $result['outcomes'][$entryPoint];
			$this->assertIsArray($outcome, $entryPoint . ' was not refused with integriq\'s AuthenticationException: ' . json_encode($outcome));
			$this->assertSame('Inbound authentication is unavailable', $outcome['message'], $entryPoint);
			$this->assertStringContainsString('update OpenRegister', $outcome['details']['reason'], $entryPoint);
		}

		// Nothing acted as anybody, the old protected checks were never
		// reached (they throw LogicException), the consumer source (which
		// implements an interface the old OpenRegister lacks) was never
		// loaded, and no consumer was resolved.
		$this->assertSame(0, $result['volatileUsers']);
		$this->assertFalse($result['consumerSourceLoaded']);
		$this->assertNull($result['resolvedConsumer']);
	}//end testEveryCheckIsRefusedOnAnOpenRegisterWithoutThePublicEntryPoints()
}//end class
