<?php

/**
 * Integriq — registry subscription binding tests.
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

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-RSC-001: a refusal is reported in the source's own words, never as a
 * silent nothing.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class SubscriptionProviderTest extends TestCase {
	/**
	 * A connection store double that finds, or does not find, a source.
	 *
	 * @param bool $found Whether a source is configured.
	 *
	 * @return ConnectionStore The double.
	 */
	private function store(bool $found = true): ConnectionStore {
		$store = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug'])
			->getMock();

		$store->method('findSourceBySlug')->willReturn(
			($found === true ? $this->createMock(ObjectEntity::class) : null)
		);

		return $store;
	}//end store()

	/**
	 * A call service double answering with one canned call log.
	 *
	 * @param int $status HTTP status the source answered with.
	 * @param array<string,mixed> $body The response body.
	 *
	 * @return CallService The double.
	 */
	private function callService(int $status, array $body): CallService {
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getObject')->willReturn(
			['statusCode' => $status, 'response' => ['body' => json_encode($body)]]
		);

		$callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$callService->method('call')->willReturn($callLog);

		return $callService;
	}//end callService()

	/**
	 * A BRP source without a volgindicatie contract refuses, and the refusal
	 * carries the source's error text.
	 *
	 * @return void
	 */
	public function testTheSourceRefusesTheVolgindicatie(): void {
		$provider = new BrpVolgindicatieProvider(
			$this->store(),
			$this->callService(403, ['title' => 'Geen volgindicatie-abonnement voor deze afnemer']),
			$this->createMock(LoggerInterface::class)
		);

		$result = $provider->subscribe('999993653');

		$this->assertSame(SubscriptionResult::STATE_FAILED, $result->getState());
		$this->assertSame('Geen volgindicatie-abonnement voor deze afnemer', $result->getError());
		$this->assertFalse($result->isActive());
	}//end testTheSourceRefusesTheVolgindicatie()

	/**
	 * A transport failure is a reported failure too, not a silent nothing.
	 *
	 * @return void
	 */
	public function testATransportFailureIsReportedNotSwallowed(): void {
		$callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$callService->method('call')->willThrowException(new RuntimeException('connection refused'));

		$provider = new BrpVolgindicatieProvider(
			$this->store(),
			$callService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $provider->subscribe('999993653');

		$this->assertSame(SubscriptionResult::STATE_FAILED, $result->getState());
		$this->assertStringContainsString('connection refused', $result->getError());
	}//end testATransportFailureIsReportedNotSwallowed()

	/**
	 * A binding with no configured source fails naming the source, and does
	 * not call anything.
	 *
	 * @return void
	 */
	public function testAMissingSourceFailsNamingTheSource(): void {
		$callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$callService->expects($this->never())->method('call');

		$provider = new BrpVolgindicatieProvider(
			$this->store(false),
			$callService,
			$this->createMock(LoggerInterface::class)
		);

		$result = $provider->subscribe('999993653');

		$this->assertSame(SubscriptionResult::STATE_FAILED, $result->getState());
		$this->assertStringContainsString(BrpVolgindicatieProvider::SOURCE_SLUG, $result->getError());
	}//end testAMissingSourceFailsNamingTheSource()

	/**
	 * An accepted volgindicatie is active and carries the registry's own
	 * reference.
	 *
	 * @return void
	 */
	public function testAnAcceptedVolgindicatieIsActive(): void {
		$provider = new BrpVolgindicatieProvider(
			$this->store(),
			$this->callService(201, ['volgindicatieId' => 'vi-42']),
			$this->createMock(LoggerInterface::class)
		);

		$result = $provider->subscribe('999993653');

		$this->assertTrue($result->isActive());
		$this->assertSame('vi-42', $result->getReference());
		$this->assertSame('', $result->getError());
	}//end testAnAcceptedVolgindicatieIsActive()

	/**
	 * A poll turns the registry's rows into changes carrying the identity,
	 * what changed and the event reference.
	 *
	 * @return void
	 */
	public function testAPollReturnsTheChangedPropertiesAndTheEventReference(): void {
		$provider = new BrpVolgindicatieProvider(
			$this->store(),
			$this->callService(
				200,
				[
					'_embedded' => [
						'ingeschrevenpersonen' => [
							[
								'burgerservicenummer' => '999993653',
								'volgindicatieId' => 'vi-42',
								'gewijzigd' => ['verblijfplaats' => ['straat' => 'Nieuwstraat']],
							],
							['burgerservicenummer' => '999993654', 'gewijzigd' => []],
						],
					],
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$changes = iterator_to_array($this->toIterator($provider->pollChanges(['999993653', '999993654'])));

		$this->assertCount(1, $changes, 'A row with nothing changed is not a change.');
		$this->assertSame('999993653', $changes[0]->getIdentity());
		$this->assertSame('vi-42', $changes[0]->getEventReference());
		$this->assertSame('Nieuwstraat', $changes[0]->getProperties()['verblijfplaats']['straat']);
	}//end testAPollReturnsTheChangedPropertiesAndTheEventReference()

	/**
	 * A poll with nobody on the roster asks the registry nothing.
	 *
	 * @return void
	 */
	public function testAPollWithAnEmptyRosterAsksNothing(): void {
		$callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$callService->expects($this->never())->method('call');

		$provider = new KvkMutatieProvider(
			$this->store(),
			$callService,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame([], iterator_to_array($this->toIterator($provider->pollChanges([]))));
	}//end testAPollWithAnEmptyRosterAsksNothing()

	/**
	 * The KvK binding reads the mutatieservice rows the same way.
	 *
	 * @return void
	 */
	public function testTheKvkBindingReadsMutatieRows(): void {
		$provider = new KvkMutatieProvider(
			$this->store(),
			$this->callService(
				200,
				[
					'mutaties' => [
						[
							'kvkNummer' => '69599084',
							'mutatieId' => 'kvk-mut-1',
							'gewijzigd' => ['adres' => ['plaats' => 'Utrecht']],
						],
					],
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$changes = iterator_to_array($this->toIterator($provider->pollChanges(['69599084'])));

		$this->assertCount(1, $changes);
		$this->assertSame('kvk-mut-1', $changes[0]->getEventReference());
	}//end testTheKvkBindingReadsMutatieRows()

	/**
	 * The development binding accepts, and yields only what was queued.
	 *
	 * @return void
	 */
	public function testTheLogBindingYieldsOnlyWhatWasQueued(): void {
		$provider = new LogSubscriptionProvider($this->createMock(LoggerInterface::class));

		$this->assertTrue($provider->subscribe('999993653')->isActive());
		$this->assertSame([], iterator_to_array($this->toIterator($provider->pollChanges(['999993653']))));

		$provider->queue(new SubscriptionChange('999993653', ['verblijfplaats' => []], 'log-1'));
		$changes = iterator_to_array($this->toIterator($provider->pollChanges(['999993653'])));

		$this->assertCount(1, $changes);
		$this->assertSame([], iterator_to_array($this->toIterator($provider->pollChanges(['999993653']))), 'A queued change is yielded once.');
	}//end testTheLogBindingYieldsOnlyWhatWasQueued()

	/**
	 * Turn an iterable into an iterator, whichever shape a binding returned.
	 *
	 * @param iterable<SubscriptionChange> $changes The changes.
	 *
	 * @return \Iterator<int,SubscriptionChange> The same changes.
	 */
	private function toIterator(iterable $changes): \Iterator {
		if (is_array($changes) === true) {
			return new \ArrayIterator(array_values($changes));
		}

		return $changes;
	}//end toIterator()
}//end class
