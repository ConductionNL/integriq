<?php

/**
 * Unit tests for EnvironmentService (environments-and-promotion).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenConnector\Service\EnvironmentService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests environment CRUD + sourceRef existence validation.
 */
class EnvironmentServiceTest extends TestCase {
	/**
	 * @var \OCA\OpenRegister\Service\ObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var EnvironmentService
	 */
	private EnvironmentService $service;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->service = new EnvironmentService($this->orObjectService);
	}//end setUp()

	/**
	 * REQ-001 scenario 1 — creating an environment with a valid sourceRef
	 * (an existing Source object) succeeds and no new credential material is
	 * stored on the environment object itself.
	 *
	 * @return void
	 */
	public function testCreateSucceedsWithResolvableSourceRef(): void {
		$sourceEntity = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Acceptance API'], 'source-uuid');
		$this->orObjectService->method('find')->willReturn($sourceEntity);

		$created = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'Acceptance', 'slug' => 'acceptance', 'role' => 'target', 'sourceRef' => 'source-uuid'],
			'env-uuid'
		);
		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->with(
				['name' => 'Acceptance', 'slug' => 'acceptance', 'role' => 'target', 'sourceRef' => 'source-uuid'],
				'openconnector',
				'environment'
			)
			->willReturn($created);

		$result = $this->service->create(['name' => 'Acceptance', 'slug' => 'acceptance', 'role' => 'target', 'sourceRef' => 'source-uuid']);

		$this->assertSame('env-uuid', $result->getUuid());
		$data = $result->getObject();
		$this->assertArrayNotHasKey('apikey', $data);
		$this->assertArrayNotHasKey('credentialRef', $data);
	}//end testCreateSucceedsWithResolvableSourceRef()

	/**
	 * Creating an environment without a sourceRef is rejected before any write.
	 *
	 * @return void
	 */
	public function testCreateRejectsMissingSourceRef(): void {
		$this->orObjectService->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);

		$this->service->create(['name' => 'Acceptance', 'slug' => 'acceptance']);
	}//end testCreateRejectsMissingSourceRef()

	/**
	 * Creating an environment whose sourceRef does not resolve to an
	 * existing Source is rejected before any write.
	 *
	 * @return void
	 */
	public function testCreateRejectsUnresolvableSourceRef(): void {
		$this->orObjectService->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
		$this->orObjectService->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/does not resolve/');

		$this->service->create(['name' => 'Acceptance', 'slug' => 'acceptance', 'sourceRef' => 'missing-uuid']);
	}//end testCreateRejectsUnresolvableSourceRef()

	/**
	 * findBySlug returns null when no environment has the given slug.
	 *
	 * @return void
	 */
	public function testFindBySlugReturnsNullWhenAbsent(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->assertNull($this->service->findBySlug('nonexistent'));
	}//end testFindBySlugReturnsNullWhenAbsent()

	/**
	 * resolveSource returns null (not an exception) when the referenced
	 * Source no longer exists, so callers can surface an actionable error.
	 *
	 * @return void
	 */
	public function testResolveSourceReturnsNullWhenDangling(): void {
		$this->orObjectService->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

		$this->assertNull($this->service->resolveSource('dangling-uuid'));
	}//end testResolveSourceReturnsNullWhenDangling()
}//end class
