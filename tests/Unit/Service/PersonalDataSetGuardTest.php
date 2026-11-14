<?php

/**
 * Unit tests for PersonalDataSetGuard — a configuration set marked as carrying
 * personal data imports only with allow-list mappings, and is refused, naming
 * the mapping, before anything is written.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Service\PersonalDataSetGuard;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the personal-data set guard.
 */
#[CoversClass(PersonalDataSetGuard::class)]
#[CoversClass(ConfigurationService::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
#[UsesClass(EndpointHandler::class)]
#[UsesClass(JobHandler::class)]
#[UsesClass(MappingHandler::class)]
#[UsesClass(RuleHandler::class)]
#[UsesClass(SourceHandler::class)]
#[UsesClass(SynchronizationHandler::class)]
class PersonalDataSetGuardTest extends TestCase {

	/**
	 * A set with an allow-list mapping and a pass-through one.
	 *
	 * @param array<string,mixed> $mark The personal-data mark, merged into the document.
	 *
	 * @return array<string,mixed> The document.
	 */
	private static function set(array $mark): array {
		return array_merge(
			[
				'info' => ['title' => 'Staff to timetable'],
				'components' => [
					'mappings' => [
						'employee-to-teacher' => ['mapping' => ['name' => '{{ givenName }} {{ familyName }}'], 'passThrough' => false],
						'leave-to-absence' => ['mapping' => ['startDate' => '{{ startDate }}']],
						'employee-copy' => ['mapping' => [], 'passThrough' => true, 'unset' => ['bsn', 'iban']],
					],
				],
			],
			$mark
		);
	}//end set()

	/**
	 * A marked set names every pass-through mapping; allow-list mappings pass.
	 *
	 * @return void
	 */
	public function testAMarkedSetNamesEveryPassThroughMapping(): void {
		$expected = [
			'The mapping "employee-copy" passes unmapped fields through, and this set carries personal data. '
			.'Name every field the target needs and set passThrough to false.',
		];

		$this->assertSame($expected, PersonalDataSetGuard::refusals(document: self::set(mark: ['personalData' => true])));
		$this->assertSame($expected, PersonalDataSetGuard::refusals(document: self::set(mark: ['info' => ['x-personalData' => true]])));
	}//end testAMarkedSetNamesEveryPassThroughMapping()

	/**
	 * An unmarked set, or a truthy non-boolean mark, is not checked.
	 *
	 * @return void
	 */
	public function testAnUnmarkedSetIsNotChecked(): void {
		$this->assertSame([], PersonalDataSetGuard::refusals(document: self::set(mark: [])));
		$this->assertSame([], PersonalDataSetGuard::refusals(document: self::set(mark: ['personalData' => 'yes'])));
		$this->assertFalse(PersonalDataSetGuard::carriesPersonalData(document: ['personalData' => 1]));
		$this->assertSame([], PersonalDataSetGuard::refusals(document: ['personalData' => true, 'components' => ['mappings' => 'none']]));
	}//end testAnUnmarkedSetIsNotChecked()

	/**
	 * Importing a marked set with a pass-through mapping is refused before anything is written.
	 *
	 * @return void
	 */
	public function testTheImportIsRefusedBeforeAnythingIsWritten(): void {
		$objects = $this->createMock(ORObjectService::class);
		$objects->expects($this->never())->method('saveObject');
		$registry = new SensitiveFieldRegistry();
		$service = new ConfigurationService(
			$objects,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			new EndpointHandler($objects, $registry),
			new SynchronizationHandler($objects, $registry),
			new MappingHandler($objects, $registry),
			new JobHandler($objects, $registry),
			new SourceHandler($objects, $registry),
			new RuleHandler($objects, $registry),
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The mapping "employee-copy" passes unmapped fields through');

		$service->importConfiguration(oas: self::set(mark: ['personalData' => true]));
	}//end testTheImportIsRefusedBeforeAnythingIsWritten()
}//end class
