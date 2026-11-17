<?php

/**
 * Unit tests for saving a SharePoint to publication setup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SharePointSetupController;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The real set over a doubled configuration import: what is imported, and what the dialog gets back.
 *
 * @covers \OCA\Integriq\Controller\SharePointSetupController
 * @uses   \OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet
 * @uses   \OCA\Integriq\Service\Zgw\ZgwSetCatalogue
 */
class SharePointSetupIntakeTest extends TestCase {

	/**
	 * The import documents handed to the configuration service.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $imported = [];

	/**
	 * The four choices of the spec's scenario, as the dialog posts them.
	 *
	 * @return array<string, mixed>
	 */
	private function choices(): array {
		return [
			'credentialId' => 'c0ffee00-0000-4000-8000-000000000001',
			'siteId' => 'example.sharepoint.com,1111,2222',
			'driveId' => 'b!woo-dossiers',
			'folderId' => '01GEREED',
			'folderName' => 'Gereed',
			'catalog' => 'woo-catalogus',
			'register' => 'publication',
			'schema' => 'publication',
			'requiresApproval' => 'true',
		];
	}//end choices()

	/**
	 * The controller for a request with these parameters; the import answers with an entity per slug or throws.
	 *
	 * @param array<string, mixed>  $params The request parameters.
	 * @param RuntimeException|null $fails  What the import throws, if anything.
	 * @param LoggerInterface|null  $logger The logger.
	 *
	 * @return SharePointSetupController
	 */
	private function controller(array $params, ?RuntimeException $fails=null, ?LoggerInterface $logger=null): SharePointSetupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$configuration = $this->createMock(ConfigurationServiceInterface::class);
		$configuration->method('importConfiguration')->willReturnCallback(
			function (array $oas) use ($fails): array {
				$this->imported[] = $oas;
				if ($fails !== null) {
					throw $fails;
				}

				$result = [];
				foreach ($oas['components'] as $bucket => $entities) {
					foreach (array_keys($entities) as $slug) {
						$entity = new ObjectEntity();
						$entity->setUuid('uuid-of-' . $slug);
						$result[$bucket][$slug] = $entity;
					}
				}

				return $result;
			}
		);

		return new SharePointSetupController(
			request: $request,
			adapter: $this->createMock(SharePointOnlineAdapter::class),
			set: new SharePointPublicationSet(),
			configuration: $configuration,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end controller()

	/**
	 * Only an integriq administrator saves a setup.
	 *
	 * @return void
	 */
	public function testTheSaveIsForIntegriqAdministratorsOnly(): void {
		$this->assertCount(1, (new ReflectionMethod(SharePointSetupController::class, 'intake'))->getAttributes(AuthorizedAdminSetting::class));
	}//end testTheSaveIsForIntegriqAdministratorsOnly()

	/**
	 * GIVEN the four choices WHEN the administrator saves THEN one source, mapping, rule and synchronization
	 * are imported in one document, the source holding a broker reference and no secret.
	 *
	 * @return void
	 */
	public function testSavingImportsOneOfEachThroughTheConfigurationImport(): void {
		$response = $this->controller(params: $this->choices())->intake();

		$this->assertSame(201, $response->getStatus());
		$this->assertCount(1, $this->imported);

		$components = $this->imported[0]['components'];
		$this->assertSame(['sources', 'mappings', 'rules', 'synchronizations'], array_keys($components));
		$source = array_values($components['sources'])[0];
		$this->assertSame(['credentialRef' => ['credentialId' => 'c0ffee00-0000-4000-8000-000000000001']], $source['configuration']['authentication']);
		$this->assertTrue(array_values($components['synchronizations'])[0]['sourceConfig']['requiresApproval']);

		$created = $response->getData()['created'];
		$this->assertSame(['sources', 'mappings', 'rules', 'synchronizations'], array_keys($created));
		$slug = array_keys($components['synchronizations'])[0];
		$this->assertSame([['slug' => $slug, 'id' => 'uuid-of-' . $slug]], $created['synchronizations']);
	}//end testSavingImportsOneOfEachThroughTheConfigurationImport()

	/**
	 * A setup missing a choice is refused and imports nothing.
	 *
	 * @return void
	 */
	public function testAMissingChoiceImportsNothing(): void {
		$response = $this->controller(params: array_merge($this->choices(), ['catalog' => '']))->intake();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'invalid_setup', 'message' => 'Choose catalog before saving.'], $response->getData());
		$this->assertSame([], $this->imported);
	}//end testAMissingChoiceImportsNothing()

	/**
	 * A failed import is logged and answered as a failure, with nothing reported as created.
	 *
	 * @return void
	 */
	public function testAFailedImportReportsNothingCreated(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('SharePoint publication intake: the import failed: store down');

		$response = $this->controller(params: $this->choices(), fails: new RuntimeException('store down'), logger: $logger)->intake();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'import_failed', 'message' => 'store down'], $response->getData());
	}//end testAFailedImportReportsNothingCreated()
}//end class
