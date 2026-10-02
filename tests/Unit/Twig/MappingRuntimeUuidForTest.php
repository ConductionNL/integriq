<?php

/**
 * The `uuidFor()` mapping function: one name, one uuid, on every run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Twig;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Twig\MappingRuntime;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers MappingRuntime::uuidFor() and its registration as a mapping function.
 */
final class MappingRuntimeUuidForTest extends TestCase {

	/**
	 * The same name gives the same uuid, another name another one, and the value is a UUID v5.
	 *
	 * @return void
	 */
	public function testOneNameGivesOneStableVersion5Uuid(): void {
		$first = MappingRuntime::uuidFor(name: 'course-marketplace:go1:course:1830612');
		$again = MappingRuntime::uuidFor(name: 'course-marketplace:go1:course:1830612');
		$other = MappingRuntime::uuidFor(name: 'course-marketplace:go1:lesson:1830612');

		$this->assertSame($first, $again);
		$this->assertNotSame($first, $other);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);

		// Pinned, so a changed namespace cannot slip through: every object a set
		// already wrote would otherwise be written again under a new id.
		$this->assertSame('ae04735e-3ed2-5319-9e1b-6fdf977c3271', $first);
	}//end testOneNameGivesOneStableVersion5Uuid()

	/**
	 * An empty name is refused rather than answered with the namespace's one shared uuid.
	 *
	 * @return void
	 */
	public function testAnEmptyNameIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		MappingRuntime::uuidFor(name: '  ');
	}//end testAnEmptyNameIsRefused()

	/**
	 * A mapping reaches it by name.
	 *
	 * @return void
	 */
	public function testAMappingCallsItByName(): void {
		$service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

		$mapped = $service->executeMapping(
			mapping: ['mapping' => ['id' => "{{ uuidFor('course-marketplace:go1:course:' ~ id) }}"]],
			input: ['id' => 1830612]
		);

		$this->assertSame(MappingRuntime::uuidFor(name: 'course-marketplace:go1:course:1830612'), $mapped['id']);
	}//end testAMappingCallsItByName()
}//end class
