<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Service\Helper;

use OCA\OpenConnector\Service\Helper\SyncRefResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Cover all four variants produced by SyncRefResolver::resolve(), per the
 * services-direct-or-usage Task 3 acceptance criteria.
 */
final class SyncRefResolverTest extends TestCase {

	public function testIntegerPkResolvesViaObjectServiceLookup(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService
			->method('findAll')
			->with(
				config: [
					'filters' => [
						'register' => 'openconnector',
						'schema' => 'source',
						'id' => 42,
					],
				]
			)
			->willReturn(['results' => [['uuid' => '00000000-0000-0000-0000-000000000042']]]);

		$resolver = new SyncRefResolver($objectService, $this->createMock(LoggerInterface::class));

		$result = $resolver->resolve('42');

		$this->assertSame('integer-pk', $result['variant']);
		$this->assertSame('00000000-0000-0000-0000-000000000042', $result['value']);
	}

	public function testIntegerPkWithNoLookupResultPreservesRawValue(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$resolver = new SyncRefResolver($objectService, $this->createMock(LoggerInterface::class));

		$result = $resolver->resolve('99999');

		$this->assertSame('integer-pk', $result['variant']);
		$this->assertSame('99999', $result['value']);
	}

	public function testRegisterSchemaSlugPair(): void {
		$resolver = $this->makeResolver();

		$result = $resolver->resolve('openconnector/source');

		$this->assertSame('register-schema', $result['variant']);
		$this->assertSame('openconnector/source', $result['value']);
	}

	public function testUuidIsPassedThroughUnchanged(): void {
		$resolver = $this->makeResolver();

		$result = $resolver->resolve('00000000-0000-0000-0000-000000000000');

		$this->assertSame('uuid', $result['variant']);
		$this->assertSame('00000000-0000-0000-0000-000000000000', $result['value']);
	}

	public function testEmptyStringIsUnrecognisedWithoutThrowing(): void {
		$resolver = $this->makeResolver();

		$result = $resolver->resolve('');

		$this->assertSame('unrecognised', $result['variant']);
		$this->assertSame('', $result['value']);
	}

	public function testUnknownShapeIsUnrecognisedAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$objectService = $this->createMock(ObjectService::class);
		$resolver = new SyncRefResolver($objectService, $logger);

		$result = $resolver->resolve('not-a-recognised-format');

		$this->assertSame('unrecognised', $result['variant']);
		$this->assertSame('not-a-recognised-format', $result['value']);
	}

	public function testIntegerPkObjectServiceFailurePreservesRawValue(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService
			->method('findAll')
			->willThrowException(new \RuntimeException('OR unavailable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$resolver = new SyncRefResolver($objectService, $logger);

		$result = $resolver->resolve('7');

		$this->assertSame('integer-pk', $result['variant']);
		$this->assertSame('7', $result['value']);
	}

	private function makeResolver(): SyncRefResolver {
		return new SyncRefResolver(
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

}
