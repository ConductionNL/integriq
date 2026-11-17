<?php

/**
 * Integriq Settlement Mapper.
 *
 * Turns one provider settlement and the payments, refunds and chargebacks it
 * holds into the settlement shape every binding reports
 * (`PaymentProviderInterface::fetchSettlements()`): decimal-string amounts,
 * one transaction line per payment (positive) and per refund or chargeback
 * (negative). The fees are what the provider kept: gross minus refunds
 * minus chargebacks minus the net payout, so the lines minus the fees add
 * up to the net exactly. Arithmetic runs in integer cents; no float ever
 * touches an amount.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Exception\PaymentProviderException;

/**
 * Maps a provider settlement onto the reported settlement shape.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */
class SettlementMapper {

	/**
	 * Build the reported settlement.
	 *
	 * @param array $settlement  The provider settlement (`id`, `reference`, `settledAt`, `amount{value,currency}`).
	 * @param array $payments    The payments it holds (`id`, `amount{value}`).
	 * @param array $refunds     The refunds it holds (`id`, `paymentId`, `amount{value}`).
	 * @param array $chargebacks The chargebacks it holds (`id`, `paymentId`, `amount{value}`).
	 *
	 * @return array<string, mixed> `{settlementReference, settledAt, currency, amountGross, amountFees,
	 *                              amountRefunds, amountChargebacks, amountNet, transactions}`.
	 *
	 * @throws PaymentProviderException When an amount is not a decimal number.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function map(array $settlement, array $payments, array $refunds, array $chargebacks): array {
		$lines = [];
		$gross = $this->linesOf(rows: $payments, type: 'payment', sign: 1, lines: $lines);
		$refunded = $this->linesOf(rows: $refunds, type: 'refund', sign: -1, lines: $lines);
		$chargedBack = $this->linesOf(rows: $chargebacks, type: 'chargeback', sign: -1, lines: $lines);
		$net = $this->toCents(value: ($settlement['amount']['value'] ?? '0'));

		return [
			'settlementReference' => (string)($settlement['reference'] ?? $settlement['id'] ?? ''),
			'settledAt' => (string)($settlement['settledAt'] ?? ''),
			'currency' => (string)($settlement['amount']['currency'] ?? ''),
			'amountGross' => $this->toDecimal(cents: $gross),
			'amountFees' => $this->toDecimal(cents: ($gross - $refunded - $chargedBack - $net)),
			'amountRefunds' => $this->toDecimal(cents: $refunded),
			'amountChargebacks' => $this->toDecimal(cents: $chargedBack),
			'amountNet' => $this->toDecimal(cents: $net),
			'transactions' => $lines,
		];

	}//end map()

	/**
	 * Append one line per row and return the rows' total in cents.
	 *
	 * @param array  $rows  The provider rows.
	 * @param string $type  The line type.
	 * @param int    $sign  1 for money in, -1 for money out.
	 * @param array  $lines The lines so far (appended to).
	 *
	 * @return int The rows' total in cents, unsigned.
	 *
	 * @throws PaymentProviderException When an amount is not a decimal number.
	 */
	private function linesOf(array $rows, string $type, int $sign, array &$lines): int {
		$total = 0;
		foreach ($rows as $row) {
			$cents = abs($this->toCents(value: ($row['amount']['value'] ?? '0')));
			$total += $cents;
			$lines[] = [
				'providerPaymentId' => (string)($row['paymentId'] ?? $row['id'] ?? ''),
				'type' => $type,
				'amount' => $this->toDecimal(cents: ($sign * $cents)),
				'fee' => '0.00',
			];
		}

		return $total;

	}//end linesOf()

	/**
	 * A decimal amount string in integer cents.
	 *
	 * @param mixed $value A decimal string such as `612.40` or `-22.85`.
	 *
	 * @return int The amount in cents.
	 *
	 * @throws PaymentProviderException When the value is not a decimal number with at most two decimals.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function toCents(mixed $value): int {
		$text = trim((string)$value);
		if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
			throw new PaymentProviderException(message: 'The provider reported an amount that is not a decimal number: "' . $text . '".');
		}

		$cents = ((int)$matches[2] * 100) + (int)str_pad(($matches[3] ?? ''), 2, '0');

		if ($matches[1] === '-') {
			return -$cents;
		}

		return $cents;

	}//end toCents()

	/**
	 * Integer cents as a decimal string with two decimals.
	 *
	 * @param int $cents The amount in cents.
	 *
	 * @return string Such as `585.49` or `-22.85`.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function toDecimal(int $cents): string {
		$sign = '';
		if ($cents < 0) {
			$sign = '-';
		}

		$cents = abs($cents);

		return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);

	}//end toDecimal()
}//end class
