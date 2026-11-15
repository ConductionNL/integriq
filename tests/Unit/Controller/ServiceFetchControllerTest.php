<?php

/**
 * Unit tests for ServiceFetchController — the designer's list of fetches.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ServiceFetchController;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchCatalogue;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SF-003.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
 */
#[CoversClass(ServiceFetchController::class)]
#[UsesClass(ServiceFetchCatalogue::class)]
class ServiceFetchControllerTest extends TestCase {

	/**
	 * The controller over a register holding these fetches, or failing.
	 *
	 * @param list<array<string, mixed>>|null $fetches The fetches, or null for a failing read.
	 *
	 * @return ServiceFetchController
	 */
	private function controller(?array $fetches): ServiceFetchController {
		$objects = $this->createMock(OrObjectService::class);
		if ($fetches === null) {
			$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		} else {
			$objects->method('findAll')->willReturn(
				['results' => array_map(fn (array $fetch) => ObjectServiceMockBuilder::objectEntity($this, $fetch, $fetch['slug']), $fetches)]
			);
		}

		return new ServiceFetchController(
			'integriq',
			$this->createMock(IRequest::class),
			new ServiceFetchCatalogue($objects),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Two fetches, one allowed for portaliq: one is listed, without source,
	 * path or mappings.
	 *
	 * @return void
	 */
	public function testTheDesignerSeesOnlyTheFetchesTheAppMayCall(): void {
		$kadaster = [
			'slug' => 'kadaster-eigenaar', 'title' => 'Eigenaar', 'description' => 'Is de aanvrager eigenaar?',
			'source' => 'source-brk', 'path' => '/eigendom/{bsn}', 'query' => ['x' => '{bsn}'], 'outputMapping' => 'm-1', 'bodyMapping' => 'm-2',
			'inputs' => [['name' => 'bsn', 'type' => 'string', 'required' => true]], 'outputs' => [['name' => 'isEigenaar', 'type' => 'boolean']],
			'allowedApps' => ['portaliq'],
		];
		$other = ['slug' => 'rdw', 'title' => 'RDW', 'allowedApps' => ['buildiq']];

		$response = $this->controller(fetches: [$other, $kadaster])->index(app: 'portaliq');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['results' => [[
				'slug' => 'kadaster-eigenaar',
				'title' => 'Eigenaar',
				'description' => 'Is de aanvrager eigenaar?',
				'inputs' => $kadaster['inputs'],
				'outputs' => $kadaster['outputs'],
			]]],
			$response->getData()
		);
	}//end testTheDesignerSeesOnlyTheFetchesTheAppMayCall()

	/**
	 * Without an app the request is 400; a failing read is 503 without detail.
	 *
	 * @return void
	 */
	public function testAMissingAppIs400AndAFailingReadIs503(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(fetches: [])->index(app: ' ')->getStatus());

		$failed = $this->controller(fetches: null)->index(app: 'portaliq');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $failed->getStatus());
		$this->assertSame(['error' => 'The service fetches could not be listed.'], $failed->getData());
	}//end testAMissingAppIs400AndAFailingReadIs503()

	/**
	 * A designer is not an admin: the route carries #[NoAdminRequired] (gate 5).
	 *
	 * @return void
	 */
	public function testTheListIsOpenToSignedInUsers(): void {
		$attributes = array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new \ReflectionMethod(ServiceFetchController::class, 'index'))->getAttributes()
		);

		$this->assertSame([NoAdminRequired::class], $attributes);
	}//end testTheListIsOpenToSignedInUsers()
}//end class
