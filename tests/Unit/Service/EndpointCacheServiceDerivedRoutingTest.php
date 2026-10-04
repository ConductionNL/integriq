<?php

/**
 * An endpoint saved without a regex still routes (live defect I1).
 *
 * The Endpoints page saves an endpoint through OpenRegister's generic object
 * API with only `endpoint` and `method`: `endpointRegex` and `endpointArray`
 * stay empty, and the router skipped every such endpoint, so a request to it
 * answered 404. Driven through the real EndpointCacheService::findByPathRegex()
 * over what OpenRegister returns for such a row.
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
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointCacheServiceDerivedRoutingTest extends TestCase {

	/**
	 * The cache service over endpoints as OpenRegister stores them.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored endpoint objects.
	 *
	 * @return EndpointCacheService
	 */
	private function service(array $rows): EndpointCacheService {
		$entities = [];
		foreach ($rows as $index => $row) {
			$entity = new ObjectEntity();
			$entity->setUuid('endpoint-' . $index);
			$entity->setObject($row);
			$entities[] = $entity;
		}

		$or = $this->createMock(ORObjectService::class);
		$or->method('findAll')->willReturn(['results' => $entities]);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('3600');

		return new EndpointCacheService($factory, $or, new NullLogger(), $appConfig);
	}//end service()

	/**
	 * An endpoint made on the Endpoints page, with no regex, answers its path.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutARegexIsFoundByItsPath(): void {
		$service = $this->service(
			[
				['name' => 'livepass person', 'endpoint' => 'livepass-lane9/person', 'method' => 'POST', 'endpointRegex' => '', 'endpointArray' => []],
				['name' => 'other', 'endpoint' => 'livepass-lane9/other', 'method' => 'POST'],
			]
		);

		$endpoint = $service->findByPathRegex(path: 'livepass-lane9/person', method: 'POST');

		$this->assertNotNull($endpoint);
		$this->assertSame('livepass person', $endpoint->getObject()['name']);
		$this->assertSame(['livepass-lane9', 'person'], $endpoint->getObject()['endpointArray']);
		$this->assertNull($service->findByPathRegex(path: 'livepass-lane9/person', method: 'GET'));
	}//end testAnEndpointWithoutARegexIsFoundByItsPath()

	/**
	 * A path parameter in an endpoint without a regex matches, and the segments name it.
	 *
	 * @return void
	 */
	public function testAPathParameterMatchesWithoutAStoredRegex(): void {
		$service = $this->service([['name' => 'persoon', 'endpoint' => 'personen/{{id}}', 'method' => 'GET']]);

		$endpoint = $service->findByPathRegex(path: 'personen/123456782', method: 'GET');

		$this->assertNotNull($endpoint);
		$this->assertSame(['personen', '{{id}}'], $endpoint->getObject()['endpointArray']);
		$this->assertNull($service->findByPathRegex(path: 'personen/1/extra', method: 'GET'));
	}//end testAPathParameterMatchesWithoutAStoredRegex()

	/**
	 * A stored regex still wins: an administrator's own pattern is not replaced at runtime.
	 *
	 * @return void
	 */
	public function testAStoredRegexIsUsedAsItIs(): void {
		$service = $this->service([['name' => 'any', 'endpoint' => 'x', 'method' => 'GET', 'endpointRegex' => '#^anything/.*$#', 'endpointArray' => ['anything']]]);

		$this->assertNotNull($service->findByPathRegex(path: 'anything/goes', method: 'GET'));
	}//end testAStoredRegexIsUsedAsItIs()
}//end class
