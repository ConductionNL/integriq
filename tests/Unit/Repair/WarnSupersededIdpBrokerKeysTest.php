<?php

/**
 * The repair step that warns about superseded identity provider broker keys.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\WarnSupersededIdpBrokerKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Repair\WarnSupersededIdpBrokerKeys
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class WarnSupersededIdpBrokerKeysTest extends TestCase {

	/**
	 * Run the step over the given app config values.
	 *
	 * @param array<string,string> $values  The stored values by key.
	 * @param array<int,string>    $warned  Receives every output warning.
	 * @param array<int,string>    $logged  Receives every logged warning.
	 *
	 * @return void
	 */
	private function runStep(array $values, array &$warned, array &$logged): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($values): string {
				TestCase::assertSame('integriq', $app);
				return ($values[$key] ?? $default);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warned): void {
				$warned[] = $message;
			}
		);

		(new WarnSupersededIdpBrokerKeys(appConfig: $appConfig, logger: $logger))->run($output);
	}//end runStep()

	/**
	 * Each key holding a value gets one warning naming the key and its replacement, never the value.
	 *
	 * @return void
	 */
	public function testEachKeyHoldingAValueIsWarnedAboutWithoutItsValue(): void {
		$warned = [];
		$logged = [];
		$this->runStep(
			values: [
				'idp_broker_salts' => '{"org-a":"plaintext-salt-123"}',
				'idp_broker_trust_aliases' => '{"digid":{"urn:x":"substantieel"}}',
				'idp_broker_entity_ids' => '{}',
			],
			warned: $warned,
			logged: $logged
		);

		$this->assertSame(
			[
				'The app setting idp_broker_salts is no longer read: a salt credential in Keepiq, referenced from each identity provider registration replaces it. Move its contents there, then delete the setting.',
				'The app setting idp_broker_trust_aliases is no longer read: the assuranceMapping of each identity provider registration replaces it. Move its contents there, then delete the setting.',
			],
			$warned
		);
		$this->assertSame(array_map(static fn (string $w): string => '[integriq] ' . $w, $warned), $logged);
		$this->assertStringNotContainsString('plaintext-salt-123', implode(' ', $logged));
		$this->assertStringNotContainsString('urn:x', implode(' ', $logged));
	}//end testEachKeyHoldingAValueIsWarnedAboutWithoutItsValue()

	/**
	 * With none of the keys set the step logs nothing.
	 *
	 * @return void
	 */
	public function testNoKeySetLogsNothing(): void {
		$warned = [];
		$logged = [];
		$this->runStep(values: [], warned: $warned, logged: $logged);

		$this->assertSame([], $warned);
		$this->assertSame([], $logged);
	}//end testNoKeySetLogsNothing()
}//end class
