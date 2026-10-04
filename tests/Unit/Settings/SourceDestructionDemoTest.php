<?php

/**
 * The demo data carries a synchronization that purges.
 *
 * The mock register has no publication synchronization (the design assumed
 * one), so the purge is declared on the full-mode demo synchronization: a
 * demo run then reports a purged count instead of a soft delete.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The purge demo synchronization against the real register schema.
 */
class SourceDestructionDemoTest extends TestCase {
	private const SLUG = 'synchronization-voorbeeld-name-3-3';

	/**
	 * The demo synchronization by its slug.
	 *
	 * @return array<string,mixed>
	 */
	private function demoSynchronization(): array {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? null) === self::SLUG) {
				return $object;
			}
		}

		$this->fail('No demo synchronization ' . self::SLUG);
	}//end demoSynchronization()

	/**
	 * It purges on a full run and on a destruction notice.
	 *
	 * @return void
	 */
	public function testTheDemoSynchronizationPurges(): void {
		$sync = $this->demoSynchronization();

		$this->assertSame('full', $sync['syncMode'], 'A purge only runs on a complete full run.');
		$this->assertSame(DisappearancePolicy::PURGE, DisappearancePolicy::fromSourceConfig($sync['sourceConfig']));
		$this->assertSame('purge', $sync['sourceConfig']['onSourceDestroyed'] ?? null);
	}//end testTheDemoSynchronizationPurges()

	/**
	 * It is still a valid synchronization object.
	 *
	 * @return void
	 */
	public function testTheDemoSynchronizationMatchesTheSchema(): void {
		$sync = $this->demoSynchronization();
		unset($sync['@self']);

		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'synchronization', object: $sync));
	}//end testTheDemoSynchronizationMatchesTheSchema()
}//end class
