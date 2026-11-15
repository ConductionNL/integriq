<?php

/**
 * Integriq — KvK mutatieservice and BRP volgindicatie provider call tests.
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
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionResult;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the KvK binding asks its source for, and what it makes of the answer; and the BRP stop call.
 *
 * @covers \OCA\Integriq\Service\Registry\KvkMutatieProvider
 * @covers \OCA\Integriq\Service\Registry\BrpVolgindicatieProvider
 * @uses   \OCA\Integriq\Service\Registry\AbstractSourceSubscriptionProvider
 * @uses   \OCA\Integriq\Service\Registry\SubscriptionResult
 * @uses   \OCA\Integriq\Service\Registry\ChangeRowShape
 * @uses   \OCA\Integriq\Service\Registry\SubscriptionChange
 */
final class KvkMutatieProviderTest extends TestCase {

	/** @var ObjectEntity&MockObject */
	private ObjectEntity $source;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var list<array{string, string, array<string, mixed>}> */
	private array $calls = [];

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->source = $this->createMock(ObjectEntity::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->calls  = [];
	}//end setUp()

	/**
	 * A source answering every call with one status and body, recording each call.
	 *
	 * @param int                  $status The status.
	 * @param array<string, mixed> $body   The body.
	 *
	 * @return CallService
	 */
	private function answering(int $status, array $body): CallService {
		$log = $this->createMock(ObjectEntity::class);
		$log->method('getObject')->willReturn(['statusCode' => $status, 'response' => ['body' => json_encode($body)]]);
		$calls       = &$this->calls;
		$callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$callService->method('call')->willReturnCallback(
			function (...$args) use ($log, &$calls) {
				$this->assertSame($this->source, $args[0]);
				$calls[] = [$args[1], $args[2], $args[3]];
				return $log;
			}
		);

		return $callService;
	}//end answering()

	/**
	 * The connection store, finding the source under the given slug.
	 *
	 * @param string $slug The slug the binding must ask for.
	 *
	 * @return ConnectionStore
	 */
	private function store(string $slug = KvkMutatieProvider::SOURCE_SLUG): ConnectionStore {
		$store = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug'])
			->getMock();
		$store->expects($this->atLeastOnce())->method('findSourceBySlug')->with($slug)->willReturn($this->source);

		return $store;
	}//end store()

	/**
	 * Subscribing posts the KvK number and keeps the abonnement id.
	 *
	 * @return void
	 */
	public function testSubscribePostsTheKvkNumber(): void {
		$provider = new KvkMutatieProvider($this->store(), $this->answering(201, ['abonnementId' => 'ab-7']), $this->logger);

		$result = $provider->subscribe('69599084');

		$this->assertSame([['/abonnementen', 'POST', ['body' => ['kvkNummer' => '69599084']]]], $this->calls);
		$this->assertTrue($result->isActive());
		$this->assertSame('69599084', $result->getIdentity());
		$this->assertSame('ab-7', $result->getReference());
		$this->assertSame('kvk', $provider->registryId());
	}//end testSubscribePostsTheKvkNumber()

	/**
	 * A refused subscription is a failed result with the source's reason.
	 *
	 * @return void
	 */
	public function testARefusedSubscriptionFails(): void {
		$provider = new KvkMutatieProvider($this->store(), $this->answering(409, ['detail' => 'Al geabonneerd']), $this->logger);

		$result = $provider->subscribe('69599084');

		$this->assertSame(SubscriptionResult::STATE_FAILED, $result->getState());
		$this->assertSame('Al geabonneerd', $result->getError());
	}//end testARefusedSubscriptionFails()

	/**
	 * Unsubscribing deletes the number's abonnement, encoding it in the path.
	 *
	 * @return void
	 */
	public function testUnsubscribeDeletesTheAbonnement(): void {
		$provider = new KvkMutatieProvider($this->store(), $this->answering(204, []), $this->logger);

		$provider->unsubscribe('695/99 084');

		$this->assertSame([['/abonnementen/695%2F99%20084', 'DELETE', []]], $this->calls);
	}//end testUnsubscribeDeletesTheAbonnement()

	/**
	 * A poll asks for the mutations of every number, and reads `results` when there are no `mutaties`.
	 *
	 * @return void
	 */
	public function testPollAsksForAllNumbersAndReadsResults(): void {
		$provider = new KvkMutatieProvider(
			$this->store(),
			$this->answering(200, ['results' => [
				['kvkNummer' => '1', 'eventReference' => 'ev-1', 'gewijzigd' => ['naam' => 'Nieuw']],
				['kvkNummer' => '2', 'gewijzigd' => []],
			]]),
			$this->logger
		);

		$changes = $provider->pollChanges(['1', '2']);

		$this->assertSame([['/mutaties', 'GET', ['query' => ['kvkNummer' => '1,2']]]], $this->calls);
		$this->assertCount(1, $changes);
		$this->assertInstanceOf(SubscriptionChange::class, $changes[0]);
		$this->assertSame('1', $changes[0]->getIdentity());
		$this->assertSame(['naam' => 'Nieuw'], $changes[0]->getProperties());
		$this->assertSame('ev-1', $changes[0]->getEventReference());
	}//end testPollAsksForAllNumbersAndReadsResults()

	/**
	 * A failed poll is logged and yields nothing.
	 *
	 * @return void
	 */
	public function testAFailedPollIsLoggedAndYieldsNothing(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('registry-subscription.kvk.poll-failed', ['error' => 'The source answered HTTP 503.']);
		$provider = new KvkMutatieProvider($this->store(), $this->answering(503, []), $this->logger);

		$this->assertSame([], $provider->pollChanges(['1']));
	}//end testAFailedPollIsLoggedAndYieldsNothing()

	/**
	 * The field map exists for Dossiq's company, and for nothing else.
	 *
	 * @return void
	 */
	public function testTheFieldMapCoversOnlyTheCompany(): void {
		$provider = new KvkMutatieProvider($this->createMock(ConnectionStore::class), $this->createMock(CallService::class), $this->logger);

		$this->assertSame(
			['handelsnaam' => 'tradeName', 'naam' => 'tradeName', 'rechtsvorm' => 'legalForm', 'adres' => 'address', 'bezoekadres' => 'address'],
			$provider->fieldMapFor('kvkCompany')
		);
		$this->assertNull($provider->fieldMapFor('person'));
	}//end testTheFieldMapCoversOnlyTheCompany()

	/**
	 * Stopping a BRP volgindicatie deletes it under the person, encoding the BSN.
	 *
	 * @return void
	 */
	public function testBrpUnsubscribeDeletesTheVolgindicatie(): void {
		$provider = new BrpVolgindicatieProvider($this->store(BrpVolgindicatieProvider::SOURCE_SLUG), $this->answering(204, []), $this->logger);

		$provider->unsubscribe('999993653');

		$this->assertSame([['/ingeschrevenpersonen/999993653/volgindicaties', 'DELETE', []]], $this->calls);
	}//end testBrpUnsubscribeDeletesTheVolgindicatie()
}//end class
