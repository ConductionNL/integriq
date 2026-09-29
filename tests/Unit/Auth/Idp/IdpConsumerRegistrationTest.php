<?php

/**
 * Unit tests for registering a consumer: the seed, the occ command and the
 * return-address rules.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCA\Integriq\Command\IdpConsumerCommand;
use OCA\Integriq\Repair\SeedIdpBrokerConsumers;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the consumer registration.
 */
class IdpConsumerRegistrationTest extends TestCase {

	/**
	 * App config, as an array.
	 *
	 * @var array<string,string>
	 */
	private array $settings = [];

	/**
	 * The broker config over the settings array.
	 *
	 * @return IdpBrokerConfig The config.
	 */
	private function config(): IdpBrokerConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->settings[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new IdpBrokerConfig($appConfig);
	}//end config()

	/**
	 * Run the command.
	 *
	 * @param array<string,mixed> $input The input.
	 *
	 * @return CommandTester The finished tester.
	 */
	private function runCommand(array $input): CommandTester {
		$tester = new CommandTester(new IdpConsumerCommand(config: $this->config()));
		$tester->execute($input);
		return $tester;
	}//end runCommand()

	/**
	 * The seed writes a disabled portaliq that can neither start nor redeem.
	 *
	 * @return void
	 */
	public function testTheSeedWritesADisabledPortaliq(): void {
		(new SeedIdpBrokerConsumers(config: $this->config()))->run($this->createMock(IOutput::class));

		$portaliq = $this->config()->consumer(consumer: 'portaliq');
		$this->assertNotNull($portaliq);
		$this->assertFalse($portaliq->isEnabled());
		$this->assertSame([], $portaliq->getReturnUrls());
		$this->assertSame('', $portaliq->getSecretRef());
		$this->assertFalse($portaliq->mayReturnTo(returnUrl: IdpBrowserLoginTest::RETURN_URL));
	}//end testTheSeedWritesADisabledPortaliq()

	/**
	 * The seed never overwrites an entry that exists, in either form.
	 *
	 * @return void
	 */
	public function testTheSeedNeverOverwritesAnExistingEntry(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = (string)json_encode(['portaliq' => 'live-inline-secret-0123456789abcdef']);

		(new SeedIdpBrokerConsumers(config: $this->config()))->run($this->createMock(IOutput::class));

		$this->assertSame('live-inline-secret-0123456789abcdef', $this->config()->consumerSecret(consumer: 'portaliq'));
	}//end testTheSeedNeverOverwritesAnExistingEntry()

	/**
	 * An administrator enables portaliq with one command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function testAnAdministratorEnablesPortaliq(): void {
		(new SeedIdpBrokerConsumers(config: $this->config()))->run($this->createMock(IOutput::class));

		$tester = $this->runCommand(
			[
				'consumer' => 'portaliq',
				'--return-url' => [IdpBrowserLoginTest::RETURN_URL],
				'--secret-ref' => 'cred-1',
			]
		);

		$this->assertSame(0, $tester->getStatusCode());
		$portaliq = $this->config()->consumer(consumer: 'portaliq');
		$this->assertTrue($portaliq->isEnabled());
		$this->assertSame('cred-1', $portaliq->getSecretRef());
		$this->assertTrue($portaliq->mayReturnTo(returnUrl: IdpBrowserLoginTest::RETURN_URL));
		$this->assertStringNotContainsString('secret', strtolower((string)json_encode($portaliq->toConfig()['returnUrls'])));
	}//end testAnAdministratorEnablesPortaliq()

	/**
	 * A return address that is not https, carries a fragment or user info, is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnsafeReturnAddressIsNotRegistered(): void {
		foreach (['http://portal.example.nl/cb', 'https://portal.example.nl/cb#x', 'https://u:p@portal.example.nl/cb', 'javascript:alert(1)', '/relative'] as $url) {
			$tester = $this->runCommand(['consumer' => 'portaliq', '--return-url' => [$url], '--secret-ref' => 'cred-1']);
			$this->assertSame(1, $tester->getStatusCode(), $url);
		}

		$this->assertArrayNotHasKey(IdpBrokerConfig::KEY_CONSUMERS, $this->settings);
		$this->assertTrue(IdpConsumer::isAcceptableReturnUrl(url: 'http://localhost:8080/portal/cb'));
	}//end testAnUnsafeReturnAddressIsNotRegistered()

	/**
	 * An older-form consumer is not moved without a secret reference, so its inline secret is not rewritten.
	 *
	 * @return void
	 */
	public function testAnOlderFormConsumerNeedsASecretReferenceToMove(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = (string)json_encode(['legacyapp' => 'inline-secret-0123456789abcdef0123']);

		$tester = $this->runCommand(['consumer' => 'legacyapp', '--return-url' => ['https://legacy.example.nl/cb']]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertSame('inline-secret-0123456789abcdef0123', $this->config()->consumerSecret(consumer: 'legacyapp'));
	}//end testAnOlderFormConsumerNeedsASecretReferenceToMove()

	/**
	 * Other consumers are left exactly as they are when one is written.
	 *
	 * @return void
	 */
	public function testWritingOneConsumerLeavesTheOthers(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = (string)json_encode(['legacyapp' => 'inline-secret-0123456789abcdef0123']);

		$this->runCommand(['consumer' => 'portaliq', '--return-url' => [IdpBrowserLoginTest::RETURN_URL], '--secret-ref' => 'cred-1']);

		$this->assertSame('inline-secret-0123456789abcdef0123', $this->config()->consumerSecret(consumer: 'legacyapp'));
	}//end testWritingOneConsumerLeavesTheOthers()

}//end class
