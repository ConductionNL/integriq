<?php

/**
 * Integriq ProductChannelsControllerThrottleTest.
 *
 * Every public route of the product channels controller carries an anonymous
 * volume ceiling (ADR-082).
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ProductChannelsController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The public channel routes are throttled for anonymous callers.
 *
 * @spec openspec/changes/archive/2026-11-20-events-async-api-products/specs/api-product-gateway/spec.md#requirement-a-consumer-reaches-a-channel-under-the-products-policies-req-aapi-002
 */
#[CoversNothing]
class ProductChannelsControllerThrottleTest extends TestCase {

	/**
	 * The ceiling each public route carries, per minute.
	 *
	 * @var array<string, int>
	 */
	private const EXPECTED = [
		'asyncapi' => 120,
		'pull' => 600,
		'subscribe' => 30,
		'unsubscribe' => 30,
	];

	/**
	 * Every public page method has an AnonRateLimit with the expected ceiling.
	 *
	 * @return void
	 */
	public function testEveryPublicRouteHasAnAnonymousCeiling(): void {
		$found = [];
		$class = new ReflectionClass(ProductChannelsController::class);
		foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getAttributes(PublicPage::class) === []) {
				continue;
			}

			$limits = $method->getAttributes(AnonRateLimit::class);
			$this->assertCount(expectedCount: 1, haystack: $limits, message: $method->getName() . ' is public and needs one AnonRateLimit.');
			$arguments = $limits[0]->getArguments();
			$found[$method->getName()] = [(int)$arguments['limit'], (int)$arguments['period']];
		}

		ksort($found);
		$expected = [];
		foreach (self::EXPECTED as $name => $limit) {
			$expected[$name] = [$limit, 60];
		}

		ksort($expected);
		$this->assertSame(expected: $expected, actual: $found);
	}//end testEveryPublicRouteHasAnAnonymousCeiling()
}//end class
