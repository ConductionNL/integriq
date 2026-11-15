<?php

/**
 * Golden tests for EndpointCacheService::findByPathRegex(): matching, the one
 * refresh-and-retry, ambiguity, and the shape of the endpoint it answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins findByPathRegex() over a fixed endpoint list.
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointCacheFindByPathGoldenTest extends TestCase {

	/**
	 * Info lines logged.
	 *
	 * @var list<string>
	 */
	private array $infos = [];

	/**
	 * How often the cache was refreshed.
	 *
	 * @var int
	 */
	private int $refreshes = 0;

	/**
	 * The service over a list of endpoints, which may change after a refresh.
	 *
	 * @param list<mixed>      $endpoints        What getAllEndpoints() answers first.
	 * @param list<mixed>|null $afterRefresh     What it answers after a refresh, or the same.
	 *
	 * @return EndpointCacheService
	 */
	private function service(array $endpoints, ?array $afterRefresh = null): EndpointCacheService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->infos[] = $message;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('3600');

		$service = $this->getMockBuilder(EndpointCacheService::class)
			->setConstructorArgs([$this->createMock(ICacheFactory::class), $this->createMock(ORObjectService::class), $logger, $appConfig])
			->onlyMethods(['getAllEndpoints', 'refreshCache'])
			->getMock();
		$service->method('getAllEndpoints')->willReturnCallback(
			function () use ($endpoints, $afterRefresh): array {
				if ($this->refreshes > 0 && $afterRefresh !== null) {
					return $afterRefresh;
				}

				return $endpoints;
			}
		);
		$service->method('refreshCache')->willReturnCallback(
			function (): void {
				$this->refreshes++;
			}
		);

		return $service;

	}//end service()

	/**
	 * An endpoint stored as an entity.
	 *
	 * @param array<string,mixed> $data The endpoint object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * A matching entity is answered with the routing derived in memory.
	 *
	 * @return void
	 */
	public function testMatchingEntityIsAnsweredWithItsRouting(): void {
		$match = $this->entity(['name' => 'zaken', 'endpoint' => 'zaken/{{id}}', 'method' => 'GET']);
		$service = $this->service(
			[
				$this->entity(['name' => 'post', 'endpoint' => 'zaken/{{id}}', 'method' => 'POST']),
				$this->entity(['name' => 'blank', 'method' => 'GET']),
				'not an endpoint',
				$match,
			]
		);

		$found = $service->findByPathRegex('zaken/42', 'GET');

		$this->assertSame($match, $found);
		$data = $found->getObject();
		$this->assertSame(['name', 'endpoint', 'method', 'endpointRegex', 'endpointArray'], array_keys($data));
		$this->assertSame(1, preg_match($data['endpointRegex'], 'zaken/42'));
		$this->assertSame(0, $this->refreshes);
		$this->assertSame([], $this->infos);

	}//end testMatchingEntityIsAnsweredWithItsRouting()

	/**
	 * A matching cached array is rebuilt as an entity without its @self block.
	 *
	 * @return void
	 */
	public function testMatchingArrayIsRebuiltAsAnEntity(): void {
		$service = $this->service(
			[['@self' => ['id' => 9], 'name' => 'arr', 'endpointRegex' => '#^/a$#', 'method' => 'PUT', 'endpoint' => '/a']]
		);

		$found = $service->findByPathRegex('/a', 'PUT');

		$this->assertInstanceOf(ObjectEntity::class, $found);
		$this->assertSame('arr', $found->getObject()['name']);
		$this->assertArrayNotHasKey('@self', $found->getObject());
		$this->assertSame('#^/a$#', $found->getObject()['endpointRegex']);

	}//end testMatchingArrayIsRebuiltAsAnEntity()

	/**
	 * No match refreshes once, logs once, and answers what the retry finds.
	 *
	 * @return void
	 */
	public function testNoMatchRefreshesOnceThenRetries(): void {
		$late = $this->entity(['name' => 'late', 'endpointRegex' => '#^/late$#', 'method' => 'GET']);
		$service = $this->service([], [$late]);

		$this->assertSame($late, $service->findByPathRegex('/late', 'GET'));
		$this->assertSame(1, $this->refreshes);
		$this->assertSame(['No endpoint matches found for GET /late, refreshing cache and retrying'], $this->infos);

	}//end testNoMatchRefreshesOnceThenRetries()

	/**
	 * Still no match after the refresh answers null, after one refresh only.
	 *
	 * @return void
	 */
	public function testNoMatchAfterTheRefreshIsNull(): void {
		$service = $this->service([$this->entity(['endpointRegex' => '#^/x$#', 'method' => 'GET'])]);

		$this->assertNull($service->findByPathRegex('/y', 'GET'));
		$this->assertSame(1, $this->refreshes);

		$this->assertNull($service->findByPathRegex('/y', 'GET', true));
		$this->assertSame(1, $this->refreshes);

	}//end testNoMatchAfterTheRefreshIsNull()

	/**
	 * Two matches are refused with every name, `unnamed` for a nameless one.
	 *
	 * @return void
	 */
	public function testTwoMatchesAreAmbiguous(): void {
		$service = $this->service(
			[
				$this->entity(['name' => 'one', 'endpointRegex' => '#^/d$#', 'method' => 'DELETE']),
				['endpointRegex' => '#^/d$#', 'method' => 'DELETE'],
			]
		);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Multiple endpoints found for path and method: /d DELETE. Matching endpoints: one, unnamed');
		$service->findByPathRegex('/d', 'DELETE');

	}//end testTwoMatchesAreAmbiguous()
}//end class
