<?php

/**
 * PaymentProviderContractTestCase
 *
 * The rules every payment provider binding keeps. An extension app extends this case with its provider, a source configuration and a create-payment envelope.
 *
 * @category  Tests
 * @package   OCA\Integriq\Tests\Contract
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Contract;

use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for a PaymentProviderInterface implementation.
 */
abstract class PaymentProviderContractTestCase extends TestCase {

	/**
	 * A provider id: lower-case letters, digits and hyphens, starting with a letter.
	 */
	public const PROVIDER_ID_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/';

	/**
	 * The provider under test.
	 *
	 * @return PaymentProviderInterface
	 */
	abstract protected function createProvider(): PaymentProviderInterface;

	/**
	 * A payment source's `configuration` the provider accepts.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function sourceConfiguration(): array;

	/**
	 * A create-payment envelope. Override for a provider that needs more.
	 *
	 * @return array<string,mixed>
	 */
	protected function paymentPayload(): array {
		return [
			'amount' => ['value' => '12.50', 'currency' => 'EUR'],
			'description' => 'Leges paspoort',
			'redirectUrl' => 'https://gemeente.example/betaald',
			'webhookUrl' => 'https://gemeente.example/webhook',
		];
	}//end paymentPayload()

	/**
	 * The provider id is a stable slug.
	 *
	 * @return void
	 */
	public function testTheProviderIdIsAStableSlug(): void {
		$provider = $this->createProvider();
		$this->assertMatchesRegularExpression(self::PROVIDER_ID_PATTERN, $provider->getProviderId());
		$this->assertSame($provider->getProviderId(), $provider->getProviderId());
	}//end testTheProviderIdIsAStableSlug()

	/**
	 * A created payment names its provider id, a status and where to pay.
	 *
	 * @return void
	 */
	public function testACreatedPaymentCanBePaidAndLookedUp(): void {
		$provider = $this->createProvider();
		$created = $provider->createPayment($this->sourceConfiguration(), $this->paymentPayload());
		$this->assertIsString($created['providerPaymentId'] ?? null);
		$this->assertNotSame('', $created['providerPaymentId']);
		$this->assertIsString($created['paymentStatus'] ?? null);
		$this->assertIsString($created['checkoutUrl'] ?? null);

		$status = $provider->fetchPaymentStatus($this->sourceConfiguration(), $created['providerPaymentId']);
		$this->assertSame($created['providerPaymentId'], $status['providerPaymentId'] ?? null);
		$this->assertIsString($status['paymentStatus'] ?? null);
	}//end testACreatedPaymentCanBePaidAndLookedUp()

	/**
	 * Settlements and terminals are lists, empty when the provider has none.
	 *
	 * @return void
	 */
	public function testSettlementsAndTerminalsAreLists(): void {
		$provider = $this->createProvider();
		$this->assertIsList($provider->fetchSettlements($this->sourceConfiguration(), '2026-01-01T00:00:00+00:00'));
		$this->assertIsList($provider->listTerminals($this->sourceConfiguration()));
	}//end testSettlementsAndTerminalsAreLists()
}//end class
