<?php

/**
 * Integriq ExchangeRateService.
 *
 * Answers the rate of one currency pair on one date from the stored ECB
 * euro reference rates (sources-ecb-exchange-rates, design D2).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The newest stored rate on or before a date, inverted or crossed through the euro.
 *
 * Every stored ECB rate is units of a currency per euro. The rate of a pair is
 * the quote currency's euro rate divided by the base currency's euro rate on
 * the same publication date, with the euro itself at 1. A pair without the
 * euro is such a division of two stored rates and is marked derived.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */
class ExchangeRateService {

	/**
	 * Refusal code when no rate lies within the allowed age.
	 *
	 * @var string
	 */
	public const REFUSAL_NO_RATE = 'no-rate';

	/**
	 * Refusal code for a malformed currency, date or age.
	 *
	 * @var string
	 */
	public const REFUSAL_INVALID = 'invalid-request';

	/**
	 * Refusal code when the rates could not be read.
	 *
	 * @var string
	 */
	public const REFUSAL_UNAVAILABLE = 'unavailable';

	/**
	 * The largest age a caller may allow, so one question reads at most this many dates.
	 *
	 * @var integer
	 */
	public const MAX_AGE_LIMIT = 31;

	/**
	 * Register of the stored rates; the same value ExchangeRateImportService writes to.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * Schema of the stored rates; the same value ExchangeRateImportService writes to.
	 *
	 * @var string
	 */
	private const SCHEMA = 'exchange_rate';

	/**
	 * Source marker on every stored ECB rate; the same value ExchangeRateImportService sets.
	 *
	 * @var string
	 */
	private const RATE_SOURCE = 'ecb';

	/**
	 * The currency every stored rate is expressed against.
	 *
	 * @var string
	 */
	private const BASE_CURRENCY = 'EUR';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object store holding the `exchange_rate` objects.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The rate of a pair on a date, or why there is none.
	 *
	 * @param string $baseCurrency  ISO 4217 code of the currency one unit is quoted of.
	 * @param string $quoteCurrency ISO 4217 code of the currency the rate is expressed in.
	 * @param string $asOf          The date asked for, `Y-m-d`.
	 * @param int    $maxAgeDays    How many days before `asOf` the rate may be published.
	 *
	 * @return array<string,mixed> Either `{result: {rate, rateDate, source, derived}}`
	 *                             or `{refusal: {code, reason, newestDate}}`.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function quote(string $baseCurrency, string $quoteCurrency, string $asOf, int $maxAgeDays = 7): array {
		$invalid = $this->invalidReason(base: $baseCurrency, quote: $quoteCurrency, asOf: $asOf, maxAgeDays: $maxAgeDays);
		if ($invalid !== null) {
			return ['refusal' => ['code' => self::REFUSAL_INVALID, 'reason' => $invalid, 'newestDate' => null]];
		}

		$day = new DateTimeImmutable($asOf);
		for ($age = 0; $age <= $maxAgeDays; $age++) {
			$date  = $day->modify('-' . $age . ' days')->format('Y-m-d');
			$rates = $this->ratesOn(date: $date);
			if (isset($rates[$baseCurrency], $rates[$quoteCurrency]) === true) {
				return ['result' => $this->answer(base: $baseCurrency, quote: $quoteCurrency, rates: $rates, date: $date)];
			}
		}

		$newest = $this->newestDate();

		return [
			'refusal' => [
				'code'       => self::REFUSAL_NO_RATE,
				'reason'     => sprintf(
					'No %s/%s rate was published in the %d days up to %s; the newest stored rates are of %s.',
					$baseCurrency,
					$quoteCurrency,
					$maxAgeDays,
					$asOf,
					($newest ?? 'no date')
				),
				'newestDate' => $newest,
			],
		];
	}//end quote()

