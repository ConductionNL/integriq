<?php

/**
 * The import preview warns about a deprecated template before anything is written.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-the-legacy-sharepoint-woo-template-is-marked-deprecated-req-sppi-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConfigurationImportPreviewService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The legacy SharePoint Woo template, as shipped, through the preview.
 *
 * @covers \OCA\Integriq\Service\ConfigurationImportPreviewService
 * @uses   \OCA\Integriq\Service\ConfigurationImportPreviewRun
 */
class ConfigurationImportPreviewDeprecationTest extends TestCase {

	/**
	 * The preview over an empty target environment.
	 *
	 * @param array<string, mixed> $components The document's components.
	 *
	 * @return array<string, mixed>
	 */
	private function preview(array $components): array {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$objects->expects($this->never())->method('saveObject');

		return (new ConfigurationImportPreviewService($objects))->preview(oas: ['components' => $components]);
	}//end preview()

	/**
	 * GIVEN the legacy template's four files WHEN previewed THEN each is reported deprecated, naming the new set.
	 *
	 * @return void
	 */
	public function testEveryLegacySharePointWooEntityIsReportedDeprecated(): void {
		$dir        = dirname(__DIR__, 3) . '/configurations/sharepoint-woo/';
		$components = [];
		foreach (['sources' => 'sources/sharepoint.json', 'mappings' => 'mappings/sharepoint-woo-verzoek-to-publications.json', 'rules' => 'rules/Rule-Fetch Sharepoint files-v0.0.1.json', 'synchronizations' => 'synchronizations/sharepoint-publications.json'] as $bucket => $file) {
			$components[$bucket]['legacy-' . $bucket] = json_decode((string)file_get_contents($dir . $file), true, flags: JSON_THROW_ON_ERROR);
		}

		$deprecations = $this->preview(components: $components)['deprecations'];

		$this->assertSame(['source', 'mapping', 'rule', 'synchronization'], array_column($deprecations, 'type'));
		$this->assertSame(['sharepoint-publications'], array_values(array_unique(array_column($deprecations, 'replacedBy'))));
		$this->assertStringContainsString('Route to publications', $deprecations[0]['reason']);
	}//end testEveryLegacySharePointWooEntityIsReportedDeprecated()

	/**
	 * A string marker names the replacement; a document without a marker gets no deprecations list at all.
	 *
	 * @return void
	 */
	public function testAStringMarkerNamesTheReplacementAndNoMarkerAddsNothing(): void {
		$this->assertSame(
			[['type' => 'mapping', 'slug' => 'old-map', 'replacedBy' => 'new-map', 'reason' => '']],
			$this->preview(components: ['mappings' => ['old-map' => ['slug' => 'old-map', 'deprecated' => 'new-map']]])['deprecations']
		);

		$preview = $this->preview(components: ['mappings' => ['m' => ['slug' => 'm', 'deprecated' => ''], 'n' => ['slug' => 'n']]]);
		$this->assertArrayNotHasKey('deprecations', $preview);
		$this->assertSame(['creates', 'updates', 'collisions', 'unresolvedReferences', 'credentialsNeedingReentry'], array_keys($preview));
	}//end testAStringMarkerNamesTheReplacementAndNoMarkerAddsNothing()
}//end class
