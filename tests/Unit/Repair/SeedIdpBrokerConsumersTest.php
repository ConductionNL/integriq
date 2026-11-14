<?php

/**
 * The repair step that seeds the disabled identity broker consumers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Auth\Idp\IdpBrokerConfigInterface;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCA\Integriq\Repair\SeedIdpBrokerConsumers;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Repair\SeedIdpBrokerConsumers
 * @uses   \OCA\Integriq\Auth\Idp\IdpConsumer
 */
class SeedIdpBrokerConsumersTest extends TestCase {

	/**
	 * A consumer that is not configured yet is saved, switched off, and reported.
	 *
	 * @return void
	 */
	public function testAMissingConsumerIsSavedDisabled(): void {
		$saved  = [];
		$config = $this->createMock(IdpBrokerConfigInterface::class);
		$config->expects($this->once())->method('hasConsumer')->with('portaliq')->willReturn(false);
		$config->expects($this->once())->method('saveConsumer')->willReturnCallback(
			static function (IdpConsumer $consumer) use (&$saved): void {
				$saved[] = $consumer;
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Seeded the disabled identity broker consumer portaliq.');

		(new SeedIdpBrokerConsumers(config: $config))->run($output);

		$this->assertCount(1, $saved);
		$this->assertSame('portaliq', $saved[0]->getId());
		$this->assertFalse($saved[0]->isEnabled());
		$this->assertSame([], $saved[0]->getReturnUrls());
		$this->assertSame('', $saved[0]->getSecretRef());
	}//end testAMissingConsumerIsSavedDisabled()

	/**
	 * A consumer an administrator already configured is left as it is.
	 *
	 * @return void
	 */
	public function testAnExistingConsumerIsLeftAlone(): void {
		$config = $this->createMock(IdpBrokerConfigInterface::class);
		$config->method('hasConsumer')->with('portaliq')->willReturn(true);
		$config->expects($this->never())->method('saveConsumer');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new SeedIdpBrokerConsumers(config: $config))->run($output);
	}//end testAnExistingConsumerIsLeftAlone()

	/**
	 * The step names itself for occ, and seeds exactly portaliq.
	 *
	 * @return void
	 */
	public function testNameAndSeededConsumers(): void {
		$step = new SeedIdpBrokerConsumers(config: $this->createMock(IdpBrokerConfigInterface::class));

		$this->assertSame('Seed the disabled identity broker consumers', $step->getName());
		$this->assertSame(['portaliq'], SeedIdpBrokerConsumers::SEEDED);
	}//end testNameAndSeededConsumers()
}//end class
