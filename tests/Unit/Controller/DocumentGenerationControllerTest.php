<?php

/**
 * Unit tests for DocumentGenerationController.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#scenario-a-source-without-credentials-cannot-activate
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DocumentGenerationController;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderRegistry;
use OCA\Integriq\Service\DocumentGeneration\LogDocumentGenerationProvider;
use OCA\Integriq\Service\DocumentGeneration\SmartDocumentsProvider;
use OCA\Integriq\Service\DocumentGeneration\XentialProvider;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the operator endpoints.
 */
class DocumentGenerationControllerTest extends TestCase {

	/**
	 * The objects saveObject() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the controller over one seeded source.
	 *
	 * @param array $source The source object.
	 *
	 * @return DocumentGenerationController The controller.
	 */
	private function controllerFor(array $source): DocumentGenerationController {
		$entity = ObjectServiceMockBuilder::objectEntity($this, $source, 'source-1');

		/**
		 * @var ORObjectService|MockObject $objectService
		 */
		$objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register = '', string $schema = '', ?string $uuid = null): ObjectEntity {
				$this->saved[] = $object;

				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'source-1'));
			}
		);

		$broker = $this->getMockBuilder(BrokeredCallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCredentialRef', 'prepare', 'dispatch'])
			->getMock();
		$broker->method('hasCredentialRef')->willReturnCallback(
			static fn (array $config): bool => trim(
				(string)(($config['authentication'] ?? [])['credentialRef'] ?? '')
			) !== ''
		);

		$registry = new DocumentGenerationProviderRegistry(
			logProvider: new LogDocumentGenerationProvider(),
			smartDocuments: new SmartDocumentsProvider(
				brokeredCallService: $broker,
				logger: new NullLogger()
			),
			xentialProvider: new XentialProvider(brokeredCallService: $broker, logger: new NullLogger())
		);

		return new DocumentGenerationController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			registry: $registry,
			logger: new NullLogger()
		);

	}//end controllerFor()

	/**
	 * Reset the recorded writes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];

	}//end setUp()

	/**
	 * A vendor source with no credential reference is refused, and nothing is enabled.
	 *
	 * @return void
	 */
	public function testActivationIsRefusedWithoutACredentialReference(): void {
		$controller = $this->controllerFor(
			[
				'type' => 'documentGeneration',
				'isEnabled' => false,
				'configuration' => [
					'providerId' => 'smartdocuments',
					'baseUrl' => 'https://vendor.example',
					'authentication' => ['credentialRef' => ''],
				],
			]
		);

		$response = $controller->activate(sourceId: 'source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('credentialRef', $response->getData()['error']);
		$this->assertSame([], $this->saved, 'a source that cannot render is not enabled');

	}//end testActivationIsRefusedWithoutACredentialReference()

	/**
	 * A mock-mode source activates without a credential, and is enabled.
	 *
	 * @return void
	 */
	public function testAMockModeSourceActivates(): void {
		$controller = $this->controllerFor(
			[
				'type' => 'documentGeneration',
				'isEnabled' => false,
				'configuration' => [
					'providerId' => 'xential',
					'baseUrl' => 'https://vendor.example',
					'mockMode' => true,
					'authentication' => ['credentialRef' => ''],
				],
			]
		);

		$response = $controller->activate(sourceId: 'source-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($this->saved[0]['isEnabled']);

	}//end testAMockModeSourceActivates()

	/**
	 * The templates of a mock-mode source are listed from the binding.
	 *
	 * @return void
	 */
	public function testTheVendorTemplatesAreListed(): void {
		$controller = $this->controllerFor(
			[
				'type' => 'documentGeneration',
				'isEnabled' => true,
				'configuration' => [
					'providerId' => 'xential',
					'baseUrl' => 'https://vendor.example',
					'mockMode' => true,
					'authentication' => ['credentialRef' => ''],
				],
			]
		);

		$data = $controller->templates(sourceId: 'source-1')->getData();

		$this->assertSame('xential', $data['providerId']);
		$this->assertCount(3, $data['templates']);
		$this->assertSame('xt-beschikking', $data['templates'][0]['id']);

	}//end testTheVendorTemplatesAreListed()

	/**
	 * A source that is not a document generation source is refused as one.
	 *
	 * @return void
	 */
	public function testASourceOfAnotherKindIsNotTreatedAsOne(): void {
		$controller = $this->controllerFor(
			['type' => 'api', 'isEnabled' => true, 'configuration' => ['providerId' => 'xential']]
		);

		$response = $controller->templates(sourceId: 'source-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('not a document generation source', $response->getData()['error']);

	}//end testASourceOfAnotherKindIsNotTreatedAsOne()
}//end class
