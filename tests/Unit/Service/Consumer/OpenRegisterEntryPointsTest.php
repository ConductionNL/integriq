<?php

/**
 * Tests for the OpenRegister entry-point check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\Consumer\OpenRegisterEntryPoints;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use PHPUnit\Framework\TestCase;

/**
 * Pins the verdict the bridge and the setup check share.
 */
class OpenRegisterEntryPointsTest extends TestCase {

	/**
	 * An authorization service lacking any entry point is not enough.
	 *
	 * @return void
	 */
	public function testAServiceWithoutTheEntryPointsIsRefused(): void {
		$this->assertFalse(OpenRegisterEntryPoints::available(authorization: new \stdClass()));
		$this->assertFalse(OpenRegisterCredentialBridge::entryPointsAvailable(authorization: new \stdClass()));

	}//end testAServiceWithoutTheEntryPointsIsRefused()

	/**
	 * A service with every entry point passes when OpenRegister ships ConsumerSource.
	 *
	 * @return void
	 */
	public function testAServiceWithEveryEntryPointPasses(): void {
		$service = new class {
			public function authorizeJwt(): void {}
			public function authorizeApiKey(): void {}
			public function authorizeBasic(): void {}
			public function authorizeOAuth(): void {}
			public function authorizeNcSession(): void {}
			public function validatePayload(): void {}
			public function getResolvedConsumer(): void {}
		};

		$expected = interface_exists(ConsumerSource::class);
		$this->assertSame(expected: $expected, actual: OpenRegisterEntryPoints::available(authorization: $service));
		$this->assertSame(expected: $expected, actual: OpenRegisterCredentialBridge::entryPointsAvailable(authorization: $service));
		$this->assertSame(expected: OpenRegisterEntryPoints::REQUIRED, actual: OpenRegisterCredentialBridge::REQUIRED_ENTRY_POINTS);

	}//end testAServiceWithEveryEntryPointPasses()
}//end class
