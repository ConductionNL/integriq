<?php

/**
 * Unit tests for OpenApiImportController: both routes are admin-only, a
 * document is accepted as an object or as text, a URL on an internal address
 * is refused with 400 before any request, and an import answers 201.
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
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OpenApiImportController;
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiImportService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the OpenAPI import routes.
 */
#[CoversClass(OpenApiImportController::class)]
#[UsesClass(OpenApiImportService::class)]
#[UsesClass(OpenApiDocumentNormaliser::class)]
#[UsesClass(SourceHandler::class)]
#[UsesClass(EndpointHandler::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
#[UsesClass(EgressGuard::class)]
class OpenApiImportControllerTest extends TestCase {

	private OrObjectService&MockObject $objects;

	private IClientService&MockObject $clients;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(OrObjectService::class);
		$this->objects->method('findAll')->willReturn(['results' => []]);
		$this->clients = $this->createMock(IClientService::class);
	}//end setUp()

	/**
	 * The controller for a request with the given parameters.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return OpenApiImportController The controller.
	 */
	private function controller(array $params): OpenApiImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$registry = new SensitiveFieldRegistry();
		$normaliser = new OpenApiDocumentNormaliser();

		return new OpenApiImportController(
			appName: 'integriq',
			request: $request,
			importer: new OpenApiImportService(
				objects: $this->objects,
				sources: new SourceHandler(orObjectService: $this->objects, sensitiveFieldRegistry: $registry),
				endpoints: new EndpointHandler(orObjectService: $this->objects, sensitiveFieldRegistry: $registry),
				normaliser: $normaliser,
				egressGuard: new EgressGuard(),
				clientService: $this->clients,
				logger: $this->createMock(LoggerInterface::class)
			),
			normaliser: $normaliser
		);
	}//end controller()

	/**
	 * The petstore fixture as JSON text.
	 *
	 * @return string The document.
	 */
	private static function petstore(): string {
		return (string) file_get_contents(__DIR__.'/../../../lib/Settings/examples/petstore-openapi.json');
	}//end petstore()

	/**
	 * Both routes are gated to integriq administrators, never opened to any user or the public.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['preview', 'import'] as $method) {
			$reflection = new ReflectionMethod(OpenApiImportController::class, $method);
			$attributes = $reflection->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes);
			$this->assertSame(IntegriqAdmin::class, $attributes[0]->getArguments()[0]);
			$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class));
			$this->assertCount(0, $reflection->getAttributes(PublicPage::class));
		}
	}//end testBothRoutesAreAdminOnly()

	/**
	 * A document given as JSON text previews, and nothing is written.
	 *
	 * @return void
	 */
	public function testAPreviewOfADocumentGivenAsText(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$response = $this->controller(params: ['document' => self::petstore(), 'prefix' => 'vendor'])->preview();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['listPets', 'createPets', 'showPetById'], array_column($response->getData()['operations'], 'key'));
	}//end testAPreviewOfADocumentGivenAsText()

	/**
	 * A YAML document previews too.
	 *
	 * @return void
	 */
	public function testAPreviewOfAYamlDocument(): void {
		$yaml = "openapi: 3.0.3\ninfo:\n  title: Zaken\n  version: '1'\nservers:\n  - url: https://zaken.example.com\npaths:\n  /zaken:\n    get:\n      operationId: listZaken\n      responses:\n        '200':\n          description: OK\n";

		$response = $this->controller(params: ['document' => $yaml, 'prefix' => 'zaken'])->preview();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('zaken/zaken', $response->getData()['operations'][0]['gatewayPath']);
	}//end testAPreviewOfAYamlDocument()

	/**
	 * A URL on an internal address is 400 egress_refused, and no request is made.
	 *
	 * @return void
	 */
	public function testAUrlOnAnInternalAddressIsRefused(): void {
		$this->clients->expects($this->never())->method('newClient');

		$response = $this->controller(params: ['url' => 'https://192.168.1.10/openapi.json', 'prefix' => 'vendor'])->preview();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('egress_refused', $response->getData()['error']);
	}//end testAUrlOnAnInternalAddressIsRefused()

	/**
	 * Without a document or a URL, or with a broken one, the answer is 400.
	 *
	 * @return void
	 */
	public function testAMissingOrBrokenDocumentIs400(): void {
		$this->assertSame(400, $this->controller(params: ['prefix' => 'vendor'])->preview()->getStatus());
		$this->assertSame(400, $this->controller(params: ['document' => '{"not": "openapi"}', 'prefix' => 'vendor'])->preview()->getStatus());
	}//end testAMissingOrBrokenDocumentIs400()

	/**
	 * An import of two chosen operations answers 201 with one source and two created endpoints.
	 *
	 * @return void
	 */
	public function testAnImportAnswers201(): void {
		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register = null, mixed $schema = null) use (&$saved): ObjectEntity {
				$saved[] = (string) $schema;
				$entity = new ObjectEntity();
				$entity->setUuid($schema.'-'.count($saved));
				$entity->setObject($object);

				return $entity;
			}
		);

		$response = $this->controller(
			params: ['document' => json_decode(self::petstore(), true), 'prefix' => 'vendor', 'operations' => ['listPets', 'showPetById'], 'name' => 'Petstore']
		)->import();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(2, $response->getData()['created']);
		$this->assertSame('Petstore', $response->getData()['source']['name']);
		$this->assertSame(['source', 'endpoint', 'endpoint'], $saved);
	}//end testAnImportAnswers201()
}//end class
