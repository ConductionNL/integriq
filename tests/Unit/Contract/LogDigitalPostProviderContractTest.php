<?php

/**
 * LogDigitalPostProviderContractTest
 *
 * Runs the digital post provider contract test case on the built-in log provider.
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
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Contract;

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\LogDigitalPostProvider;
use OCA\Integriq\Tests\Contract\DigitalPostProviderContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * The log digital post provider keeps the digital post provider contract.
 */
#[CoversClass(LogDigitalPostProvider::class)]
final class LogDigitalPostProviderContractTest extends DigitalPostProviderContractTestCase {

	/**
	 * The provider under test.
	 *
	 * @return DigitalPostProviderInterface
	 */
	protected function createProvider(): DigitalPostProviderInterface {
		return new LogDigitalPostProvider(logger: new NullLogger());
	}//end createProvider()

	/**
	 * The log provider needs no configuration.
	 *
	 * @return array<string,mixed>
	 */
	protected function validConfig(): array {
		return [];
	}//end validConfig()
}//end class
