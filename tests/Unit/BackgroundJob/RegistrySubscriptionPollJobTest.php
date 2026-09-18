<?php

/**
 * Integriq — registry subscription poll job tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
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

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\RegistrySubscriptionPollJob;
use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-RSC-003: a change is posted to OpenRegister, an unchanged payload posts
 * nothing, and a 422 is not retried.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
 */
class RegistrySubscriptionPollJobTest extends TestCase {
	/**
	 * Build the job around an update-client double.
	 *
	 * @param RegistryUpdateClient $updateClient The double.
	 *
	 * @return RegistrySubscriptionPollJob The job under test.
	 */
	private function job(RegistryUpdateClient $updateClient, ?LoggerInterface $logger = null): RegistrySubscriptionPollJob {
		return new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			new SubscriptionRegistry([]),
			new SubscriptionRoster($this->createMock(IAppConfig::class)),
			$updateClient,
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end job()

	/**
	 * An update-client double restricted to the method the real class has.
	 *
	 * @return RegistryUpdateClient The double.
	 */
	private function updateClient(): RegistryUpdateClient {
		return $this->getMockBuilder(RegistryUpdateClient::class)
			->disableOriginalConstructor()
			->onlyMethods(['postUpdate'])
			->getMock();
	}//end updateClient()

	/**
	 * A person moves house: one POST carries the identity, the new address and
	 * the event reference.
	 *
	 * @return void
	 */
	public function testAChangeIsPostedWithItsIdentityAddressAndReference(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->once())
			->method('postUpdate')
			->with(
				'brp',
				[
					'identity' => '999993653',
					'properties' => ['verblijfplaats' => ['straat' => 'Nieuwstraat']],
					'eventReference' => 'vi-42',
				]
			)
			->willReturn(200);

		$posted = $this->job($updateClient)->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['verblijfplaats' => ['straat' => 'Nieuwstraat']], 'vi-42')]
		);

		$this->assertSame(1, $posted);
	}//end testAChangeIsPostedWithItsIdentityAddressAndReference()

	/**
	 * An unchanged payload posts nothing at all.
	 *
	 * @return void
	 */
	public function testAnUnchangedPayloadPostsNothing(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->never())->method('postUpdate');

		$posted = $this->job($updateClient)->postChanges('brp', [new SubscriptionChange('999993653', [], 'vi-42')]);

		$this->assertSame(0, $posted);
	}//end testAnUnchangedPayloadPostsNothing()

	/**
	 * A 422 is a property outside the owned set: it is not counted as posted
	 * and it is not retried here.
	 *
	 * @return void
	 */
	public function testA422IsNotCountedAndNotRetried(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->once())->method('postUpdate')->willReturn(422);

		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$posted = $this->job($updateClient, $logger)->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['nationaliteit' => 'NL'], 'vi-43')]
		);

		$this->assertSame(0, $posted);
		$this->assertSame(['registry-subscription.update.rejected'], array_column($logged, 0));
		$this->assertStringContainsString('not retried', $logged[0][1]['reason']);
	}//end testA422IsNotCountedAndNotRetried()

	/**
	 * Any other failure leaves the change for the next run, and is not
	 * reported as posted.
	 *
	 * @return void
	 */
	public function testAnotherFailureIsLeftForTheNextRun(): void {
		$updateClient = $this->updateClient();
		$updateClient->method('postUpdate')->willReturn(503);

		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$posted = $this->job($updateClient, $logger)->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['verblijfplaats' => ['straat' => 'Nieuwstraat']], 'vi-44')]
		);

		$this->assertSame(0, $posted);
		$this->assertSame(['registry-subscription.update.deferred'], array_column($logged, 0));
		$this->assertStringContainsString('next scheduled run', $logged[0][1]['reason']);
	}//end testAnotherFailureIsLeftForTheNextRun()
}//end class
