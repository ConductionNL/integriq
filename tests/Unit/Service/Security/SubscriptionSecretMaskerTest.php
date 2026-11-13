<?php

/**
 * Unit tests for SubscriptionSecretMasker: what a subscription read shows of its protocol settings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Security\SubscriptionSecretMasker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Security\SubscriptionSecretMasker
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class SubscriptionSecretMaskerTest extends TestCase {

	/**
	 * Mask a subscription with the given protocol settings and answer the settings.
	 *
	 * @param array<string, mixed> $settings The protocol settings.
	 *
	 * @return array<string, mixed> The masked settings.
	 */
	private static function masked(array $settings): array {
		return (new SubscriptionSecretMasker())->mask(subscription: ['id' => 's1', 'protocolSettings' => $settings])['protocolSettings'];
	}//end masked()

	/**
	 * The signing secrets show as a fixed mask, never their value or the registry placeholder.
	 *
	 * @return void
	 */
	public function testSigningSecretsAreMasked(): void {
		$this->assertSame(
			['signingSecret' => '**********', 'previousSigningSecret' => '**********'],
			self::masked(settings: ['signingSecret' => 'whsec_abc', 'previousSigningSecret' => 'whsec_old'])
		);
	}//end testSigningSecretsAreMasked()

	/**
	 * An unset secret stays visibly unset, and the rotation moment is shown although its name says secret.
	 *
	 * @return void
	 */
	public function testUnsetSecretsAndTheRotationMomentShow(): void {
		$this->assertSame(
			['signingSecret' => '', 'previousSigningSecret' => null, 'secretRotatedAt' => '2026-11-01T10:00:00Z'],
			self::masked(settings: ['signingSecret' => '', 'previousSigningSecret' => null, 'secretRotatedAt' => '2026-11-01T10:00:00Z'])
		);
	}//end testUnsetSecretsAndTheRotationMomentShow()

	/**
	 * Other settings with a sensitive name are replaced by the registry placeholder; plain ones are kept.
	 *
	 * @return void
	 */
	public function testSensitiveNamedSettingsUseThePlaceholder(): void {
		$this->assertSame(
			[
				'apiKey' => SensitiveFieldRegistry::PLACEHOLDER,
				'password' => SensitiveFieldRegistry::PLACEHOLDER,
				'url' => 'https://hooks.example/in',
				'method' => 'POST',
				'retries' => 3,
				'verifyTls' => false,
			],
			self::masked(
				settings: [
					'apiKey' => 'k-123',
					'password' => 'hunter2',
					'url' => 'https://hooks.example/in',
					'method' => 'POST',
					'retries' => 3,
					'verifyTls' => false,
				]
			)
		);
	}//end testSensitiveNamedSettingsUseThePlaceholder()

	/**
	 * A nested setting is redacted by the registry, keeping its harmless entries.
	 *
	 * @return void
	 */
	public function testNestedSettingsAreRedactedByTheRegistry(): void {
		$headers = ['Authorization' => 'Bearer t-1', 'Accept' => 'application/json'];

		$masked = self::masked(settings: ['headers' => $headers]);

		$this->assertSame((new SensitiveFieldRegistry())->redactArray(data: $headers), $masked['headers']);
		$this->assertSame(SensitiveFieldRegistry::PLACEHOLDER, $masked['headers']['Authorization']);
		$this->assertSame('application/json', $masked['headers']['Accept']);
	}//end testNestedSettingsAreRedactedByTheRegistry()

	/**
	 * A subscription without protocol settings, or with settings that are not a list, is returned as is.
	 *
	 * @return void
	 */
	public function testASubscriptionWithoutSettingsIsUnchanged(): void {
		$masker = new SubscriptionSecretMasker();

		$this->assertSame(['id' => 's1', 'signingSecret' => 'top'], $masker->mask(subscription: ['id' => 's1', 'signingSecret' => 'top']));
		$this->assertSame(['protocolSettings' => 'opaque'], $masker->mask(subscription: ['protocolSettings' => 'opaque']));
		$this->assertSame(['protocolSettings' => null], $masker->mask(subscription: ['protocolSettings' => null]));
	}//end testASubscriptionWithoutSettingsIsUnchanged()

	/**
	 * Only the protocol settings change; the rest of the subscription and the key order are kept.
	 *
	 * @return void
	 */
	public function testOnlyTheSettingsChange(): void {
		$masked = (new SubscriptionSecretMasker())->mask(
			subscription: ['id' => 's1', 'protocolSettings' => ['url' => 'u', 'signingSecret' => 'x'], 'style' => 'push']
		);

		$this->assertSame(['id' => 's1', 'protocolSettings' => ['url' => 'u', 'signingSecret' => '**********'], 'style' => 'push'], $masked);
	}//end testOnlyTheSettingsChange()
}//end class
