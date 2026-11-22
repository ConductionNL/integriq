<?php

/**
 * The seeded example watched folder: a dormant `nextcloud-folder` source and
 * a disabled synchronization that would turn each dropped PDF into a draft
 * publication, read the way WatchedFolder reads it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * @uses \OCA\Integriq\Service\WatchedFolder\WatchedFolder
 */
final class WatchedFolderSeedTest extends TestCase {

	/**
	 * A fresh install carries the example, and both halves of it are off.
	 *
	 * @return void
	 */
	public function testTheExampleExistsAndIsDisabled(): void {
		$source          = self::seeded(schema: 'source', slug: 'example-watched-folder');
		$synchronization = self::seeded(schema: 'synchronization', slug: 'example-watched-folder-publications');

		$this->assertSame(expected: ['nextcloud-folder', false], actual: [$source['type'], $source['isEnabled']]);
		$this->assertSame(expected: ['nextcloud-folder', 'disabled'], actual: [$synchronization['sourceType'], $synchronization['status']]);
	}//end testTheExampleExistsAndIsDisabled()

	/**
	 * The example writes draft publications from PDFs and moves each handled file.
	 *
	 * @return void
	 */
	public function testTheExampleReadsAsADraftPublicationPickup(): void {
		$watched = new WatchedFolder(
			synchronization: self::seeded(schema: 'synchronization', slug: 'example-watched-folder-publications'),
			source: self::seeded(schema: 'source', slug: 'example-watched-folder')
		);

		$this->assertSame(expected: ['register' => 'opencatalogi', 'schema' => 'publication'], actual: $watched->target());
		$this->assertNull(actual: $watched->intakeApp());
		$this->assertSame(
			expected: ['*.pdf', 'move', 'processed', 'failed', 60, 'woo-intake'],
			actual: [
				$watched->pattern(),
				$watched->after(),
				$watched->folderFor(outcome: 'processed'),
				$watched->folderFor(outcome: 'failed'),
				$watched->stableSeconds(),
				$watched->ownerUid(),
			]
		);
	}//end testTheExampleReadsAsADraftPublicationPickup()

	/**
	 * One seeded object by schema and slug.
	 *
	 * @param string $schema The schema.
	 * @param string $slug   The slug.
	 *
	 * @return array<string, mixed>
	 */
	private static function seeded(string $schema, string $slug): array {
		$seed = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/integriq_seed_data.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$found = array_values(array_filter($seed[$schema] ?? [], static fn (array $row): bool => ($row['@self']['slug'] ?? '') === $slug));
		self::assertCount(expectedCount: 1, haystack: $found, message: $schema . ' ' . $slug . ' is seeded once');

		return $found[0];
	}//end seeded()
}//end class
