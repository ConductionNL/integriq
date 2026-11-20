<?php

/**
 * LogPaymentProviderContractTest
 *
 * Runs the payment provider contract test case on the built-in log binding.
 *
 * @category  Tests
 * @package   OCA\Integriq\Tests\Unit\Contract
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

namespace OCA\Integriq\Tests\Unit\Contract;

use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Tests\Contract\PaymentProviderContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The log payment provider keeps the payment provider contract.
 */
#[CoversClass(LogPaymentProvider::class)]
final class LogPaymentProviderContractTest extends PaymentProviderContractTestCase {

	/**
	 * The provider under test.
	 *
	 * @return PaymentProviderInterface
	 */
	protected function createProvider(): PaymentProviderInterface {
		return new LogPaymentProvider();
	}//end createProvider()

	/**
	 * A sandbox source configuration.
	 *
	 * @return array<string,mixed>
	 */
	protected function sourceConfiguration(): array {
		return ['provider' => 'log'];
	}//end sourceConfiguration()
}//end class
