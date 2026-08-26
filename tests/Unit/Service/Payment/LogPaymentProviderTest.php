<?php

/**
 * Unit tests for LogPaymentProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/live-payment-providers/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Payment;

use OCA\Integriq\Service\Payment\LogPaymentProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox/mock payment provider.
 *
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */
class LogPaymentProviderTest extends TestCase {

	/**
	 * @var LogPaymentProvider
	 */
	private LogPaymentProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogPaymentProvider();

	}//end setUp()

	/**
	 * createPayment returns a synthetic MOCK-PAY-<n> id and a checkout URL with no network call.
	 *
	 * @return void
	 */
	public function testCreatePaymentReturnsIncrementingMockIdAndCheckoutUrl(): void {
		$payload = [
			'amount' => ['value' => '10.00', 'currency' => 'EUR'],
			'description' => 'Invoice INV-1',
			'redirectUrl' => 'https://example.com/return',
			'webhookUrl' => 'https://example.com/webhook',
			'method' => 'ideal',
		];

		$first = $this->provider->createPayment(sourceConfiguration: [], payload: $payload);
		$second = $this->provider->createPayment(sourceConfiguration: [], payload: $payload);

		$this->assertMatchesRegularExpression('/^MOCK-PAY-\d+$/', $first['providerPaymentId']);
		$this->assertNotSame($first['providerPaymentId'], $second['providerPaymentId']);
		$this->assertSame('open', $first['paymentStatus']);
		$this->assertStringContainsString($first['providerPaymentId'], $first['checkoutUrl']);
		$this->assertSame('ideal', $first['extras']['method']);

	}//end testCreatePaymentReturnsIncrementingMockIdAndCheckoutUrl()

	/**
	 * createPayment defaults method to "ideal" when the payload omits it.
	 *
	 * @return void
	 */
	public function testCreatePaymentDefaultsMethodToIdeal(): void {
		$result = $this->provider->createPayment(
			sourceConfiguration: [],
			payload: [
				'amount' => ['value' => '5.00', 'currency' => 'EUR'],
				'description' => 'Invoice INV-2',
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);

		$this->assertSame('ideal', $result['extras']['method']);

	}//end testCreatePaymentDefaultsMethodToIdeal()

	/**
	 * fetchPaymentStatus returns the seeded status from configuration.mockStatuses with no upstream call.
	 *
	 * @return void
	 */
	public function testFetchPaymentStatusReturnsSeededStatus(): void {
		$result = $this->provider->fetchPaymentStatus(
			sourceConfiguration: ['mockStatuses' => ['MOCK-PAY-1' => 'paid']],
			providerPaymentId: 'MOCK-PAY-1'
		);

		$this->assertSame('MOCK-PAY-1', $result['providerPaymentId']);
		$this->assertSame('paid', $result['paymentStatus']);

	}//end testFetchPaymentStatusReturnsSeededStatus()

	/**
	 * fetchPaymentStatus defaults to "open" when no mock status is seeded for the id.
	 *
	 * @return void
	 */
	public function testFetchPaymentStatusDefaultsToOpenWhenUnseeded(): void {
		$result = $this->provider->fetchPaymentStatus(sourceConfiguration: [], providerPaymentId: 'MOCK-PAY-99');

		$this->assertSame('open', $result['paymentStatus']);

	}//end testFetchPaymentStatusDefaultsToOpenWhenUnseeded()
}//end class
