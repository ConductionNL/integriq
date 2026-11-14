<?php

/**
 * Unit tests for ExchangeRateService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ExchangeRateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The newest rate on or before a date, inverted or crossed through the euro.
 *
 * @covers \OCA\Integriq\Service\ExchangeRateService
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */
class ExchangeRateServiceTest extends TestCase {

	/**
	 * Every findAll config the service sent, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A service over a store holding these rates.
	 *
	 * @param array<string, array<string, float>> $store Units per euro, keyed by date then currency.
	 *
	 * @return ExchangeRateService
	 */
	private function serviceOver(array $store): ExchangeRateService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($store): array {
				$this->queries[] = $config;
				$filters = $config['filters'];
				$objects = [];
				foreach ($store as $date => $currencies) {
					if (isset($filters['rateDate']) === true && $filters['rateDate'] !== $date) {
						continue;
					}

					foreach ($currencies as $currency => $rate) {
						$objects[] = [
							'baseCurrency' => 'EUR',
							'currency'     => $currency,
							'rateDate'     => $date,
							'rate'         => $rate,
							'source'       => 'ecb',
						];
					}
				}

				if (isset($config['sort']['rateDate']) === true) {
					usort($objects, static fn (array $a, array $b): int => strcmp($b['rateDate'], $a['rateDate']));
				}

				return ['results' => array_slice($objects, 0, $config['limit'])];
			}
		);

		return new ExchangeRateService(objectService: $objectService);
	}//end serviceOver()

	/**
	 * Dollar to euro on a Sunday is the inverse of Friday's stored dollar rate.
	 *
	 * @return void
	 */
	public function testASundayAnswersTheInverseOfFridaysRate(): void {
		$service = $this->serviceOver(['2026-09-25' => ['USD' => 1.1203, 'GBP' => 0.8744]]);

		$answer = $service->quote(baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-09-27');

		$this->assertArrayNotHasKey('refusal', $answer);
		$this->assertEqualsWithDelta(0.892618, $answer['result']['rate'], 0.0000005);
		$this->assertSame('2026-09-25', $answer['result']['rateDate']);
		$this->assertSame('ecb', $answer['result']['source']);
		$this->assertFalse($answer['result']['derived']);
		// Sunday and Saturday hold nothing, so Friday is the third date read.
		$this->assertSame(['2026-09-27', '2026-09-26', '2026-09-25'], array_map(static fn (array $q): string => $q['filters']['rateDate'], $this->queries));
		$this->assertSame(['register' => 'integriq', 'schema' => 'exchange_rate', 'source' => 'ecb', 'rateDate' => '2026-09-27'], $this->queries[0]['filters']);
	}//end testASundayAnswersTheInverseOfFridaysRate()

	/**
	 * Euro to dollar is the stored rate itself.
	 *
	 * @return void
	 */
	public function testEuroToACurrencyIsTheStoredRate(): void {
		$answer = $this->serviceOver(['2026-09-25' => ['USD' => 1.1203]])->quote(baseCurrency: 'EUR', quoteCurrency: 'USD', asOf: '2026-09-25');

		$this->assertSame(1.1203, $answer['result']['rate']);
		$this->assertFalse($answer['result']['derived']);
	}//end testEuroToACurrencyIsTheStoredRate()

	/**
	 * A pair without the euro divides two rates of the same date and says it is derived.
	 *
	 * @return void
	 */
	public function testAPairWithoutTheEuroIsCrossedThroughTheEuro(): void {
		$answer = $this->serviceOver(['2026-09-25' => ['USD' => 1.1203, 'GBP' => 0.8744]])->quote(baseCurrency: 'USD', quoteCurrency: 'GBP', asOf: '2026-09-25');

		$this->assertEqualsWithDelta(0.8744 / 1.1203, $answer['result']['rate'], 1e-12);
		$this->assertSame('2026-09-25', $answer['result']['rateDate']);
		$this->assertTrue($answer['result']['derived']);
	}//end testAPairWithoutTheEuroIsCrossedThroughTheEuro()

	/**
	 * A cross rate never mixes two dates: a date lacking one side is passed over.
	 *
	 * @return void
	 */
	public function testACrossRateUsesOneDateHoldingBothCurrencies(): void {
		$service = $this->serviceOver([
			'2026-09-25' => ['USD' => 1.10],
			'2026-09-24' => ['USD' => 1.20, 'GBP' => 0.90],
		]);

		$answer = $service->quote(baseCurrency: 'USD', quoteCurrency: 'GBP', asOf: '2026-09-25');

		$this->assertSame('2026-09-24', $answer['result']['rateDate']);
		$this->assertEqualsWithDelta(0.90 / 1.20, $answer['result']['rate'], 1e-12);
	}//end testACrossRateUsesOneDateHoldingBothCurrencies()

	/**
	 * No rate within the allowed age is refused with `no-rate` and the newest date there is.
	 *
	 * @return void
	 */
	public function testNoRecentRateIsRefusedWithTheNewestDate(): void {
		$service = $this->serviceOver([
			'2026-09-01' => ['USD' => 1.10],
			'2026-09-10' => ['USD' => 1.12],
		]);

		$answer = $service->quote(baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-09-27');

		$this->assertArrayNotHasKey('result', $answer);
		$this->assertSame('no-rate', $answer['refusal']['code']);
		$this->assertSame('2026-09-10', $answer['refusal']['newestDate']);
		$this->assertSame(
			'No USD/EUR rate was published in the 7 days up to 2026-09-27; the newest stored rates are of 2026-09-10.',
			$answer['refusal']['reason']
		);
		// Eight dates (the day asked and seven before it), then the newest-date lookup.
		$this->assertCount(9, $this->queries);
		$this->assertSame('2026-09-20', $this->queries[7]['filters']['rateDate']);
		$this->assertSame(['rateDate' => 'DESC'], $this->queries[8]['sort']);
		$this->assertSame(1, $this->queries[8]['limit']);
	}//end testNoRecentRateIsRefusedWithTheNewestDate()

	/**
	 * An empty store refuses with no newest date.
	 *
	 * @return void
	 */
	public function testAnEmptyStoreRefusesWithoutANewestDate(): void {
		$answer = $this->serviceOver([])->quote(baseCurrency: 'EUR', quoteCurrency: 'USD', asOf: '2026-09-27', maxAgeDays: 0);

		$this->assertSame('no-rate', $answer['refusal']['code']);
		$this->assertNull($answer['refusal']['newestDate']);
		$this->assertCount(2, $this->queries);
	}//end testAnEmptyStoreRefusesWithoutANewestDate()

	/**
	 * A currency the ECB does not publish is a `no-rate`, not a rate of zero.
	 *
	 * @return void
	 */
	public function testAnUnpublishedCurrencyIsNoRate(): void {
		$answer = $this->serviceOver(['2026-09-25' => ['USD' => 1.1203]])->quote(baseCurrency: 'XAU', quoteCurrency: 'EUR', asOf: '2026-09-25', maxAgeDays: 1);

		$this->assertSame('no-rate', $answer['refusal']['code']);
		$this->assertSame('2026-09-25', $answer['refusal']['newestDate']);
	}//end testAnUnpublishedCurrencyIsNoRate()

	/**
	 * A malformed question is refused before the store is read.
	 *
	 * @param string $base   The base currency.
	 * @param string $quote  The quote currency.
	 * @param string $asOf   The date.
	 * @param int    $age    The allowed age.
	 * @param string $reason The expected reason.
	 *
	 * @dataProvider malformedQuestions
	 *
	 * @return void
	 */
	public function testAMalformedQuestionIsRefusedWithoutReading(string $base, string $quote, string $asOf, int $age, string $reason): void {
		$answer = $this->serviceOver(['2026-09-25' => ['USD' => 1.1203]])->quote(baseCurrency: $base, quoteCurrency: $quote, asOf: $asOf, maxAgeDays: $age);

		$this->assertSame(['code' => 'invalid-request', 'reason' => $reason, 'newestDate' => null], $answer['refusal']);
		$this->assertSame([], $this->queries);
	}//end testAMalformedQuestionIsRefusedWithoutReading()

	/**
	 * Malformed questions and the reason each one is refused with.
	 *
	 * @return array<string, array{string, string, string, int, string}>
	 */
	public static function malformedQuestions(): array {
		return [
			'lower-case code' => ['usd', 'EUR', '2026-09-25', 7, '"usd" is not a three-letter ISO 4217 currency code.'],
			'long code'       => ['EUR', 'USDX', '2026-09-25', 7, '"USDX" is not a three-letter ISO 4217 currency code.'],
			'no such day'     => ['USD', 'EUR', '2026-02-30', 7, '"2026-02-30" is not a date in the form YYYY-MM-DD.'],
			'time attached'   => ['USD', 'EUR', '2026-09-25T10:00', 7, '"2026-09-25T10:00" is not a date in the form YYYY-MM-DD.'],
			'negative age'    => ['USD', 'EUR', '2026-09-25', -1, 'The allowed age must lie between 0 and 31 days.'],
			'too old'         => ['USD', 'EUR', '2026-09-25', 32, 'The allowed age must lie between 0 and 31 days.'],
		];
	}//end malformedQuestions()

	/**
	 * Stored objects come back as entities too, and an unusable rate is ignored.
	 *
	 * @return void
	 */
	public function testEntitiesAreReadAndUnusableRatesIgnored(): void {
		$usd = new ObjectEntity();
		$usd->setObject(['currency' => 'usd', 'rateDate' => '2026-09-25', 'rate' => '1.25', 'source' => 'ecb']);
		$zero = new ObjectEntity();
		$zero->setObject(['currency' => 'GBP', 'rateDate' => '2026-09-25', 'rate' => 0, 'source' => 'ecb']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['rateDate'] ?? '') === '2026-09-25') ? [$usd, $zero] : []
		);
		$service = new ExchangeRateService(objectService: $objectService);

		$this->assertSame(0.8, $service->quote(baseCurrency: 'USD', quoteCurrency: 'EUR', asOf: '2026-09-25')['result']['rate']);
		$this->assertSame('no-rate', $service->quote(baseCurrency: 'GBP', quoteCurrency: 'EUR', asOf: '2026-09-25', maxAgeDays: 0)['refusal']['code']);
	}//end testEntitiesAreReadAndUnusableRatesIgnored()
}//end class
