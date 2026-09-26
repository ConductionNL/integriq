<?php

/**
 * Contract tests for the seeded iDEAL ouderbijdrage payment-source template.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/psp-source-template/spec.md#requirement-the-seeded-configuration-produces-a-working-mock-ideal-payment-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Payment\LogPaymentProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the `ideal-ouderbijdrage-source.json` seed fragment is genuinely
 * wired to the existing payment stack — not a decorative JSON file. Loads
 * the fragment exactly as `CatalogRegistryService::collectFromSeedFragments()`
 * would, then exercises its `configuration` object against the real,
 * unmodified `LogPaymentProvider`.
 */
class IdealOuderbijdrageSourceTemplateTest extends TestCase {
	/**
	 * Path to the seed fragment under test.
	 *
	 * @var string
	 */
	private const FRAGMENT_PATH = __DIR__ . '/../../../lib/Settings/register.d/ideal-ouderbijdrage-source.json';

	/**
	 * @return array<string,mixed>
	 */
	private function loadSeededObject(): array {
		$raw = file_get_contents(self::FRAGMENT_PATH);
		$this->assertIsString($raw);

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded);

		$objects = $decoded['components']['objects'];
		$this->assertIsArray($objects);
		$this->assertCount(1, $objects);

		return $objects[0];
	}//end loadSeededObject()

	/**
	 * @return void
	 */
	public function testFragmentIsValidJsonWithASourceSelfBlock(): void {
		$object = $this->loadSeededObject();

		$this->assertSame('source', $object['@self']['schema']);
		$this->assertSame('ideal-ouderbijdrage', $object['@self']['slug']);
		$this->assertSame('payment', $object['type']);
	}//end testFragmentIsValidJsonWithASourceSelfBlock()

	/**
	 * @return void
	 */
	public function testFragmentIsDormantByDefault(): void {
		$object = $this->loadSeededObject();

		$this->assertFalse($object['isEnabled']);
		$this->assertSame('log', $object['configuration']['provider']);
	}//end testFragmentIsDormantByDefault()

	/**
	 * @return void
	 */
	public function testSeededConfigurationProducesAWorkingIdealMockPayment(): void {
		$object = $this->loadSeededObject();
		$configuration = $object['configuration'];

		$provider = new LogPaymentProvider();
		$result = $provider->createPayment(
			sourceConfiguration: $configuration,
			payload: [
				'amount' => ['value' => '25.00', 'currency' => 'EUR'],
				'description' => 'Schoolreisje groep 6',
			]
		);

		$this->assertSame('ideal', $result['extras']['method']);
		$this->assertSame('open', $result['paymentStatus']);
		$this->assertStringStartsWith('MOCK-PAY-', $result['providerPaymentId']);
		$this->assertStringStartsWith('https://sandbox.payment.example/checkout/', $result['checkoutUrl']);
	}//end testSeededConfigurationProducesAWorkingIdealMockPayment()

	/**
	 * @return void
	 */
	public function testSeededConfigurationRoundTripsThroughFetchPaymentStatus(): void {
		$object = $this->loadSeededObject();
		$configuration = $object['configuration'];

		$provider = new LogPaymentProvider();
		$created = $provider->createPayment(
			sourceConfiguration: $configuration,
			payload: ['amount' => ['value' => '10.00', 'currency' => 'EUR'], 'description' => 'Overblijfkosten']
		);

		$status = $provider->fetchPaymentStatus(
			sourceConfiguration: $configuration,
			providerPaymentId: $created['providerPaymentId']
		);

		// No status seeded in configuration.mockStatuses -> default 'open'.
		$this->assertSame('open', $status['paymentStatus']);
		$this->assertSame($created['providerPaymentId'], $status['providerPaymentId']);
	}//end testSeededConfigurationRoundTripsThroughFetchPaymentStatus()
}//end class
