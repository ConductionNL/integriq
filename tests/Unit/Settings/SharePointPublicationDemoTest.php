<?php

/**
 * The SharePoint to publication demo in the mock register.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
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

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The demo source, mapping, rule and synchronization: valid objects that reference each other and hold no secret.
 *
 * @coversNothing
 */
class SharePointPublicationDemoTest extends TestCase {

	/**
	 * The demo objects by schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function demo(): array {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$demo = [];
		foreach ($mock['components']['objects'] as $object) {
			if (str_starts_with((string)($object['@self']['slug'] ?? ''), 'demo-sharepoint-') === true) {
				$demo[$object['@self']['schema']] = $object;
			}
		}

		return $demo;
	}//end demo()

	/**
	 * The Synchronizations page lists a SharePoint to publication synchronization over the demo source.
	 *
	 * @return void
	 */
	public function testTheDemoSynchronizationRunsTheDemoSourceMappingAndRule(): void {
		$demo = $this->demo();

		$this->assertSame(['source', 'mapping', 'rule', 'synchronization'], array_keys($demo));
		$sync = $demo['synchronization'];
		$this->assertSame('SharePoint to publications (Gereed (demo))', $sync['name']);
		$this->assertSame('demo-sharepoint-publications-source', $sync['sourceId']);
		$this->assertSame('demo-sharepoint-folder-to-publication', $sync['sourceTargetMapping']);
		$this->assertSame(['demo-sharepoint-publication-documents'], $sync['actions']);
		$this->assertSame('demo-sharepoint-publications-source', $demo['rule']['configuration']['fetch_file']['source']);
		$this->assertArrayNotHasKey('publicationDate', $demo['mapping']['mapping']);
	}//end testTheDemoSynchronizationRunsTheDemoSourceMappingAndRule()

	/**
	 * The demo source is disabled, holds only a broker reference, and carries the canned folder listing.
	 *
	 * @return void
	 */
	public function testTheDemoSourceIsDisabledAndHoldsNoSecret(): void {
		$source = $this->demo()['source'];

		$this->assertFalse($source['isEnabled']);
		$this->assertSame(['credentialRef' => ['credentialId' => '5d3a0000-0000-4000-8000-000000000001']], $source['configuration']['authentication']);
		$this->assertTrue($source['configuration']['mock']);
		$this->assertSame(['01WOO2026014', '01WOO2026015'], array_column($source['configuration']['mockResponse']['value'], 'id'));
	}//end testTheDemoSourceIsDisabledAndHoldsNoSecret()

	/**
	 * Every demo object satisfies the schema that validates it.
	 *
	 * @return void
	 */
	public function testEveryDemoObjectMatchesItsSchema(): void {
		foreach ($this->demo() as $schema => $object) {
			unset($object['@self']);
			$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: $schema, object: $object), $schema);
		}
	}//end testEveryDemoObjectMatchesItsSchema()
}//end class
