<?php

/**
 * The demo payments and the imported payment_intent schema.
 *
 * The payments page is reviewed against three demo payments: a paid, an open
 * and an expired one. The imported register (the base descriptor with every
 * register.d fragment merged in) must keep payment_intent closed to everyone
 * but administrators and the owner of a payment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-is-readable-only-by-administrators-and-its-owner-req-prv-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Demo payments and the lockdown of the imported payment_intent schema.
 */
class PaymentsReviewDemoTest extends TestCase {

	/**
	 * The demo payment_intent objects of the mock register.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function demoPayments(): array {
		$path     = __DIR__ . '/../../../lib/Settings/integriq_mock_register.json';
		$mock     = json_decode(json: (string)file_get_contents(filename: $path), associative: true);
		$payments = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? null) === 'payment_intent') {
				$payments[] = $object;
			}
		}

		return $payments;
	}//end demoPayments()

	/**
	 * A paid, an open and an expired payment are seeded.
	 *
	 * @return void
	 */
	public function testTheDemoSeedsAPaidAnOpenAndAnExpiredPayment(): void {
		$statuses = array_map(callback: static fn(array $payment): string => $payment['paymentStatus'], array: $this->demoPayments());
		sort(array: $statuses);

		$this->assertSame(expected: ['expired', 'open', 'paid'], actual: $statuses);
	}//end testTheDemoSeedsAPaidAnOpenAndAnExpiredPayment()

	/**
	 * Only the paid payment carries a captured outcome; the open one has none yet.
	 *
	 * @return void
	 */
	public function testOutcomesFollowTheStatus(): void {
		foreach ($this->demoPayments() as $payment) {
			$outcome = $payment['lastOutcome'] ?? null;
			match ($payment['paymentStatus']) {
				'paid' => $this->assertSame(expected: 'captured', actual: $outcome),
				'open' => $this->assertNull(actual: $outcome, message: 'An open payment has no outcome yet.'),
				default => $this->assertNotSame(expected: 'captured', actual: $outcome, message: 'Only a paid payment is captured.'),
			};
		}
	}//end testOutcomesFollowTheStatus()

	/**
	 * Every demo payment is a valid object of the imported schema.
	 *
	 * @return void
	 */
	public function testEveryDemoPaymentMatchesTheImportedSchema(): void {
		$payments = $this->demoPayments();
		$this->assertNotEmpty(actual: $payments);
		foreach ($payments as $payment) {
			$slug = $payment['@self']['slug'];
			unset($payment['@self']);
			$this->assertSame(expected: [], actual: RegisterSchemaValidator::errors(schemaSlug: 'payment_intent', object: $payment), message: $slug);
		}
	}//end testEveryDemoPaymentMatchesTheImportedSchema()

	/**
	 * The imported schema grants nothing to a non-admin: four empty rule lists.
	 *
	 * @return void
	 */
	public function testTheImportedSchemaDeniesEveryoneButAdminsAndOwners(): void {
		$schema = RegisterSchemaValidator::descriptor()['components']['schemas']['payment_intent'];

		$this->assertSame(
			expected: ['create' => [], 'read' => [], 'update' => [], 'delete' => []],
			actual: $schema['authorization'] ?? null,
			message: 'A non-empty block of empty rule lists denies to everyone except the admin group and the owner.'
		);
	}//end testTheImportedSchemaDeniesEveryoneButAdminsAndOwners()
}//end class
