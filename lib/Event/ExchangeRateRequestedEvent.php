<?php

/**
 * Integriq ExchangeRateRequested Event.
 *
 * ADR-041 typed question for sibling apps (sources-ecb-exchange-rates,
 * design D2). A sibling app (shillinq) asks for the rate of one currency
 * pair on one date; integriq answers synchronously inside dispatchTyped()
 * from the stored ECB euro reference rates.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

use DateTimeInterface;
use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "what is this pair's rate on this date".
 *
 * A handled event carries either a result or a refusal, never both. The
 * result holds `rate` (units of the quote currency per unit of the base
 * currency), `rateDate` (the publication date the rate applies to), `source`
 * and `derived` (true for a cross rate through the euro). The refusal holds
 * a `code` (`no-rate`, `invalid-request` or `unavailable`), a `reason` and
 * `newestDate`, the newest stored publication date or null.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */
class ExchangeRateRequestedEvent extends Event {

	/**
	 * Whether integriq answered.
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The answer, when there is a rate.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * The refusal, when there is no rate.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string                   $sourceApp     The asking app id, for example `shillinq`.
	 * @param string                   $baseCurrency  ISO 4217 code of the currency one unit is quoted of.
	 * @param string                   $quoteCurrency ISO 4217 code of the currency the rate is expressed in.
	 * @param string|DateTimeInterface $asOf          The date asked for, `Y-m-d` or a date object.
	 * @param int                      $maxAgeDays    How many days before `asOf` a rate may be published.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $baseCurrency,
		private readonly string $quoteCurrency,
		private readonly string|DateTimeInterface $asOf,
		private readonly int $maxAgeDays = 7,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app id.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The base currency, upper case.
	 *
	 * @return string The ISO 4217 code.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getBaseCurrency(): string {
		return strtoupper(trim($this->baseCurrency));
	}//end getBaseCurrency()

	/**
	 * The quote currency, upper case.
	 *
	 * @return string The ISO 4217 code.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getQuoteCurrency(): string {
		return strtoupper(trim($this->quoteCurrency));
	}//end getQuoteCurrency()

	/**
	 * The date asked for, as `Y-m-d`.
	 *
	 * @return string The date; a date object is read in its own time zone.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getAsOf(): string {
		if ($this->asOf instanceof DateTimeInterface) {
			return $this->asOf->format('Y-m-d');
		}

		return trim($this->asOf);
	}//end getAsOf()

	/**
	 * How many days before the date asked a rate may be published.
	 *
	 * @return int The age in days.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getMaxAgeDays(): int {
		return $this->maxAgeDays;
	}//end getMaxAgeDays()

	/**
	 * Record the rate and mark the event handled.
	 *
	 * @param array<string,mixed> $result `{rate, rateDate, source, derived}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function setResult(array $result): void {
		$this->result  = $result;
		$this->refusal = null;
		$this->handled = true;
	}//end setResult()

	/**
	 * Record a refusal and mark the event handled.
	 *
	 * @param string      $code       `no-rate`, `invalid-request` or `unavailable`.
	 * @param string      $reason     Why there is no rate.
	 * @param string|null $newestDate The newest stored publication date, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function setRefusal(string $code, string $reason, ?string $newestDate = null): void {
		$this->refusal = ['code' => $code, 'reason' => $reason, 'newestDate' => $newestDate];
		$this->result  = null;
		$this->handled = true;
	}//end setRefusal()

	/**
	 * Whether integriq answered.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The rate, when there is one.
	 *
	 * @return array<string,mixed>|null `{rate, rateDate, source, derived}`.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * The refusal, when there is no rate.
	 *
	 * @return array<string,mixed>|null `{code, reason, newestDate}`.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
