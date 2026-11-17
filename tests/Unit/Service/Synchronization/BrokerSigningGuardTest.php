<?php

/**
 * The broker signing guard refuses a run whose signing scheme the broker does not offer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\BrokerSigningGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers BrokerSigningGuard.
 */
#[CoversClass(BrokerSigningGuard::class)]
final class BrokerSigningGuardTest extends TestCase {

	/**
	 * A synchronization that names no scheme is never refused.
	 *
	 * @return void
	 */
	public function testARunWithoutASigningSchemeMayStart(): void {
		$this->assertNull((new BrokerSigningGuard(brokerSchemes: []))->refusal(sourceConfig: ['endpoint' => '/services']));
	}//end testARunWithoutASigningSchemeMayStart()

	/**
	 * A broker without aws-sigv4 refuses the run, and the message says what it waits on.
	 *
	 * @return void
	 */
	public function testABrokerWithoutTheSchemeRefusesAndSaysWhatItWaitsOn(): void {
		$refusal = (new BrokerSigningGuard(brokerSchemes: []))->refusal(sourceConfig: ['brokerSigning' => 'aws-sigv4']);

		$this->assertSame(
			'Canceled: the credential broker cannot sign AWS Signature Version 4 yet, so this source cannot be called. This synchronization waits on OpenRegister to offer the aws-sigv4 scheme. Nothing was fetched or written.',
			$refusal
		);
	}//end testABrokerWithoutTheSchemeRefusesAndSaysWhatItWaitsOn()

	/**
	 * A broker offering the scheme lets the run start.
	 *
	 * @return void
	 */
	public function testABrokerOfferingTheSchemeLetsTheRunStart(): void {
		$guard = new BrokerSigningGuard(brokerSchemes: ['aws-sigv4']);

		$this->assertNull($guard->refusal(sourceConfig: ['brokerSigning' => 'aws-sigv4']));
		$this->assertNull($guard->refusal(sourceConfig: ['brokerSigning' => ' aws-sigv4 ']), 'surrounding space is not a different scheme');
	}//end testABrokerOfferingTheSchemeLetsTheRunStart()

	/**
	 * An unknown scheme is refused under its own name.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemeIsRefusedByName(): void {
		$refusal = (new BrokerSigningGuard(brokerSchemes: ['aws-sigv4']))->refusal(sourceConfig: ['brokerSigning' => 'hmac-x']);

		$this->assertStringContainsString('cannot sign hmac-x yet', (string)$refusal);
	}//end testAnUnknownSchemeIsRefusedByName()

	/**
	 * A declared but empty or non-string scheme is a configuration error, not a pass.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsRefused(): void {
		$guard = new BrokerSigningGuard(brokerSchemes: ['aws-sigv4']);

		foreach (['', '  ', null, ['aws-sigv4'], 4] as $value) {
			$this->assertSame(
				'Canceled: sourceConfig.brokerSigning must name a signing scheme, for example aws-sigv4. Nothing was fetched or written.',
				$guard->refusal(sourceConfig: ['brokerSigning' => $value]),
				var_export($value, true)
			);
		}
	}//end testAMalformedDeclarationIsRefused()

	/**
	 * Without injected schemes the guard reads the broker, and today's OpenRegister offers no signing scheme.
	 *
	 * @return void
	 */
	public function testTheBrokerIsReadWhenNoSchemesAreGiven(): void {
		$guard = new BrokerSigningGuard();
		if (defined(BrokerSigningGuard::BROKER_SCHEMES_CONSTANT) === true) {
			$offered = in_array('aws-sigv4', (array)constant(BrokerSigningGuard::BROKER_SCHEMES_CONSTANT), true);
			$this->assertSame($offered, $guard->refusal(sourceConfig: ['brokerSigning' => 'aws-sigv4']) === null);
			return;
		}

		$this->assertNotNull($guard->refusal(sourceConfig: ['brokerSigning' => 'aws-sigv4']));
	}//end testTheBrokerIsReadWhenNoSchemesAreGiven()
}//end class
