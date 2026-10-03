<?php

/**
 * integriq:events:purge-recursion removes the storm's rows and nothing else.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-the-storm-s-rows-shall-be-removable-without-touching-genuine-events
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\PurgeEventRecursion;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Events generated from events go; genuine events stay; their orphans go.
 */
class PurgeEventRecursionTest extends TestCase {

	/**
	 * Every deleteObjects() uuid list, in call order.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $deleteCalls = [];

	/**
	 * An entity with the given uuid and payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;
	}//end entity()

	/**
	 * Only the three generated sources are recursion.
	 *
	 * @return void
	 */
	public function testOnlyEventsGeneratedFromEventsAreRecursion(): void {
		$this->assertTrue(PurgeEventRecursion::isRecursion(['source' => '/objects/com.nextcloud.openregister.object.created']));
		$this->assertTrue(PurgeEventRecursion::isRecursion(['source' => '/objects/com.nextcloud.openregister.object.updated']));
		$this->assertTrue(PurgeEventRecursion::isRecursion(['source' => '/objects/com.nextcloud.openregister.object.deleted']));
		$this->assertFalse(PurgeEventRecursion::isRecursion(['source' => '/objects/meeting']));
		$this->assertFalse(PurgeEventRecursion::isRecursion(['source' => '/objects/']));
		$this->assertFalse(PurgeEventRecursion::isRecursion([]));
	}//end testOnlyEventsGeneratedFromEventsAreRecursion()

	/**
	 * A message is orphaned only when the event it names is not kept.
	 *
	 * @return void
	 */
	public function testAMessageIsOrphanedOnlyWhenItsEventIsGone(): void {
		$kept = ['e-real' => true];

		$this->assertFalse(PurgeEventRecursion::isOrphan(['event' => 'e-real'], $kept));
		$this->assertTrue(PurgeEventRecursion::isOrphan(['event' => 'e-storm'], $kept));
		// A message that names no event cannot be judged, so it stays.
		$this->assertFalse(PurgeEventRecursion::isOrphan(['event' => ''], $kept));
		$this->assertFalse(PurgeEventRecursion::isOrphan([], $kept));
	}//end testAMessageIsOrphanedOnlyWhenItsEventIsGone()

	/**
	 * Run the command over a fixed set of events and messages.
	 *
	 * @param array<int, string> $args    Command options, e.g. ['--apply' => true].
	 * @param bool               $shortBy Make OpenRegister delete one row fewer than asked.
	 *
	 * @return CommandTester
	 */
	private function runCommand(array $args, bool $shortBy = false): CommandTester {
		$events = [
			$this->entity('e-real', ['source' => '/objects/meeting']),
			$this->entity('e-storm-1', ['source' => '/objects/com.nextcloud.openregister.object.created']),
			$this->entity('e-storm-2', ['source' => '/objects/com.nextcloud.openregister.object.updated']),
		];
		$messages = [
			$this->entity('m-real', ['event' => 'e-real']),
			$this->entity('m-storm', ['event' => 'e-storm-1']),
			$this->entity('m-old', ['event' => 'e-long-gone']),
		];

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($events, $messages): array {
				$rows = $events;
				if (($config['filters']['schema'] ?? null) === 'event_message') {
					$rows = $messages;
				}

				return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
			}
		);
		$objects->method('deleteObjects')->willReturnCallback(
			function (array $uuids = []) use ($shortBy): array {
				$this->deleteCalls[] = $uuids;
				$deleted = $uuids;
				if ($shortBy === true) {
					$deleted = array_slice($uuids, 1);
				}

				return ['deleted_uuids' => $deleted, 'skipped_uuids' => []];
			}
		);

		$tester = new CommandTester(new PurgeEventRecursion($objects, 2));
		$tester->execute($args);

		return $tester;
	}//end runCommand()

	/**
	 * A dry run reports the plan and deletes nothing.
	 *
	 * @return void
	 */
	public function testADryRunDeletesNothing(): void {
		$tester = $this->runCommand([]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame([], $this->deleteCalls);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Events:          3 (2 generated from events, 1 genuine)', $display);
		$this->assertStringContainsString('Orphan messages: 2', $display);
	}//end testADryRunDeletesNothing()

	/**
	 * --apply deletes the generated events and the orphaned messages only.
	 *
	 * @return void
	 */
	public function testApplyDeletesTheStormAndItsOrphans(): void {
		$tester = $this->runCommand(['--apply' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$deleted = array_merge(...$this->deleteCalls);
		$this->assertEqualsCanonicalizing(['e-storm-1', 'e-storm-2', 'm-storm', 'm-old'], $deleted);
		$this->assertStringContainsString('Deleted:         2 event(s), 2 message(s)', $tester->getDisplay());
	}//end testApplyDeletesTheStormAndItsOrphans()

	/**
	 * A shortfall between plan and result fails the run.
	 *
	 * @return void
	 */
	public function testAShortfallFails(): void {
		$tester = $this->runCommand(['--apply' => true], true);

		$this->assertSame(1, $tester->getStatusCode());
	}//end testAShortfallFails()
}//end class
