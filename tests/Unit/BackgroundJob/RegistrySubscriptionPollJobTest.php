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
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
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
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
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

	/**
	 * A roster backed by an in-memory app config, with targets recorded.
	 *
	 * @param array<string,array<int,string>> $targets Identity to target schema slugs, for `brp`.
	 *
	 * @return SubscriptionRoster The roster.
	 */
	private function rosterWithTargets(array $targets): SubscriptionRoster {
		$config = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$config): string {
				return ($config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$config): bool {
				$config[$key] = $value;
				return true;
			}
		);

		$roster = new SubscriptionRoster($appConfig);
		foreach ($targets as $identity => $slugs) {
			$roster->add('brp', (string)$identity, 'vi');
			foreach ($slugs as $slug) {
				$roster->addTarget('brp', (string)$identity, $slug);
			}
		}

		return $roster;
	}//end rosterWithTargets()

	/**
	 * The BRP binding, built without touching its source.
	 *
	 * @return BrpVolgindicatieProvider The binding.
	 */
	private function brp(): BrpVolgindicatieProvider {
		return new BrpVolgindicatieProvider(
			$this->createMock(ConnectionStore::class),
			$this->createMock(CallService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end brp()

	/**
	 * Collect every payload the job posts.
	 *
	 * @param array<int,array<string,mixed>> $posted Receives the payloads.
	 *
	 * @return RegistryUpdateClient The double.
	 */
	private function recordingClient(array &$posted): RegistryUpdateClient {
		$updateClient = $this->updateClient();
		$updateClient->method('postUpdate')->willReturnCallback(
			static function (string $registryId, array $payload) use (&$posted): int {
				$posted[] = $payload;
				return 200;
			}
		);

		return $updateClient;
	}//end recordingClient()

	/**
	 * A dossiq person moves house: the post carries `residence` and `name`,
	 * never the BRP's own field names (REQ-RSC-004).
	 *
	 * @return void
	 */
	public function testAChangeIsPostedInTheTargetSchemasNames(): void {
		$posted = [];
		$job = new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			new SubscriptionRegistry([]),
			$this->rosterWithTargets(['999993653' => ['brpPerson']]),
			$this->recordingClient($posted),
			$this->createMock(LoggerInterface::class)
		);

		$count = $job->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['verblijfplaats' => ['straat' => 'Nieuwstraat'], 'naam' => ['geslachtsnaam' => 'Jansen']], 'vi-42')],
			$this->brp()
		);

		$this->assertSame(1, $count);
		$this->assertSame(
			[['identity' => '999993653', 'properties' => ['residence' => ['straat' => 'Nieuwstraat'], 'name' => ['geslachtsnaam' => 'Jansen']], 'eventReference' => 'vi-42']],
			$posted
		);
	}//end testAChangeIsPostedInTheTargetSchemasNames()

	/**
	 * Two schemas follow one BSN: one post per schema, each in its own names;
	 * a schema without a map gets the source's names.
	 *
	 * @return void
	 */
	public function testOnePostPerTargetSchema(): void {
		$posted = [];
		$job = new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			new SubscriptionRegistry([]),
			$this->rosterWithTargets(['999993653' => ['brpPerson', 'resident']]),
			$this->recordingClient($posted),
			$this->createMock(LoggerInterface::class)
		);

		$count = $job->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['verblijfplaats' => ['straat' => 'Nieuwstraat']], 'vi-42')],
			$this->brp()
		);

		$this->assertSame(2, $count);
		$this->assertSame(['residence' => ['straat' => 'Nieuwstraat']], $posted[0]['properties']);
		$this->assertSame(['verblijfplaats' => ['straat' => 'Nieuwstraat']], $posted[1]['properties']);
	}//end testOnePostPerTargetSchema()

	/**
	 * A mapped change that keeps nothing posts nothing.
	 *
	 * @return void
	 */
	public function testAMappedChangeThatKeepsNothingPostsNothing(): void {
		$updateClient = $this->updateClient();
		$updateClient->expects($this->never())->method('postUpdate');

		$job = new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			new SubscriptionRegistry([]),
			$this->rosterWithTargets(['999993653' => ['brpPerson']]),
			$updateClient,
			$this->createMock(LoggerInterface::class)
		);

		$count = $job->postChanges('brp', [new SubscriptionChange('999993653', ['aNummer' => '1234567890'], 'vi-44')], $this->brp());

		$this->assertSame(0, $count);
	}//end testAMappedChangeThatKeepsNothingPostsNothing()

	/**
	 * A provider that does not map (the log binding) posts unchanged, even
	 * when targets are recorded.
	 *
	 * @return void
	 */
	public function testABindingWithoutMapsPostsUnchanged(): void {
		$posted = [];
		$job = new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			new SubscriptionRegistry([]),
			$this->rosterWithTargets(['999993653' => ['brpPerson']]),
			$this->recordingClient($posted),
			$this->createMock(LoggerInterface::class)
		);

		$job->postChanges(
			'brp',
			[new SubscriptionChange('999993653', ['verblijfplaats' => []], 'log-1')],
			new LogSubscriptionProvider($this->createMock(LoggerInterface::class))
		);

		$this->assertSame([['identity' => '999993653', 'properties' => ['verblijfplaats' => []], 'eventReference' => 'log-1']], $posted);
	}//end testABindingWithoutMapsPostsUnchanged()
}//end class
