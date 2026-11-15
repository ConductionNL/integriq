<?php

/**
 * Integriq — subscription roster storage tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The roster maps followed BSN and KvK numbers, so it is stored lazy and sensitive.
 */
class SubscriptionRosterTest extends TestCase {

	/**
	 * Every write: [key, lazy, sensitive].
	 *
	 * @var array<int, array{0:string,1:bool,2:bool}>
	 */
	private array $writes = [];

	/**
	 * Every read: [key, lazy].
	 *
	 * @var array<int, array{0:string,1:bool}>
	 */
	private array $reads = [];

	/**
	 * A roster over an app config that records how it is called.
	 *
	 * @return SubscriptionRoster The roster.
	 */
	private function roster(): SubscriptionRoster {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false): string {
				$this->reads[] = [$key, $lazy];
				return $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->writes[] = [$key, $lazy, $sensitive];
				return true;
			}
		);

		return new SubscriptionRoster($appConfig);
	}//end roster()

	/**
	 * Adding an identity and a target writes both maps lazy and sensitive, and reads them as lazy values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function testTheMapsOfFollowedNumbersAreStoredLazyAndSensitive(): void {
		$roster = $this->roster();
		$roster->add(registryId: 'brp', identity: '999993653', reference: 'abo-1');
		$roster->addTarget(registryId: 'brp', identity: '999993653', targetSchema: 'person');
		$roster->remove(registryId: 'brp', identity: '999993653');

		$this->assertNotSame([], $this->writes);
		foreach ($this->writes as [$key, $lazy, $sensitive]) {
			$this->assertTrue($lazy, $key . ' is stored lazy');
			$this->assertTrue($sensitive, $key . ' is stored sensitive');
		}

		foreach ($this->reads as [$key, $lazy]) {
			$this->assertTrue($lazy, $key . ' is read as a lazy value');
		}
	}//end testTheMapsOfFollowedNumbersAreStoredLazyAndSensitive()
}//end class
