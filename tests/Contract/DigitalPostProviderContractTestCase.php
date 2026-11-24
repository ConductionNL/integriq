<?php

/**
 * DigitalPostProviderContractTestCase
 *
 * The rules every digital post provider keeps. An extension app extends this case with its provider and a configuration it accepts.
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
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Contract;

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for a DigitalPostProviderInterface implementation.
 */
abstract class DigitalPostProviderContractTestCase extends TestCase {

	/**
	 * A provider id: lower-case letters, digits and hyphens, starting with a letter.
	 */
	public const PROVIDER_ID_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/';

	/**
	 * The provider under test.
	 *
	 * @return DigitalPostProviderInterface
	 */
	abstract protected function createProvider(): DigitalPostProviderInterface;

	/**
	 * A configuration the provider accepts for activation.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function validConfig(): array;

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
	 * The configuration schema is a JSON-schema-shaped array.
	 *
	 * @return void
	 */
	public function testTheConfigSchemaIsAnArray(): void {
		$this->assertIsArray($this->createProvider()->getConfigSchema());
	}//end testTheConfigSchemaIsAnArray()

	/**
	 * A valid configuration has no activation refusals; each refusal is a sentence.
	 *
	 * @return void
	 */
	public function testAValidConfigurationIsNotRefused(): void {
		$provider = $this->createProvider();
		$this->assertSame([], $provider->activationRefusals($this->validConfig()));
		$this->assertContainsOnly('string', $provider->activationRefusals([]));
	}//end testAValidConfigurationIsNotRefused()

	/**
	 * Polling for inbound post answers a list.
	 *
	 * @return void
	 */
	public function testPollingAnswersAList(): void {
		$this->assertIsList($this->createProvider()->pollInbound($this->validConfig()));
	}//end testPollingAnswersAList()
}//end class
