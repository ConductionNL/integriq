<?php

/**
 * Unit tests for the Edurep status store.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepStatusStore;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Success, failure and health are kept in the distributed cache.
 */
#[CoversClass(EdurepStatusStore::class)]
class EdurepStatusStoreTest extends TestCase {
	/**
	 * @param ICache $cache The cache the factory hands out.
	 *
	 * @return EdurepStatusStore
	 */
	private function store(ICache $cache): EdurepStatusStore {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')->with('integriq.edurep')->willReturn($cache);

		return new EdurepStatusStore(cacheFactory: $factory);
	}//end store()

	/**
	 * @return void
	 */
	public function testNothingIsKnownBeforeAnything(): void {
		$store = $this->store(cache: new ArrayCache());
		$this->assertNull($store->lastSuccess());
		$this->assertNull($store->lastFailure());
		$this->assertNull($store->health());
	}//end testNothingIsKnownBeforeAnything()

	/**
	 * @return void
	 */
	public function testSuccessAndFailureAreRemembered(): void {
		$store = $this->store(cache: new ArrayCache());
		$store->markSuccess();
		$store->markFailure(reason: 'HTTP 503');

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string)$store->lastSuccess());
		$failure = $store->lastFailure();
		$this->assertSame('HTTP 503', $failure['reason'] ?? null);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)($failure['at'] ?? ''));
	}//end testSuccessAndFailureAreRemembered()

	/**
	 * @return void
	 */
	public function testStatusIsKeptForAWeekAndHealthForFiveMinutes(): void {
		$cache = $this->createMock(ICache::class);
		$ttls = [];
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl) use (&$ttls): bool {
			$ttls[$key] = $ttl;
			return true;
		});
		$store = $this->store(cache: $cache);
		$store->markSuccess();
		$store->markFailure(reason: 'x');
		$store->rememberHealth(health: ['ok' => true]);

		$this->assertSame(['lastSuccess' => 604800, 'lastFailure' => 604800, 'health' => 300], $ttls);
	}//end testStatusIsKeptForAWeekAndHealthForFiveMinutes()

	/**
	 * @return void
	 */
	public function testHealthIsReturnedAsStored(): void {
		$store = $this->store(cache: new ArrayCache());
		$store->rememberHealth(health: ['status' => 'ok', 'checkedAt' => 'now']);
		$this->assertSame(['status' => 'ok', 'checkedAt' => 'now'], $store->health());
	}//end testHealthIsReturnedAsStored()

	/**
	 * @return void
	 */
	public function testValuesOfTheWrongShapeReadAsUnknown(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnMap([['lastSuccess', ['not', 'a', 'string']], ['lastFailure', 'nope'], ['health', 'nope']]);
		$store = $this->store(cache: $cache);

		$this->assertNull($store->lastSuccess());
		$this->assertNull($store->lastFailure());
		$this->assertNull($store->health());
	}//end testValuesOfTheWrongShapeReadAsUnknown()

	/**
	 * @return void
	 */
	public function testAFailureMissingFieldsReadsThemAsEmpty(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnMap([['lastFailure', ['reason' => 42]]]);
		$this->assertSame(['at' => '', 'reason' => '42'], $this->store(cache: $cache)->lastFailure());
	}//end testAFailureMissingFieldsReadsThemAsEmpty()
}//end class
