<?php

/**
 * The packaged SharePoint to publication set: its install guard, its instantiation and its mapping against Graph.
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

use InvalidArgumentException;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * The real set file, the real mapping engine and the engine's own condition and origin-id rules over recorded Graph answers.
 *
 * @covers \OCA\Integriq\Service\Adapter\DocumentCms\SharePointPublicationSet
 * @uses   \OCA\Integriq\Service\MappingService
 * @uses   \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Zgw\ZgwSetCatalogue
 */
class SharePointPublicationSetTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../../fixtures/graph/';

	/**
	 * The choices of the spec's scenario.
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
			'catalog' => 'woo-catalogus-uuid',
			'register' => 'publication',
			'schema' => 'publication',
			'requiresApproval' => true,
		];
	}//end choices()

	/**
	 * A recorded Graph answer.
	 *
	 * @param string $name The fixture file without extension.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture(string $name): array {
		return json_decode((string)file_get_contents(self::FIXTURES . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * The mapping engine with only its storage collaborators doubled.
	 *
	 * @return MappingService
	 */
	private function mappingService(): MappingService {
		return new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end mappingService()

	/**
	 * What one run makes of the dossier folder: the items the synchronization's conditions keep, each mapped.
	 *
	 * @param array<string, mixed> $folder The folder's children as Graph lists them.
	 *
	 * @return array<string, array<string, mixed>> Origin id => mapped publication.
	 */
	private function runOnce(array $folder): array {
		$document = (new SharePointPublicationSet())->build(choices: $this->choices());
		$sync     = array_values($document['components']['synchronizations'])[0];
		$mapping  = array_values($document['components']['mappings'])[0];

		$conditionsHold = new ReflectionMethod(SynchronizationService::class, 'conditionsHold');
		$originId       = new ReflectionMethod(SynchronizationService::class, 'getOriginId');
		$engine         = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		$mapper         = $this->mappingService();

		$published = [];
		foreach ($folder['value'] as $item) {
			if ($conditionsHold->invoke(null, $sync['conditions'], $item) === false) {
				continue;
			}

			$fixture = self::FIXTURES . 'sharepoint-dossier-woo-2026-' . substr($item['id'], -3) . '.json';
			$item['documents'] = json_decode((string)file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);

			$published[$originId->invoke($engine, $sync, $item)] = $mapper->executeMapping(mapping: $mapping, input: $item);
		}

		return $published;
	}//end run()

	/**
	 * The packaged declaration passes its own install guard.
	 *
	 * @return void
	 */
	public function testThePackagedSetPassesTheInstallGuard(): void {
		$set = new SharePointPublicationSet();

		$this->assertSame([], $set->refuseTemplate(template: $set->template()));
		$this->assertSame('sharepoint-publications', $set->template()['slug']);
	}//end testThePackagedSetPassesTheInstallGuard()

	/**
	 * The guard refuses a publication date, an inline secret, an undeclared component and a fleet app name.
	 *
	 * @return void
	 */
	public function testTheGuardRefusesWhatWouldPublishOrLeakOrFailSilently(): void {
		$set      = new SharePointPublicationSet();
		$template = $set->template();
		$template['components']['mappings']['sharepoint-folder-to-publication']['mapping']['publicationDate'] = '{{ listItem.fields.publicatiedatum }}';
		$template['components']['sources']['sharepoint-publications-source']['configuration']['authentication']['client_secret'] = 'x';
		$template['rules'][] = 'not-declared';
		$template['description'] = 'Writes into opencatalogi.';

		$this->assertSame(
			[
				'The set names the fleet app "opencatalogi"; the operator binds the target register and schema instead.',
				'The set lists the rule "not-declared" but does not declare it.',
				'The source "sharepoint-publications-source" must authenticate with a broker reference only.',
				'The mapping "sharepoint-folder-to-publication" writes "publicationDate", which would publish on the first run.',
			],
			$set->refuseTemplate(template: $template)
		);
	}//end testTheGuardRefusesWhatWouldPublishOrLeakOrFailSilently()

	/**
	 * The choices fill the source with a broker reference only, and every reference follows the renamed slugs.
	 *
	 * @return void
	 */
	public function testTheChoicesMakeOneSourceSynchronizationMappingAndRuleWithoutASecret(): void {
		$document   = (new SharePointPublicationSet())->build(choices: $this->choices());
		$components = $document['components'];
		$suffix     = substr(hash('sha256', 'example.sharepoint.com,1111,2222|b!woo-dossiers|01GEREED'), 0, 8);

		$this->assertSame(['sources', 'mappings', 'rules', 'synchronizations'], array_keys($components));
		foreach ($components as $bucket => $entities) {
			$this->assertCount(1, $entities, $bucket);
		}

		$source = $components['sources']['sharepoint-publications-source-' . $suffix];
		$this->assertSame(['credentialRef' => ['credentialId' => 'c0ffee00-0000-4000-8000-000000000001']], $source['configuration']['authentication']);
		$this->assertSame('https://graph.microsoft.com/v1.0', $source['location']);
		$this->assertSame('example.sharepoint.com,1111,2222', $source['configuration']['siteId']);
		$this->assertSame('SharePoint publications (Gereed)', $source['name']);
		$this->assertSame('sharepoint-publications-source-' . $suffix, $source['slug']);

		$sync = $components['synchronizations']['sharepoint-publications-pull-' . $suffix];
		$this->assertSame('sharepoint-publications-source-' . $suffix, $sync['sourceId']);
		$this->assertSame('sharepoint-folder-to-publication-' . $suffix, $sync['sourceTargetMapping']);
		$this->assertSame(['sharepoint-publication-documents-' . $suffix], $sync['actions']);
		$this->assertSame('publication/publication', $sync['targetId']);
		$this->assertSame('/drives/b%21woo-dossiers/items/01GEREED/children', $sync['sourceConfig']['endpoint']);
		$this->assertSame('/drives/b%21woo-dossiers/items/{{ originId }}/children', $sync['sourceConfig']['extraDataConfigs'][0]['staticEndpoint']);
		$this->assertTrue($sync['sourceConfig']['requiresApproval']);

		$rule = $components['rules']['sharepoint-publication-documents-' . $suffix];
		$this->assertSame('sharepoint-publications-source-' . $suffix, $rule['configuration']['fetch_file']['source']);

		$this->assertSame('woo-catalogus-uuid', $components['mappings']['sharepoint-folder-to-publication-' . $suffix]['mapping']['catalog']);
		$this->assertDoesNotMatchRegularExpression('/%[A-Za-z]+%/', (string)json_encode($components));
	}//end testTheChoicesMakeOneSourceSynchronizationMappingAndRuleWithoutASecret()

	/**
	 * A second folder gets its own slugs; the library root lists the drive's root children.
	 *
	 * @return void
	 */
	public function testAnotherFolderGetsItsOwnSlugsAndTheRootListsTheDriveRoot(): void {
		$set   = new SharePointPublicationSet();
		$first = $set->build(choices: $this->choices());
		$root  = $set->build(choices: array_merge($this->choices(), ['folderId' => '', 'folderName' => '', 'requiresApproval' => 'false']));

		$this->assertNotSame(array_values($first['slugs']), array_values($root['slugs']));
		$sync = array_values($root['components']['synchronizations'])[0];
		$this->assertSame('/drives/b%21woo-dossiers/root/children', $sync['sourceConfig']['endpoint']);
		$this->assertFalse($sync['sourceConfig']['requiresApproval']);
		$this->assertSame('SharePoint publications (library root)', array_values($root['components']['sources'])[0]['name']);
	}//end testAnotherFolderGetsItsOwnSlugsAndTheRootListsTheDriveRoot()

	/**
	 * A setup missing a choice creates nothing.
	 *
	 * @return void
	 */
	public function testAMissingChoiceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Choose credentialId, catalog before saving.');

		(new SharePointPublicationSet())->build(choices: array_merge($this->choices(), ['credentialId' => ' ', 'catalog' => ['x']]));
	}//end testAMissingChoiceIsRefused()

	/**
	 * An unreadable set file is a refusal, not an empty set.
	 *
	 * @return void
	 */
	public function testAnUnreadableSetFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		(new SharePointPublicationSet(path: self::FIXTURES . 'missing.json'))->template();
	}//end testAnUnreadableSetFileIsRefused()

	/**
	 * Two dossiers become two draft publications with titles, a source reference and no publication date.
	 *
	 * @return void
	 */
	public function testTwoDossiersBecomeTwoDraftPublications(): void {
		$published = $this->runOnce(folder: $this->fixture(name: 'sharepoint-dossier-folder'));

		$this->assertSame(['01WOO2026014', '01WOO2026015'], array_keys($published));

		$first = $published['01WOO2026014'];
		$this->assertSame('Besluit op het Woo-verzoek over de herinrichting van het Stationsplein', $first['title']);
		$this->assertSame('Besluit en inventarislijst bij het verzoek van 3 september 2026.', $first['summary']);
		$this->assertSame('https://example.sharepoint.com/sites/woo-verzoeken/Dossiers/Gereed/Woo-2026-014', $first['source']);
		$this->assertSame('woo-catalogus-uuid', $first['catalog']);
		$this->assertArrayNotHasKey('publicationDate', $first);
		$this->assertArrayNotHasKey('published', $first);
		$this->assertSame(
			[
				['filename' => 'Besluit Woo-2026-014.pdf', 'endpoint' => '/drives/b!woo-dossiers/items/01BESLUIT014/content'],
				['filename' => 'Inventarislijst "bijlage 1" & toelichting.xlsx', 'endpoint' => '/drives/b!woo-dossiers/items/01INVENTARIS014/content'],
			],
			$first['attachments']
		);

		// No title column: the folder name; empty columns are left out.
		$second = $published['01WOO2026015'];
		$this->assertSame('Woo-2026-015', $second['title']);
		$this->assertArrayNotHasKey('summary', $second);
		$this->assertArrayNotHasKey('description', $second);
		$this->assertCount(1, $second['attachments']);
	}//end testTwoDossiersBecomeTwoDraftPublications()

	/**
	 * A renamed folder keeps its SharePoint item id, so the same publication gets the new title.
	 *
	 * @return void
	 */
	public function testARenamedFolderUpdatesTheSamePublication(): void {
		$folder = $this->fixture(name: 'sharepoint-dossier-folder');
		$before = $this->runOnce(folder: $folder);

		$folder['value'][1]['name']   = 'Woo-2026-015 herzien';
		$folder['value'][1]['webUrl'] = 'https://example.sharepoint.com/sites/woo-verzoeken/Dossiers/Gereed/Woo-2026-015 herzien';
		$after = $this->runOnce(folder: $folder);

		$this->assertSame(array_keys($before), array_keys($after));
		$this->assertSame('Woo-2026-015', $before['01WOO2026015']['title']);
		$this->assertSame('Woo-2026-015 herzien', $after['01WOO2026015']['title']);
		$this->assertSame($folder['value'][1]['webUrl'], $after['01WOO2026015']['source']);
	}//end testARenamedFolderUpdatesTheSamePublication()
}//end class
