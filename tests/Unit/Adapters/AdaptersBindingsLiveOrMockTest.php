<?php

/**
 * Unit tests: the feature flag picks the live education client or keeps the mock.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters;

use OCA\Integriq\Adapters\AdaptersBindings;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientHttp;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientMock;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Adapters\Roster\RosterImportClientZermelo;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientHttp;
use OCA\Integriq\Adapters\Swv\SwvHandoffClientMock;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AdaptersBindings::liveOrMock() and the three education bindings that use it.
 */
class AdaptersBindingsLiveOrMockTest extends TestCase {

	/**
	 * Flag values and whether each binds the live client.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function flags(): array {
		return [
			'off by default' => ['0', false],
			'one' => ['1', true],
			'true' => ['true', true],
			'TRUE' => ['TRUE', true],
			'empty' => ['', false],
			'yes is not on' => ['yes', false],
		];
	}//end flags()

	/**
	 * The flag picks live or mock for each education client.
	 *
	 * @param string $raw  The stored flag.
	 * @param bool   $live Whether the live client is expected.
	 *
	 * @return void
	 */
	#[DataProvider('flags')]
	public function testTheFlagPicksLiveOrMock(string $raw, bool $live): void {
		$pairs = [
			'roster.import.feature_flag' => [RosterImportClientZermelo::class, RosterImportClientMock::class],
			'lvs.import.feature_flag' => [UwlrResultImportClientHttp::class, UwlrResultImportClientMock::class],
			'swv.handoff.feature_flag' => [SwvHandoffClientHttp::class, SwvHandoffClientMock::class],
		];
		foreach ($pairs as $flag => [$liveClass, $mockClass]) {
			$config = $this->createMock(IAppConfig::class);
			$config->expects($this->once())->method('getValueString')->with('integriq', $flag, '0')->willReturn($raw);

			$expected = $mockClass;
			if ($live === true) {
				$expected = $liveClass;
			}

			$this->assertSame(
				expected: $expected,
				actual: AdaptersBindings::liveOrMock(appConfig: $config, flagKey: $flag, live: $liveClass, mock: $mockClass)
			);
		}
	}//end testTheFlagPicksLiveOrMock()

	/**
	 * Each education client is registered through the flag, never as a fixed mock alias.
	 *
	 * @return void
	 */
	public function testTheEducationClientsAreBoundThroughTheirFlag(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Adapters/AdaptersBindings.php');

		foreach (['UwlrResultImportSourceAdapter' => 'UwlrResultImportClientHttp', 'SwvHandoffSourceAdapter' => 'SwvHandoffClientHttp'] as $adapter => $live) {
			$this->assertMatchesRegularExpression(
				'/flagKey: ' . $adapter . '::FLAG_KEY,\s*live: ' . $live . '::class/',
				$source
			);
		}

		$this->assertStringNotContainsString('registerServiceAlias(SwvHandoffClient::class, SwvHandoffClientMock::class)', $source);
	}//end testTheEducationClientsAreBoundThroughTheirFlag()
}//end class