	/**
	 * Why a question cannot be asked, or null when it can.
	 *
	 * @param string $base       The base currency.
	 * @param string $quote      The quote currency.
	 * @param string $asOf       The date.
	 * @param int    $maxAgeDays The allowed age.
	 *
	 * @return string|null The reason.
	 */
	private function invalidReason(string $base, string $quote, string $asOf, int $maxAgeDays): ?string {
		foreach ([$base, $quote] as $currency) {
			if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
				return sprintf('"%s" is not a three-letter ISO 4217 currency code.', $currency);
			}
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
		if ($parsed === false || $parsed->format('Y-m-d') !== $asOf) {
			return sprintf('"%s" is not a date in the form YYYY-MM-DD.', $asOf);
		}

		if ($maxAgeDays < 0 || $maxAgeDays > self::MAX_AGE_LIMIT) {
			return sprintf('The allowed age must lie between 0 and %d days.', self::MAX_AGE_LIMIT);
		}

		return null;
	}//end invalidReason()

	/**
	 * The answer for a pair from one date's euro rates.
	 *
	 * @param string               $base  The base currency.
	 * @param string               $quote The quote currency.
	 * @param array<string, float> $rates Units of each currency per euro, the euro at 1.
	 * @param string               $date  The publication date of those rates.
	 *
	 * @return array<string,mixed> `{rate, rateDate, source, derived}`.
	 */
	private function answer(string $base, string $quote, array $rates, string $date): array {
		$euro = self::BASE_CURRENCY;

		return [
			'rate'     => ($rates[$quote] / $rates[$base]),
			'rateDate' => $date,
			'source'   => self::RATE_SOURCE,
			'derived'  => ($base !== $euro && $quote !== $euro && $base !== $quote),
		];
	}//end answer()

	/**
	 * Units of each currency per euro on one publication date, the euro at 1.
	 *
	 * @param string $date The publication date.
	 *
	 * @return array<string, float> Empty when nothing was published that day.
	 */
	private function ratesOn(string $date): array {
		$rates = [];
		foreach ($this->stored(filters: ['rateDate' => $date], options: ['limit' => 1000]) as $data) {
			$rate = ($data['rate'] ?? null);
			if (($data['rateDate'] ?? '') !== $date || isset($data['currency']) === false || is_numeric($rate) === false || (float)$rate <= 0.0) {
				continue;
			}

			$rates[strtoupper((string)$data['currency'])] = (float)$rate;
		}

		if ($rates === []) {
			return [];
		}

		$rates[self::BASE_CURRENCY] = 1.0;

		return $rates;
	}//end ratesOn()

	/**
	 * The newest stored publication date, or null when none is stored.
	 *
	 * @return string|null The date.
	 */
	private function newestDate(): ?string {
		$newest = null;
		foreach ($this->stored(filters: [], options: ['limit' => 1, 'sort' => ['rateDate' => 'DESC']]) as $data) {
			$date = (string)($data['rateDate'] ?? '');
			if ($date !== '' && ($newest === null || strcmp($date, $newest) > 0)) {
				$newest = $date;
			}
		}

		return $newest;
	}//end newestDate()

	/**
	 * The data of the stored ECB rate objects matching extra filters.
	 *
	 * @param array<string, mixed> $filters Filters added after register, schema and source.
	 * @param array<string, mixed> $options Query options after the filters (limit, sort).
	 *
	 * @return list<array<string, mixed>> The object data.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	private function stored(array $filters, array $options): array {
		// System path: the rate table is instance-wide reference data any app may read.
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => self::SCHEMA,
					'source'   => self::RATE_SOURCE,
				] + $filters,
			] + $options,
			_rbac: false,
			_multitenancy: false
		);

		return $this->dataOf(matches: $matches);
	}//end stored()

	/**
	 * The object data of a findAll answer, whether it is paged or a bare list.
	 *
	 * @param mixed $matches What findAll returned.
	 *
	 * @return list<array<string,mixed>> One data array per object.
	 */
	private function dataOf(mixed $matches): array {
		$items = $matches;
		if (is_array($matches) === true && array_key_exists('results', $matches) === true) {
			$items = $matches['results'];
		}

		if (is_array($items) === false) {
			return [];
		}

		$data = [];
		foreach ($items as $item) {
			if ($item instanceof ObjectEntity) {
				$data[] = $item->getObject();
				continue;
			}

			if (is_array($item) === true) {
				$data[] = $item;
			}
		}

		return $data;
	}//end dataOf()
}//end class
