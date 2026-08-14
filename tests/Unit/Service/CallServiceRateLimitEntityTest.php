<?php

/**
 * `sourceRateLimit()` keeps the entity it mutates in step with the database.
 *
 * The counter is decremented and PERSISTED, but the in-memory object it was
 * handed used to keep the old value. Nothing noticed, because the fetch loop
 * threw the object away and re-read the row on every call — one database read
 * per page, whether the source was rate-limited or not.
 *
 * That re-read is now cached, which turns the staleness from invisible into
 * dangerous: a cached source whose `rateLimitRemaining` never moves is a rate
 * limiter that never trips. This pins the invariant the cache depends on.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class CallServiceRateLimitEntityTest extends TestCase {
	private CallService $service;
	private ReflectionMethod $sourceRateLimit;

	protected function setUp(): void {
		$this->service = (new ReflectionClass(CallService::class))->newInstanceWithoutConstructor();

		// The only collaborator this path touches is the object service, and only
		// to persist. Its return value is unused here.
		$objectService = new ReflectionProperty(CallService::class, 'objectService');
		$objectService->setAccessible(true);
		$objectService->setValue(
			$this->service,
			$this->createMock(\OCA\OpenRegister\Service\ObjectService::class)
		);

		$this->sourceRateLimit = new ReflectionMethod(CallService::class, 'sourceRateLimit');
		$this->sourceRateLimit->setAccessible(true);
	}

	/**
	 * @param array $sourceData The source body.
	 * @param array $headers Response headers from the upstream.
	 *
	 * @return ObjectEntity The entity after the call.
	 */
	private function afterCall(array $sourceData, array $headers = []): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('source-uuid');
		$source->setObject($sourceData);

		$this->sourceRateLimit->invoke($this->service, $source, $sourceData, $headers);

		return $source;
	}

	/**
	 * THE invariant the source cache rests on. A configured rate limit is
	 * decremented, and the decrement must be visible on the object the caller
	 * still holds — not only in the row that was written.
	 */
	public function testTheDecrementIsVisibleOnTheEntity(): void {
		$source = $this->afterCall(
			['rateLimitLimit' => 10, 'rateLimitRemaining' => 10, 'rateLimitWindow' => 60]
		);

		$this->assertSame(
			9,
			($source->getObject()['rateLimitRemaining'] ?? null),
			'A cached source whose counter never moves is a rate limiter that never trips.'
		);
	}

	/**
	 * ...and it keeps decrementing across calls on the SAME object, which is
	 * exactly what a cached entity does over a paginated fetch. One page is not
	 * the case that breaks; the ninth is.
	 */
	public function testItKeepsDecrementingAcrossCallsOnOneEntity(): void {
		$source = new ObjectEntity();
		$source->setUuid('source-uuid');
		$source->setObject(['rateLimitLimit' => 10, 'rateLimitRemaining' => 10, 'rateLimitWindow' => 60]);

		for ($call = 0; $call < 3; $call++) {
			$this->sourceRateLimit->invoke($this->service, $source, $source->getObject(), []);
		}

		$this->assertSame(7, ($source->getObject()['rateLimitRemaining'] ?? null));
	}

	/**
	 * A source with no rate-limit configuration is left alone entirely — nothing
	 * is written and nothing is invented, so caching it is free.
	 */
	public function testASourceWithoutRateLimitingIsUntouched(): void {
		$source = $this->afterCall(['location' => 'https://example.test']);

		$this->assertArrayNotHasKey('rateLimitRemaining', $source->getObject());
	}
}
