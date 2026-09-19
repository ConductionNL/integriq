<?php

/**
 * Integriq — migration source registry and preview tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration
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

namespace OCA\Integriq\Tests\Unit\Migration;

use OCA\Integriq\Migration\MigrationPreviewReader;
use OCA\Integriq\Migration\MigrationRecord;
use OCA\Integriq\Migration\MigrationSourceAdapterInterface;
use OCA\Integriq\Migration\MigrationSourceRegistry;
use OCA\Integriq\Migration\UnknownMigrationSourceException;
use PHPUnit\Framework\TestCase;

/**
 * REQ-MSA-001 and REQ-MSA-004.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001
 */
class MigrationSourceRegistryTest extends TestCase {
	/**
	 * An adapter double for one source id.
	 *
	 * @param string $id The source id.
	 * @param array{count:int,complete:bool} $counted What count() answers.
	 *
	 * @return MigrationSourceAdapterInterface The double.
	 */
	private function adapter(string $id, array $counted = ['count' => 3, 'complete' => true]): MigrationSourceAdapterInterface {
		$adapter = $this->createMock(MigrationSourceAdapterInterface::class);
		$adapter->method('id')->willReturn($id);
		$adapter->method('describe')->willReturn(
			[
				'id' => $id,
				'label' => ucfirst($id),
				'kinds' => [
					['kind' => 'case', 'label' => 'Case', 'identifier' => 'id', 'stableIdentifier' => true],
					['kind' => 'note', 'label' => 'Note', 'identifier' => null, 'stableIdentifier' => false],
				],
			]
		);
		$adapter->method('count')->willReturn($counted);
		$adapter->method('sample')->willReturn(
			[new MigrationRecord('case', ['titel' => 'Een zaak'], $id, 'zaak-1', 1700000000)]
		);

		return $adapter;
	}//end adapter()

	/**
	 * An unknown source id fails naming itself, and reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceIdFailsNamingItselfAndReadsNothing(): void {
		$registry = new MigrationSourceRegistry([$this->adapter('redmine')]);
		$adapter = $this->adapter('redmine');

		try {
			$registry->get('otobo');
			$this->fail('An unknown source id must not resolve.');
		} catch (UnknownMigrationSourceException $e) {
			$this->assertSame('otobo', $e->getSourceId());
			$this->assertStringContainsString('redmine', $e->getMessage());
			$this->assertStringContainsString('Nothing was read', $e->getMessage());
		}
	}//end testAnUnknownSourceIdFailsNamingItselfAndReadsNothing()

	/**
	 * describe() names each record kind and what it keys on, and says plainly
	 * when a kind has no stable identifier.
	 *
	 * @return void
	 */
	public function testDescribeNamesTheKindsAndTheirIdentifiers(): void {
		$described = (new MigrationSourceRegistry([$this->adapter('redmine')]))->describeAll();

		$this->assertCount(1, $described);
		$kinds = $described[0]['kinds'];
		$this->assertTrue($kinds[0]['stableIdentifier']);
		$this->assertSame('id', $kinds[0]['identifier']);
		$this->assertFalse($kinds[1]['stableIdentifier'], 'A kind with no stable key says so before any read.');
	}//end testDescribeNamesTheKindsAndTheirIdentifiers()

	/**
	 * A second incumbent is a registration. The engine is not touched.
	 *
	 * @return void
	 */
	public function testASecondAdapterIsJustAnotherRegistration(): void {
		$registry = new MigrationSourceRegistry([$this->adapter('redmine')]);
		$registry->register($this->adapter('otobo'));

		$this->assertSame(['redmine', 'otobo'], $registry->ids());
		$this->assertSame('otobo', $registry->get('otobo')->id());
	}//end testASecondAdapterIsJustAnotherRegistration()

	/**
	 * The preview reports a count and a sample per kind, and writes nothing.
	 *
	 * @return void
	 */
	public function testThePreviewReportsCountsAndSamplesAndWritesNothing(): void {
		$reader = new MigrationPreviewReader(new MigrationSourceRegistry([$this->adapter('redmine')]));

		$preview = $reader->preview('redmine');

		$this->assertFalse($preview['wrote']);
		$this->assertTrue($preview['complete']);
		$this->assertCount(2, $preview['kinds']);
		$this->assertSame(3, $preview['kinds'][0]['count']);
		$this->assertSame('complete', $preview['kinds'][0]['countIs']);
		$this->assertSame('zaak-1', $preview['kinds'][0]['sample'][0]['provenance']['sourceIdentifier']);
	}//end testThePreviewReportsCountsAndSamplesAndWritesNothing()

	/**
	 * A truncated read says truncated. The partial count is labelled partial
	 * rather than presented as the size of the source.
	 *
	 * @return void
	 */
	public function testATruncatedReadSaysTruncatedNotSmall(): void {
		$reader = new MigrationPreviewReader(
			new MigrationSourceRegistry([$this->adapter('redmine', ['count' => 400, 'complete' => false])])
		);

		$preview = $reader->preview('redmine');

		$this->assertFalse($preview['complete']);
		$this->assertSame(400, $preview['kinds'][0]['count']);
		$this->assertSame('partial', $preview['kinds'][0]['countIs']);
	}//end testATruncatedReadSaysTruncatedNotSmall()

	/**
	 * A preview of a source nobody answers to fails rather than reporting an
	 * empty migration.
	 *
	 * @return void
	 */
	public function testAPreviewOfAnUnknownSourceFails(): void {
		$this->expectException(UnknownMigrationSourceException::class);

		(new MigrationPreviewReader(new MigrationSourceRegistry([])))->preview('redmine');
	}//end testAPreviewOfAnUnknownSourceFails()
}//end class
