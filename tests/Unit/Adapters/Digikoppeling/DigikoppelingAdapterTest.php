<?php

/**
 * OpenConnector — Digikoppeling adapter catalogue descriptor tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling;

use OCA\OpenConnector\Adapters\Digikoppeling\DigikoppelingAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ADR-017 Rule 1 catalogue entry + config schema (REQ-DK-001).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class DigikoppelingAdapterTest extends TestCase {

	/**
	 * The adapter is a catalogue card that adds no menu or /beheer route.
	 *
	 * @return void
	 */
	public function testIsCatalogueEntryNotMenu(): void {
		$adapter = new DigikoppelingAdapter();

		$this->assertSame('digikoppeling', $adapter->id());
		$this->assertSame('Digikoppeling', $adapter->label());
		$this->assertFalse($adapter->addsTopLevelMenu());
		$this->assertFalse($adapter->addsManagementRoute());
		$this->assertSame(['wus', 'ebms2'], $adapter->profiles());
	}//end testIsCatalogueEntryNotMenu()

	/**
	 * The config schema captures the fields REQ-DK-001 mandates, including a
	 * certificateRef (never an inline key/secret).
	 *
	 * @return void
	 */
	public function testConfigSchemaCapturesRequiredFields(): void {
		$schema = (new DigikoppelingAdapter())->configSchema();
		$props = $schema['properties'];

		foreach (['profile', 'oin', 'service', 'action', 'endpoint', 'certificateRef', 'reliableMessaging'] as $field) {
			$this->assertArrayHasKey($field, $props, $field . ' present in config schema');
		}

		$this->assertSame(['wus', 'ebms2'], $schema['properties']['profile']['enum']);
		$this->assertContains('certificateRef', $schema['required']);

		// No inline key/secret field is present.
		$this->assertArrayNotHasKey('privateKey', $props);
		$this->assertArrayNotHasKey('certificate', $props);
	}//end testConfigSchemaCapturesRequiredFields()
}//end class
