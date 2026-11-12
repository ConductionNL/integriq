<?php

/**
 * A CTI source whose shared secret is the export placeholder admits no caller.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\LogCtiProvider;
use OCA\Integriq\Service\Kiss\WebhookCtiProvider;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared-secret verification of both CTI providers.
 *
 * @covers \OCA\Integriq\Service\Kiss\LogCtiProvider
 * @covers \OCA\Integriq\Service\Kiss\WebhookCtiProvider
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CtiPlaceholderSecretTest extends TestCase {

	/**
	 * Both providers, by name.
	 *
	 * @return array<string, object>
	 */
	private function providers(): array {
		$normaliser = $this->createMock(CallEventNormaliser::class);
		$signatures = $this->createMock(WebhookSignatureService::class);
		$signatures->expects($this->never())->method('verify');

		return [
			'log' => new LogCtiProvider($normaliser, $this->createMock(LoggerInterface::class)),
			'webhook' => new WebhookCtiProvider($normaliser, $signatures),
		];
	}//end providers()

	/**
	 * The placeholder as the stored secret refuses the placeholder as the key.
	 *
	 * @return void
	 */
	public function testThePlaceholderSecretRefusesThePlaceholderKey(): void {
		$placeholder = SensitiveFieldRegistry::PLACEHOLDER;
		foreach ($this->providers() as $name => $provider) {
			$this->assertFalse(
				$provider->verify(['sharedSecret' => $placeholder], ['x-api-key' => $placeholder], '{}'),
				$name
			);
		}
	}//end testThePlaceholderSecretRefusesThePlaceholderKey()

	/**
	 * A real secret still admits the matching key and only that key.
	 *
	 * @return void
	 */
	public function testARealSecretAdmitsOnlyItsKey(): void {
		foreach ($this->providers() as $name => $provider) {
			$this->assertTrue($provider->verify(['sharedSecret' => 'real-secret'], ['x-api-key' => 'real-secret'], '{}'), $name);
			$this->assertFalse($provider->verify(['sharedSecret' => 'real-secret'], ['x-api-key' => 'other'], '{}'), $name);
			$this->assertFalse($provider->verify(['sharedSecret' => ''], ['x-api-key' => ''], '{}'), $name);
			$this->assertFalse($provider->verify([], [], '{}'), $name);
		}
	}//end testARealSecretAdmitsOnlyItsKey()
}//end class
