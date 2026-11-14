<?php

/**
 * Unit tests for the broker's app-config reader.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Settings are read from the integriq app config; a malformed map reads as empty.
 */
#[CoversClass(IdpBrokerConfig::class)]
#[UsesClass(IdpConsumer::class)]
class IdpBrokerConfigTest extends TestCase {

	/**
	 * The stored settings, by key.
	 *
	 * @var array<string,string>
	 */
	private array $settings = [];

	/**
	 * A config reader over the in-memory settings.
	 *
	 * @return IdpBrokerConfig
	 */
	private function config(): IdpBrokerConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('integriq', $app);
				return ($this->settings[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('integriq', $app);
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new IdpBrokerConfig($appConfig);

	}//end config()

	/**
	 * Only the exact value '1' enables the broker.
	 *
	 * @return void
	 */
	public function testOnlyOneEnablesTheBroker(): void {
		$this->assertFalse($this->config()->isEnabled());

		foreach (['true' => false, 'yes' => false, '0' => false, '1' => true] as $value => $expected) {
			$this->settings[IdpBrokerConfig::KEY_ENABLED] = (string)$value;
			$this->assertSame($expected, $this->config()->isEnabled(), "value {$value}");
		}

	}//end testOnlyOneEnablesTheBroker()

	/**
	 * The signing key is read as stored, empty when unset.
	 *
	 * @return void
	 */
	public function testSigningKey(): void {
		$this->assertSame('', $this->config()->signingKey());

		$this->settings[IdpBrokerConfig::KEY_SIGNING] = 'k-123';
		$this->assertSame('k-123', $this->config()->signingKey());

	}//end testSigningKey()

	/**
	 * An unknown, empty or malformed consumer has no entry and no secret.
	 *
	 * @return void
	 */
	public function testUnknownEmptyOrMalformedConsumerHasNoSecret(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = json_encode(['' => 'blank-id-secret', 'numeric' => 5]);
		$config = $this->config();

		$this->assertNull($config->consumer(consumer: 'portal'));
		$this->assertSame('', $config->consumerSecret(consumer: 'portal'));
		$this->assertNull($config->consumer(consumer: ''));
		$this->assertSame('', $config->consumerSecret(consumer: ''));
		$this->assertNull($config->consumer(consumer: 'numeric'));
		$this->assertTrue($config->hasConsumer(consumer: 'numeric'));
		$this->assertFalse($config->hasConsumer(consumer: 'portal'));

	}//end testUnknownEmptyOrMalformedConsumerHasNoSecret()

	/**
	 * A consumer in the older string form yields its inline secret; the current form yields none.
	 *
	 * @return void
	 */
	public function testConsumerSecretComesOnlyFromTheOlderForm(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = json_encode(
			[
				'legacy' => 'inline-secret',
				'current' => ['enabled' => true, 'secretRef' => 'ref-1', 'returnUrls' => ['https://a.example']],
			]
		);
		$config = $this->config();

		$this->assertSame('inline-secret', $config->consumerSecret(consumer: 'legacy'));
		$this->assertSame('', $config->consumerSecret(consumer: 'current'));
		$this->assertSame('ref-1', $config->consumer(consumer: 'current')?->getSecretRef());

	}//end testConsumerSecretComesOnlyFromTheOlderForm()

	/**
	 * Saving a consumer writes its current form and keeps the other consumers.
	 *
	 * @return void
	 */
	public function testSaveConsumerKeepsTheOthers(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = json_encode(['legacy' => 'inline-secret']);

		$this->config()->saveConsumer(
			new IdpConsumer(id: 'portal', enabled: true, returnUrls: ['https://p.example/cb'], secretRef: 'ref-2', secretOrganisation: 'org-1')
		);

		$this->assertSame(
			[
				'legacy' => 'inline-secret',
				'portal' => [
					'enabled' => true,
					'returnUrls' => ['https://p.example/cb'],
					'secretRef' => 'ref-2',
					'secretOrganisation' => 'org-1',
				],
			],
			json_decode($this->settings[IdpBrokerConfig::KEY_CONSUMERS], true)
		);
		$this->assertStringContainsString('https://p.example/cb', $this->settings[IdpBrokerConfig::KEY_CONSUMERS]);

	}//end testSaveConsumerKeepsTheOthers()

	/**
	 * The entity ids, salts and assurance aliases are no longer read from app
	 * config: each organisation's registration carries them (task 7). Values
	 * still stored under the superseded keys are ignored.
	 *
	 * @return void
	 */
	public function testTheSupersededKeysAreNoLongerRead(): void {
		foreach (['entityId', 'organisationSalt', 'trustAliases'] as $method) {
			$this->assertFalse(method_exists(IdpBrokerConfig::class, $method), $method . ' is still read');
		}

		$read = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$read) {
				$read[] = $key;
				return ($this->settings[$key] ?? $default);
			}
		);
		$config = new IdpBrokerConfig($appConfig);
		$config->isEnabled();
		$config->consumer(consumer: 'portaliq');
		$config->hasConsumer(consumer: 'portaliq');

		$this->assertNotContains(IdpBrokerConfig::KEY_ENTITY_IDS, $read);
		$this->assertNotContains(IdpBrokerConfig::KEY_SALTS, $read);
		$this->assertNotContains(IdpBrokerConfig::KEY_TRUST_ALIASES, $read);

	}//end testTheSupersededKeysAreNoLongerRead()

	/**
	 * A setting that is not a JSON object reads as an empty map.
	 *
	 * @return void
	 */
	public function testANonObjectSettingReadsAsEmpty(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = '"legacy"';
		$config = $this->config();

		$this->assertFalse($config->hasConsumer(consumer: 'legacy'));
		$this->assertNull($config->consumer(consumer: '0'));

	}//end testANonObjectSettingReadsAsEmpty()
}//end class
