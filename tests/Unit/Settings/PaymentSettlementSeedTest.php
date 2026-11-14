<?php

/**
 * The demo payout seed (messaging-payment-command-settlements-and-point-of-sale, Task 5).
 *
 * The seed ships one `payment_settlement`, `st_example0001`, in the shape a
 * settlement pull stores: 14 payments and one refund whose lines, less the
 * fees, add up to the net payout, matching shillinq's provider payout demo.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Payment\SettlementMapper;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the seeded payment settlement.
 */
#[CoversNothing]
class PaymentSettlementSeedTest extends TestCase {

	private const SEED = __DIR__ . '/../../../lib/Settings/integriq_seed_data.json';

	/**
	 * The seeded settlement `st_example0001`.
	 *
	 * @return array The settlement.
	 */
	private function settlement(): array {
		$seed = json_decode((string)file_get_contents(self::SEED), true, 512, JSON_THROW_ON_ERROR);
		$found = array_values(
			array_filter(
				($seed['payment_settlement'] ?? []),
				static fn (array $row): bool => ($row['settlementReference'] ?? '') === 'st_example0001'
			)
		);
		$this->assertCount(expectedCount: 1, haystack: $found, message: 'The demo data carries the settlement st_example0001 once.');

		return $found[0];
	}//end settlement()

	/**
	 * The settlement has 14 payments and one refund.
	 *
	 * @return void
	 */
	public function testTheDemoSettlementHasFifteenLines(): void {
		$settlement = $this->settlement();

		$this->assertCount(expectedCount: 15, haystack: $settlement['transactions']);
		$types = array_count_values(array_column($settlement['transactions'], 'type'));
		$this->assertSame(expected: ['payment' => 14, 'refund' => 1], actual: $types);
		$this->assertSame(expected: 'EUR', actual: $settlement['currency']);
		$this->assertSame(expected: '2026-10-02', actual: substr($settlement['settledAt'], 0, 10));
	}//end testTheDemoSettlementHasFifteenLines()

	/**
	 * Its lines, less the fees, add up to the net payout of EUR 585.49.
	 *
	 * @return void
	 */
	public function testTheLinesAndFeesAddUpToTheNetPayout(): void {
		$settlement = $this->settlement();
		$money = new SettlementMapper();

		$payments = 0;
		$refunds = 0;
		foreach ($settlement['transactions'] as $line) {
			$cents = $money->toCents(value: $line['amount']);
			if ($line['type'] === 'payment') {
				$payments += $cents;
			} else {
				$refunds -= $cents;
			}
		}

		$this->assertSame(expected: '612.40', actual: $money->toDecimal(cents: $payments));
		$this->assertSame(expected: $settlement['amountGross'], actual: $money->toDecimal(cents: $payments));
		$this->assertSame(expected: '22.85', actual: $money->toDecimal(cents: $refunds));
		$this->assertSame(expected: $settlement['amountRefunds'], actual: $money->toDecimal(cents: $refunds));
		$this->assertSame(expected: '4.06', actual: $settlement['amountFees']);
		$this->assertSame(expected: '585.49', actual: $settlement['amountNet']);
		$this->assertSame(
			expected: $money->toCents(value: $settlement['amountNet']),
			actual: ($payments - $refunds - $money->toCents(value: $settlement['amountFees']))
		);
	}//end testTheLinesAndFeesAddUpToTheNetPayout()

	/**
	 * The seeded row is valid against the register's payment_settlement schema.
	 *
	 * @return void
	 */
	public function testTheSeedMatchesTheRegisterSchema(): void {
		$settlement = $this->settlement();
		unset($settlement['@self']);

		$this->assertSame(expected: [], actual: RegisterSchemaValidator::errors('payment_settlement', $settlement));
	}//end testTheSeedMatchesTheRegisterSchema()
}//end class
