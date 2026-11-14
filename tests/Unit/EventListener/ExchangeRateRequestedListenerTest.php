<?php

/**
 * The typed exchange rate question, answered through the listener with the
 * real event class, as a sibling app (shillinq) dispatches it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use DateTimeImmutable;
use OCA\Integriq\Event\ExchangeRateRequestedEvent;
use OCA\Integriq\EventListener\ExchangeRateRequestedListener;
use OCA\Integriq\Service\ExchangeRateService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\EventListener\ExchangeRateRequestedListener
 * @covers \OCA\Integriq\Event\ExchangeRateRequestedEvent
 * @uses   \OCA\Integriq\Service\ExchangeRateService
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */
class ExchangeRateRequestedListenerTest extends TestCase {

	/**
	 * A listener over a store holding the rates of Friday 2026-09-25.
	 *
	 * @param LoggerInterface|null $logger The logger, a stub when null.
	 *
	 * @return ExchangeRateRequestedListener
	 */
	private function listener(?LoggerInterface $logger = null): ExchangeRateRequestedListener {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				$date = ($config['filters']['rateDate'] ?? '2026-09-25');
				if ($date !== '2026-09-25') {
					return ['results' => []];
				}

				return ['results' => [
					['currency' => 'USD', 'rateDate' => '2026-09-25', 'rate' => 1.1203, 'source' => 'ecb'],
					['currency' => 'GBP', 'rateDate' => '2026-09-25', 'rate' => 0.8744, 'source' => 'ecb'],
				]];
			}
		);

		return new ExchangeRateRequestedListener(
			rates: new ExchangeRateService(objectService: $objectService),
			logger: ($logger ?? $this->createStub(LoggerInterface::class))
		);
	}//end listener()

	/**
	 * Shillinq books a dollar invoice on a Sunday: Friday's rate, inverted.
	 *
	 * @return void
	 */
	public function testShillinqBooksADollarInvoiceOnASunday(): void {
		$event = new ExchangeRateRequestedEvent(sourceApp: 'shillinq', baseCurrency: 'usd', quoteCurrency: 'EUR', asOf: '2026-09-27');

		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$result = $event->getResult();
		$this->assertEqualsWithDelta(0.892618, $result['rate'], 0.0000005);
		$this->assertSame(['rateDate' => '2026-09-25', 'source' => 'ecb', 'derived' => false], array_diff_key($result, ['rate' => true]));
	}//end testShillinqBooksADollarInvoiceOnASunday()

	/**
	 * A pair without the euro is derived through the euro and says so.
	 *
	 * @return void
	 */
	public function testAPairWithoutTheEuroIsDerived(): void {
		$event = new ExchangeRateRequestedEvent(
			sourceApp: 'shillinq',
			baseCurrency: 'USD',
			quoteCurrency: 'GBP',
			asOf: new DateTimeImmutable('2026-09-25 14:00:00')
		);

		$this->listener()->handle($event);

		$this->assertSame('2026-09-25', $event->getAsOf());
		$this->assertTrue($event->getResult()['derived']);
		$this->assertEqualsWithDelta(0.8744 / 1.1203, $event->getResult()['rate'], 1e-12);
	}//end testAPairWithoutTheEuroIsDerived()

	/**
	 * No rate within the allowed age: refusal `no-rate` with the newest date, no exception.
	 *
	 * @return void
	 */
	public function testNoRecentRateIsRefusedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Integriq: an exchange rate question was refused (no-rate)',
			['sourceApp' => 'shillinq', 'baseCurrency' => 'USD', 'quoteCurrency' => 'EUR', 'asOf' => '2026-10-20']
		);
		$event = new ExchangeRateRequestedEvent(sourceApp: 'shillinq', baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-10-20');

		$this->listener(logger: $logger)->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getResult());
		$this->assertSame('no-rate', $event->getRefusal()['code']);
		$this->assertSame('2026-09-25', $event->getRefusal()['newestDate']);
	}//end testNoRecentRateIsRefusedNotThrown()

	/**
	 * A store that cannot be read is answered `unavailable`, never thrown to the caller.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreIsAnsweredUnavailable(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq: an exchange rate question could not be answered',
			['sourceApp' => 'shillinq', 'baseCurrency' => 'USD', 'quoteCurrency' => 'EUR', 'asOf' => '2026-09-25', 'error' => 'database gone']
		);
		$listener = new ExchangeRateRequestedListener(rates: new ExchangeRateService(objectService: $objectService), logger: $logger);
		$event    = new ExchangeRateRequestedEvent(sourceApp: 'shillinq', baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-09-25');

		$listener->handle($event);

		$this->assertSame(
			['code' => 'unavailable', 'reason' => 'The stored exchange rates could not be read.', 'newestDate' => null],
			$event->getRefusal()
		);
		$this->assertNull($event->getResult());
	}//end testAnUnreadableStoreIsAnsweredUnavailable()

	/**
	 * Another event passes through untouched.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');
		$listener = new ExchangeRateRequestedListener(
			rates: new ExchangeRateService(objectService: $objectService),
			logger: $this->createStub(LoggerInterface::class)
		);

		$listener->handle(new Event());

		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()

	/**
	 * A refusal replaces an earlier result, and a result an earlier refusal: never both.
	 *
	 * @return void
	 */
	public function testTheEventNeverHoldsBothAResultAndARefusal(): void {
		$event = new ExchangeRateRequestedEvent(sourceApp: 'shillinq', baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-09-25', maxAgeDays: 3);
		$this->assertFalse($event->isHandled());
		$this->assertSame(3, $event->getMaxAgeDays());
		$this->assertSame('shillinq', $event->getSourceApp());

		$event->setResult(['rate' => 1.0]);
		$event->setRefusal(code: 'no-rate', reason: 'none', newestDate: '2026-09-01');
		$this->assertNull($event->getResult());
		$this->assertSame(['code' => 'no-rate', 'reason' => 'none', 'newestDate' => '2026-09-01'], $event->getRefusal());

		$event->setResult(['rate' => 2.0]);
		$this->assertNull($event->getRefusal());
		$this->assertSame(['rate' => 2.0], $event->getResult());
	}//end testTheEventNeverHoldsBothAResultAndARefusal()
}//end class
