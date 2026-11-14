<?php

/**
 * Unit tests for LogDigipoortProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-1-source-type-and-providers
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Digipoort;

use OCA\Integriq\Service\Digipoort\LogDigipoortProvider;
use OCA\Integriq\Service\Digipoort\SbrDelivery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox binding: deterministic references, scripted statuses, no network.
 */
#[CoversClass(LogDigipoortProvider::class)]
#[UsesClass(SbrDelivery::class)]
class LogDigipoortProviderTest extends TestCase {
	/**
	 * The same filing gets the same reference, another filing another one.
	 *
	 * @return void
	 */
	public function testTheReferenceIsDeterministicPerFiling(): void {
		$provider = new LogDigipoortProvider();

		$one = $provider->deliver(configuration: [], delivery: $this->delivery(kenmerk: 'SBR-A'));
		$again = $provider->deliver(configuration: ['provider' => 'log'], delivery: $this->delivery(kenmerk: 'SBR-A'));
		$other = $provider->deliver(configuration: [], delivery: $this->delivery(kenmerk: 'SBR-B'));

		$this->assertSame($one, $again);
		$this->assertNotSame($one, $other);
		$this->assertMatchesRegularExpression('/^LOG-SBR-[0-9A-F]{12}$/', $one);
	}//end testTheReferenceIsDeterministicPerFiling()

	/**
	 * Without a script the status service answers delivered, then accepted.
	 *
	 * @return void
	 */
	public function testTheDefaultScriptIsDeliveredThenAccepted(): void {
		$statuses = (new LogDigipoortProvider())->statuses(configuration: [], deliveryReference: 'LOG-SBR-1');

		$this->assertSame(['delivered', 'accepted'], array_column($statuses, 'status'));
		$this->assertSame(['100', '500'], array_column($statuses, 'code'));
		$this->assertSame([[], []], array_column($statuses, 'errors'));
	}//end testTheDefaultScriptIsDeliveredThenAccepted()

	/**
	 * A scripted rejection carries its errors; an entry with an unknown status is left out.
	 *
	 * @return void
	 */
	public function testAScriptedRejectionCarriesItsErrors(): void {
		$statuses = (new LogDigipoortProvider())->statuses(
			configuration: [
				'mockStatuses' => [
					['status' => 'delivered', 'code' => '100'],
					['status' => 'processing'],
					'not an entry',
					['status' => 'rejected', 'code' => '510', 'errors' => [['code' => 'OB0001', 'text' => 'Al aangegeven'], 'x']],
				],
			],
			deliveryReference: 'LOG-SBR-1'
		);

		$this->assertSame(
			[
				['status' => 'delivered', 'code' => '100', 'description' => '', 'at' => '', 'errors' => []],
				['status' => 'rejected', 'code' => '510', 'description' => '', 'at' => '', 'errors' => [['code' => 'OB0001', 'text' => 'Al aangegeven']]],
			],
			$statuses
		);
	}//end testAScriptedRejectionCarriesItsErrors()

	/**
	 * A script that is not a list falls back to the default.
	 *
	 * @return void
	 */
	public function testAMalformedScriptFallsBackToTheDefault(): void {
		$statuses = (new LogDigipoortProvider())->statuses(configuration: ['mockStatuses' => 'accepted'], deliveryReference: 'LOG-SBR-1');

		$this->assertSame(['delivered', 'accepted'], array_column($statuses, 'status'));
	}//end testAMalformedScriptFallsBackToTheDefault()

	/**
	 * A delivery for a kenmerk.
	 *
	 * @param string $kenmerk The sender's kenmerk.
	 *
	 * @return SbrDelivery
	 */
	private function delivery(string $kenmerk): SbrDelivery {
		return new SbrDelivery(
			berichtsoort: 'Omzetbelasting',
			aanleverkenmerk: $kenmerk,
			identityNumber: '001234567B01',
			identityType: 'Fi',
			instance: '<xbrl/>',
			fileName: 'ob.xbrl'
		);
	}//end delivery()
}//end class
