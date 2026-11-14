<?php

/**
 * Unit tests for the events-async-api-products register fragment: after every
 * fragment is merged the way the repair step merges them, api_product carries
 * `channels`, event_subscription names the product subscription and channel,
 * the existing properties survive, and the seeded woo-publications 2.0.0 has
 * the `publications` channel while 1.0.0 has none.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The channels fragment, merged.
 *
 * @coversNothing
 */
class EventsAsyncApiProductsFragmentTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The register with every fragment merged, in the repair step's order.
	 *
	 * @return array<string,mixed> The merged register.
	 */
	private static function merged(): array {
		$register = json_decode((string)file_get_contents(self::SETTINGS . '/integriq_register.json'), true);
		$files = glob(self::SETTINGS . '/register.d/*.json');
		sort($files);
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$register = $merge->invoke(null, $register, json_decode((string)file_get_contents($file), true));
		}

		return $register;
	}//end merged()

	/**
	 * api_product gains channels with slug, title and at least one type required; its existing properties remain.
	 *
	 * @return void
	 */
	public function testAnApiProductCarriesChannels(): void {
		$product = self::merged()['components']['schemas']['api_product'];
		$channels = $product['properties']['channels'];

		$this->assertSame('array', $channels['type']);
		$this->assertSame(['slug', 'title', 'types'], $channels['items']['required']);
		$this->assertSame(1, $channels['items']['properties']['types']['minItems']);
		$this->assertSame('^[a-z0-9][a-z0-9-]*$', $channels['items']['properties']['slug']['pattern']);
		$this->assertArrayHasKey('endpoints', $product['properties']);
		$this->assertArrayHasKey('tiers', $product['properties']);
	}//end testAnApiProductCarriesChannels()

	/**
	 * event_subscription names the product subscription and channel; its secret stays write-only.
	 *
	 * @return void
	 */
	public function testAnEventSubscriptionNamesItsProductSubscription(): void {
		$properties = self::merged()['components']['schemas']['event_subscription']['properties'];

		$this->assertSame('string', $properties['productSubscription']['type']);
		$this->assertSame('string', $properties['productChannel']['type']);
		$this->assertTrue($properties['protocolSettings']['writeOnly']);
	}//end testAnEventSubscriptionNamesItsProductSubscription()

	/**
	 * The seeded woo-publications 2.0.0 carries the publications channel; 1.0.0 carries none.
	 *
	 * @return void
	 */
	public function testTheSeededProductHasThePublicationsChannel(): void {
		$seed = [];
		foreach (self::merged()['components']['schemas']['api_product']['x-openregister-seed'] as $row) {
			$seed[$row['@self']['slug']] = $row;
		}

		$this->assertSame(
			[['slug' => 'publications', 'title' => 'Publications', 'types' => ['nl.woo.publication.created', 'nl.woo.publication.updated'], 'description' => 'A WOO publication was created or updated']],
			$seed['api-product-woo-publications-v2']['channels']
		);
		$this->assertArrayNotHasKey('channels', $seed['api-product-woo-publications-v1']);
	}//end testTheSeededProductHasThePublicationsChannel()
}//end class
