<?php

/**
 * A sessionless run of the SharePoint set's fetch_file rule attaches every document to its publication.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use GuzzleHttp\Promise\FulfilledPromise;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The real engine runs the set's rule with no user session; only HTTP, OpenRegister and file storage are doubled.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet
 * @uses   \OCA\Integriq\Service\Zgw\ZgwSetCatalogue
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchItem
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchRequest
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchRun
 */
class SharePointPublicationCronAttachTest extends TestCase {

	private const PUBLICATION = 'aa11bb22-3333-4444-8555-666677778888';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $log = [];

	/**
	 * A logger that records its warnings and errors.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level): void {
					$this->log[] = ['logger.' . $level, (string)$message];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * The engine over recording collaborators, as a background job would get it.
	 *
	 * @return SynchronizationService
	 */
	private function engine(): SynchronizationService {
		$test  = $this;
		$files = new class($test) {
			/**
			 * @param SharePointPublicationCronAttachTest $test The recording test.
			 */
			public function __construct(private SharePointPublicationCronAttachTest $test) {
			}

			/**
			 * Records a save onto an object.
			 *
			 * @param ObjectEntity $objectEntity The object.
			 * @param FileUpload   $upload The file.
			 *
			 * @return string
			 */
			public function saveFile($objectEntity, FileUpload $upload) {
				$content = $upload->content;
				if (is_resource($content) === true) {
					$content = stream_get_contents($content);
				}

				$this->test->log[] = ['saveFile', $objectEntity->getUuid(), $upload->fileName, $content];
				return 'file-node';
			}

			/**
			 * The files the object has.
			 *
			 * @return array
			 */
			public function getFiles() {
				return [];
			}
		};

		$objects = new class {
			/**
			 * The publication object.
			 *
			 * @param mixed $id The id.
			 *
			 * @return ObjectEntity
			 */
			public function find($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				return $entity;
			}
		};

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($files, $objects, $appManager) {
				$this->log[] = ['container', $id];
				return match ($id) {
					IAppManager::class => $appManager,
					IJobList::class => $this->createMock(IJobList::class),
					'OCA\OpenRegister\Service\FileService' => $files,
					default => $objects,
				};
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('callAsync')->willReturnCallback(
			function (...$args) {
				$endpoint = (string)$args[1];
				$this->log[] = ['callAsync', $args[0]->getUuid(), $endpoint];

				$callLog = new ObjectEntity();
				$callLog->setObject(['request' => ['url' => 'https://graph.microsoft.com/v1.0' . $endpoint], 'response' => ['statusCode' => 200, 'headers' => ['Content-Type' => 'application/pdf'], 'body' => 'BYTES' . $endpoint]]);
				foreach ($args as $arg) {
					if (is_string($arg) === true && is_file($arg) === true) {
						file_put_contents($arg, 'BYTES' . $endpoint);
					}
				}

				return new FulfilledPromise($callLog);
			}
		);

		$sources = $this->createMock(ORObjectService::class);
		$sources->method('find')->willReturnCallback(
			function ($id) {
				$this->log[] = ['source', $id];
				$source = new ObjectEntity();
				$source->setUuid('graph-source');
				$source->setObject(['uuid' => 'graph-source', 'location' => 'https://graph.microsoft.com/v1.0', 'configuration' => ['authentication' => ['credentialRef' => ['credentialId' => 'c-1']]]]);
				return $source;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$container,
			$sources,
			$this->createMock(ObjectService::class),
			$this->logger(),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end engine()

	/**
	 * GIVEN a run with no session WHEN the documents are fetched THEN each is attached to the publication, through the Graph source,
	 * and nothing asks for a user, a session or a Files folder.
	 *
	 * @return void
	 */
	public function testACronRunAttachesEveryDocumentToThePublication(): void {
		$document = (new SharePointPublicationSet())->build(
			choices: ['credentialId' => 'c0ffee00-0000-4000-8000-000000000001', 'siteId' => 's', 'driveId' => 'b!woo-dossiers', 'folderId' => 'f', 'catalog' => 'cat', 'register' => 'r', 'schema' => 'p']
		);
		$rule = array_values($document['components']['rules'])[0];
		$rule['id'] = 7;

		$mapped = [
			'title' => 'Woo-2026-014',
			'attachments' => [
				['filename' => 'Besluit Woo-2026-014.pdf', 'endpoint' => '/drives/b!woo-dossiers/items/01BESLUIT014/content'],
				['filename' => 'Inventarislijst.xlsx', 'endpoint' => '/drives/b!woo-dossiers/items/01INVENTARIS014/content'],
			],
		];

		$engine = $this->engine();
		$result = (new ReflectionMethod(SynchronizationService::class, 'processFetchFileRule'))->invoke($engine, $rule, $mapped, self::PUBLICATION);
		$tally  = (new ReflectionMethod(SynchronizationService::class, 'takeFileFetchTally'))->invoke($engine);

		$this->assertSame([], array_values(array_filter($this->log, static fn (array $call): bool => str_starts_with($call[0], 'logger.'))));
		$this->assertSame(['succeeded' => 2, 'failed' => 0, 'skipped' => 0], $tally);
		$this->assertSame('Woo-2026-014', $result['title']);

		$this->assertSame(
			[
				['callAsync', 'graph-source', '/drives/b!woo-dossiers/items/01BESLUIT014/content'],
				['callAsync', 'graph-source', '/drives/b!woo-dossiers/items/01INVENTARIS014/content'],
				['saveFile', self::PUBLICATION, 'Besluit Woo-2026-014.pdf', 'BYTES/drives/b!woo-dossiers/items/01BESLUIT014/content'],
				['saveFile', self::PUBLICATION, 'Inventarislijst.xlsx', 'BYTES/drives/b!woo-dossiers/items/01INVENTARIS014/content'],
			],
			array_values(array_filter($this->log, static fn (array $call): bool => in_array($call[0], ['callAsync', 'saveFile'], true)))
		);

		// The rule names the set's own Graph source by slug, so the broker reference on it authenticates every fetch.
		$lookups = array_values(array_column(array_filter($this->log, static fn (array $call): bool => $call[0] === 'source'), 1));
		$this->assertSame($rule['configuration']['fetch_file']['source'], $lookups[0]);

		$asked = array_column(array_filter($this->log, static fn (array $call): bool => $call[0] === 'container'), 1);
		foreach ($asked as $id) {
			$this->assertDoesNotMatchRegularExpression('/IUserSession|IRootFolder|IUserManager/', $id);
		}
	}//end testACronRunAttachesEveryDocumentToThePublication()
}//end class
