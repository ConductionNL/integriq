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
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
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
	 * Activating a seeded vendor source from the source page writes a source
	 * the register accepts, so the activation is kept rather than refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004
	 */
	public function testActivatingASeededSourceWritesWhatTheRegisterAccepts(): void {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/document-generation-vendor-adapter.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		foreach ($fragment['components']['objects'] as $seed) {
			$this->saved = [];
			unset($seed['@self']);
			$controller = $this->controllerFor($seed);

			$response = $controller->activate(sourceId: 'source-1');

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), (string)$seed['name']);
			$this->assertTrue($this->saved[0]['isEnabled']);
			$this->assertSame([], RegisterSchemaValidator::errors('source', $this->saved[0]), (string)$seed['name']);
		}

	}//end testActivatingASeededSourceWritesWhatTheRegisterAccepts()

	/**
	 * A source seeded before this fix still carries an empty credential
	 * reference; activating it drops the empty reference, so the write is
	 * one the register accepts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
	 */
	public function testAnEmptyCredentialReferenceIsDroppedOnActivation(): void {
		$controller = $this->controllerFor(
			[
				'name' => 'Xential',
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
		$this->assertArrayNotHasKey('authentication', $this->saved[0]['configuration']);
		$this->assertSame([], RegisterSchemaValidator::errors('source', $this->saved[0]));

	}//end testAnEmptyCredentialReferenceIsDroppedOnActivation()

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
