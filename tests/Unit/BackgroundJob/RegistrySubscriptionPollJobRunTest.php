<?php

/**
 * RegistrySubscriptionPollJob polls every binding that follows someone, and one failing binding does not stop the others.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\RegistrySubscriptionPollJob;
use OCA\Integriq\Service\Registry\MapsSourceFieldsInterface;
use OCA\Integriq\Service\Registry\RegistryUpdateClientInterface;
use OCA\Integriq\Service\Registry\SubscriptionChangeInterface;
use OCA\Integriq\Service\Registry\SubscriptionProviderInterface;
use OCA\Integriq\Service\Registry\SubscriptionRegistryInterface;
use OCA\Integriq\Service\Registry\SubscriptionRosterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\RegistrySubscriptionPollJob
 */
class RegistrySubscriptionPollJobRunTest extends TestCase {

	/**
	 * A non-empty change for one identity.
	 *
	 * @param string $identity The identity.
	 * @param array<string,mixed> $payload What toArray() returns.
	 *
	 * @return SubscriptionChangeInterface The change.
	 */
	private function change(string $identity, array $payload): SubscriptionChangeInterface {
		$change = $this->createMock(SubscriptionChangeInterface::class);
		$change->method('getIdentity')->willReturn($identity);
		$change->method('isEmpty')->willReturn(false);
		$change->method('toArray')->willReturn($payload);
		return $change;
	}

	public function testEveryBindingWithIdentitiesIsPolledAndAFailingOneIsSkippedWithAWarning(): void {
		$brp = $this->createMock(SubscriptionProviderInterface::class);
		$brp->expects($this->once())->method('pollChanges')->with(['111', '222'])
			->willReturn([$this->change('111', ['id' => '111'])]);
		$kvk = $this->createMock(SubscriptionProviderInterface::class);
		$kvk->expects($this->once())->method('pollChanges')->with(['333'])->willThrowException(new RuntimeException('timeout'));
		$idle = $this->createMock(SubscriptionProviderInterface::class);
		$idle->expects($this->never())->method('pollChanges');
		$after = $this->createMock(SubscriptionProviderInterface::class);
		$after->expects($this->once())->method('pollChanges')->with(['444'])
			->willReturn([$this->change('444', ['id' => '444'])]);

		$registry = $this->createMock(SubscriptionRegistryInterface::class);
		$registry->method('all')->willReturn(['brp' => $brp, 'kvk' => $kvk, 'idle' => $idle, 'after' => $after]);
		$roster = $this->createMock(SubscriptionRosterInterface::class);
		$roster->method('identities')->willReturnMap(
			[
				['brp', ['111' => 'ref-1', '222' => 'ref-2']],
				['kvk', ['333' => '']],
				['idle', []],
				['after', ['444' => '']],
			]
		);

		$posted = [];
		$client = $this->createMock(RegistryUpdateClientInterface::class);
		$client->method('postUpdate')->willReturnCallback(
			function (string $registryId, array $payload) use (&$posted): int {
				$posted[] = [$registryId, $payload];
				return 200;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('registry-subscription.poll.failed', ['registry' => 'kvk', 'error' => 'timeout']);

		$job = new RegistrySubscriptionPollJob($this->createMock(ITimeFactory::class), $registry, $roster, $client, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame([['brp', ['id' => '111']], ['after', ['id' => '444']]], $posted);
		$this->assertSame(900, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testATargetWithoutAFieldMapGetsTheChangeUnchanged(): void {
		$change = $this->createMock(SubscriptionChangeInterface::class);
		$change->method('getIdentity')->willReturn('111');
		$change->method('isEmpty')->willReturn(false);
		$change->method('toArray')->willReturn(['naam' => 'Jansen']);
		$mapped = $this->change('111', ['lastName' => 'Jansen']);
		$change->expects($this->once())->method('mappedTo')->with(['naam' => 'lastName'])->willReturn($mapped);

		$provider = $this->createMockForIntersectionOfInterfaces([SubscriptionProviderInterface::class, MapsSourceFieldsInterface::class]);
		$provider->method('fieldMapFor')->willReturnMap([['person', ['naam' => 'lastName']], ['contact', null]]);
		$roster = $this->createMock(SubscriptionRosterInterface::class);
		$roster->expects($this->once())->method('targets')->with('brp', '111')->willReturn(['person', 'contact']);

		$posted = [];
		$client = $this->createMock(RegistryUpdateClientInterface::class);
		$client->method('postUpdate')->willReturnCallback(
			function (string $registryId, array $payload) use (&$posted): int {
				$posted[] = $payload;
				return 204;
			}
		);

		$job = new RegistrySubscriptionPollJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(SubscriptionRegistryInterface::class),
			$roster,
			$client,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(2, $job->postChanges('brp', [$change], $provider));
		$this->assertSame([['lastName' => 'Jansen'], ['naam' => 'Jansen']], $posted);
	}
}
