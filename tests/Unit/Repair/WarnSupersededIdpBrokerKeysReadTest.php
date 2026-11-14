<?php

/**
 * The superseded identity provider broker key warning, for a key that cannot be read as text.
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

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Repair\WarnSupersededIdpBrokerKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Repair\WarnSupersededIdpBrokerKeys
 */
class WarnSupersededIdpBrokerKeysReadTest extends TestCase {

	/**
	 * A key stored with another type still holds something, so it is warned
	 * about, and the read failure itself is logged at info level.
	 *
	 * @return void
	 */
	public function testAKeyThatCannotBeReadAsTextIsStillWarnedAbout(): void {
		$unreadable = IdpBrokerConfig::KEY_SALTS;
		$appConfig  = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($unreadable): string {
				if ($key === $unreadable) {
					throw new RuntimeException('typed as array');
				}
				return $default;
			}
		);

		$infos    = [];
		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message) use (&$infos): void {
				$infos[] = $message;
			}
		);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with(
			'The app setting ' . $unreadable . ' is no longer read: '
			. WarnSupersededIdpBrokerKeys::SUPERSEDED[$unreadable]
			. ' replaces it. Move its contents there, then delete the setting.'
		);

		(new WarnSupersededIdpBrokerKeys(appConfig: $appConfig, logger: $logger))->run($output);

		$this->assertSame(['[integriq] ' . $unreadable . ' could not be read as text (RuntimeException)'], $infos);
		$this->assertCount(1, $warnings);
		$this->assertSame(['key' => $unreadable], $warnings[0][1]);
		$this->assertStringStartsWith('[integriq] The app setting ' . $unreadable . ' ', $warnings[0][0]);
	}//end testAKeyThatCannotBeReadAsTextIsStillWarnedAbout()

	/**
	 * The step names itself for occ.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new WarnSupersededIdpBrokerKeys(
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame('Warn about identity provider broker settings that registrations replace', $step->getName());
	}//end testName()
}//end class
