<?php

/**
 * Unit tests for DirectorySnapshot and DirectorySyncRun — what one read of the
 * directory holds, and the configuration a run reads from its connection.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectoryEntry;
use OCA\Integriq\Directory\DirectorySnapshot;
use OCA\Integriq\Directory\DirectorySyncRun;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for a directory snapshot and a run's state.
 */
#[CoversClass(DirectorySnapshot::class)]
#[CoversClass(DirectorySyncRun::class)]
#[UsesClass(DirectoryEntry::class)]
class DirectorySnapshotTest extends TestCase {

	/**
	 * An empty snapshot is complete, with no entries, groups or failures.
	 *
	 * @return void
	 */
	public function testAnEmptySnapshotIsComplete(): void {
		$snapshot = new DirectorySnapshot();

		$this->assertSame([], $snapshot->getEntries());
		$this->assertTrue($snapshot->isComplete());
		$this->assertSame([], $snapshot->getFailures());
		$this->assertSame([], $snapshot->getDirectoryGroups());
	}//end testAnEmptySnapshotIsComplete()

	/**
	 * The directory groups are every entry's groups, once each, in first-seen order.
	 *
	 * @return void
	 */
	public function testDirectoryGroupsAreDistinctAcrossEntries(): void {
		$anja = new DirectoryEntry(userId: 'anja', directoryGroups: ['OU=Vergunningen', 'OU=Toezicht']);
		$bram = new DirectoryEntry(userId: 'bram', directoryGroups: ['OU=Toezicht', 'OU=Handhaving']);
		$cees = new DirectoryEntry(userId: 'cees');
		$failures = [['row' => 3, 'reason' => 'no userName']];

		$snapshot = new DirectorySnapshot(entries: [$anja, $bram, $cees], complete: false, failures: $failures);

		$this->assertSame(['OU=Vergunningen', 'OU=Toezicht', 'OU=Handhaving'], $snapshot->getDirectoryGroups());
		$this->assertSame([$anja, $bram, $cees], $snapshot->getEntries());
		$this->assertFalse($snapshot->isComplete());
		$this->assertSame($failures, $snapshot->getFailures());
	}//end testDirectoryGroupsAreDistinctAcrossEntries()

	/**
	 * A run reads the configuration from its connection and starts as a confirmed-less live run.
	 *
	 * @return void
	 */
	public function testARunReadsItsConnectionsConfiguration(): void {
		$connection = new ObjectEntity();
		$connection->setObject(['name' => 'Directory', 'configuration' => ['mock' => true, 'mapping' => ['rules' => []]]]);

		$run = new DirectorySyncRun(connection: $connection, startedAt: 12.5);

		$this->assertSame(['mock' => true, 'mapping' => ['rules' => []]], $run->configuration());
		$this->assertSame($connection, $run->connection);
		$this->assertSame(12.5, $run->startedAt);
		$this->assertFalse($run->dryRun);
		$this->assertFalse($run->confirmRemovals);
		$this->assertSame([], $run->plan);
	}//end testARunReadsItsConnectionsConfiguration()

	/**
	 * A connection without a configuration gives an empty one.
	 *
	 * @return void
	 */
	public function testAConnectionWithoutConfigurationGivesAnEmptyOne(): void {
		$connection = new ObjectEntity();
		$connection->setObject(['name' => 'Directory']);

		$this->assertSame([], (new DirectorySyncRun(connection: $connection, startedAt: 0.0))->configuration());
	}//end testAConnectionWithoutConfigurationGivesAnEmptyOne()
}//end class
