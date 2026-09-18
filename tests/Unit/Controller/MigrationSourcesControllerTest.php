<?php

/**
 * Integriq — migration sources controller tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MigrationSourcesController;
use OCA\Integriq\Migration\ColumnMappingValidator;
use OCA\Integriq\Migration\MigrationPreviewReader;
use OCA\Integriq\Migration\MigrationSourceAdapterInterface;
use OCA\Integriq\Migration\MigrationSourceRegistry;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The read-only surface, and the mapping refusal a screen calls before it
 * saves.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-mapping-onto-a-field-that-does-not-exist-is-refused-at-save
 */
class MigrationSourcesControllerTest extends TestCase {
	/**
	 * Build the controller over one adapter double.
	 *
	 * @return MigrationSourcesController The controller under test.
	 */
	private function controller(): MigrationSourcesController {
		$adapter = $this->createMock(MigrationSourceAdapterInterface::class);
		$adapter->method('id')->willReturn('redmine');
		$adapter->method('describe')->willReturn(
			[
				'id' => 'redmine',
				'label' => 'Redmine',
				'kinds' => [['kind' => 'issue', 'label' => 'Issue', 'identifier' => 'id', 'stableIdentifier' => true]],
			]
		);
		$adapter->method('count')->willReturn(['count' => 7, 'complete' => true]);
		$adapter->method('sample')->willReturn([]);

		$registry = new MigrationSourceRegistry([$adapter]);

		return new MigrationSourcesController(
			'integriq',
			$this->createMock(IRequest::class),
			$registry,
			new MigrationPreviewReader($registry),
			new ColumnMappingValidator()
		);
	}//end controller()

	/**
	 * The inventory lists every adapter and what it can yield.
	 *
	 * @return void
	 */
	public function testTheInventoryListsTheAdapters(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(['redmine'], array_column($data['results'], 'id'));
	}//end testTheInventoryListsTheAdapters()

	/**
	 * The preview reports the counts and says it wrote nothing.
	 *
	 * @return void
	 */
	public function testThePreviewReportsCountsAndWritesNothing(): void {
		$data = $this->controller()->preview('redmine')->getData();

		$this->assertFalse($data['wrote']);
		$this->assertSame(7, $data['kinds'][0]['count']);
	}//end testThePreviewReportsCountsAndWritesNothing()

	/**
	 * A preview of a source nothing answers to is a 404 naming the id.
	 *
	 * @return void
	 */
	public function testAPreviewOfAnUnknownSourceIsA404(): void {
		$response = $this->controller()->preview('otobo');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringContainsString('otobo', $response->getData()['error']);
	}//end testAPreviewOfAnUnknownSourceIsA404()

	/**
	 * A mapping onto a field the schema does not have is refused, naming it.
	 *
	 * @return void
	 */
	public function testAMappingOntoAMissingFieldIsRefused(): void {
		$response = $this->controller()->validateMapping(
			['name' => 'levering', 'kind' => 'case', 'columns' => ['plaats' => 'woonplaats']],
			['reference', 'city'],
			[]
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['valid']);
		$this->assertStringContainsString('woonplaats', $response->getData()['errors'][0]);
	}//end testAMappingOntoAMissingFieldIsRefused()

	/**
	 * A mapping with no columns at all is refused before anything else is
	 * checked.
	 *
	 * @return void
	 */
	public function testAMappingWithNoColumnsIsRefused(): void {
		$response = $this->controller()->validateMapping(['name' => 'leeg', 'columns' => []], ['reference'], []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('no columns', $response->getData()['errors'][0]);
	}//end testAMappingWithNoColumnsIsRefused()

	/**
	 * A valid mapping comes back with its stored shape, version and all.
	 *
	 * @return void
	 */
	public function testAValidMappingComesBackWithItsStoredShape(): void {
		$response = $this->controller()->validateMapping(
			['name' => 'levering', 'kind' => 'case', 'version' => 2, 'columns' => ['plaats' => 'city']],
			['city'],
			['city']
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
		$this->assertSame(2, $response->getData()['mapping']['version']);
	}//end testAValidMappingComesBackWithItsStoredShape()
}//end class
