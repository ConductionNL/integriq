<?php

/**
 * The staff-to-untis set: humaniq employees and approved leave, run through
 * the real MappingService, compared with the Untis requests they must become.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use JWadhams\JsonLogic;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\PersonalDataSetGuard;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers lib/Settings/configurations/staff-to-untis.json.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/tasks.md#task-3
 */
final class StaffToUntisSetTest extends TestCase {

	/**
	 * The Untis id of the teacher the employee fixture was pushed as.
	 */
	private const UNTIS_TEACHER_ID = '4711';

	/**
	 * The humaniq id of the employee fixture.
	 */
	private const EMPLOYEE_ID = '6b0c9a52-3f0e-4d43-9a1e-2f8f3c1d7a10';

	/**
	 * Fields of a humaniq Employee or LeaveRequest that must never reach Untis.
	 *
	 * @var string[]
	 */
	private const NEVER_SENT = [
		'999993653',
		'NL91ABNA0417164300',
		'A. van den Berg',
		'4321.5',
		'1988-04-12',
		'anna@example.org',
		'+31612345678',
		'Schoolstraat',
		'zwangerschapsverlof',
		'Bevalling',
		'doc-778',
	];

	/**
	 * The packaged set.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/configurations/staff-to-untis.json';

		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end set()

	/**
	 * A fixture under tests/fixtures/untis.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture(string $file): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/untis/' . $file), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * Map a fixture with one of the set's mappings through the real MappingService.
	 *
	 * @param string      $slug      The mapping slug.
	 * @param string      $input     The fixture file.
	 * @param string|null $teacherId The Untis teacher id the contract lookup answers.
	 *
	 * @return array<string, mixed>
	 */
	private function map(string $slug, string $input, ?string $teacherId = self::UNTIS_TEACHER_ID): array {
		$contracts = $this->createMock(originalClassName: SynchronizationContractService::class);
		$contracts->method('findTargetIdByOriginId')->willReturnCallback(
			function (string $originId) use ($teacherId): ?string {
				if ($originId === self::EMPLOYEE_ID) {
					return $teacherId;
				}

				return null;
			}
		);

		$service = new MappingService(
			loader: new ArrayLoader(templates: []),
			callService: $this->createMock(originalClassName: CallService::class),
			fileService: $this->createMock(originalClassName: FileService::class),
			objectService: $this->createMock(originalClassName: ObjectService::class),
			orObjectService: $this->createMock(originalClassName: ORObjectService::class),
			synchronizationContractService: $contracts,
		);

		return $service->executeMapping(mapping: $this->set()['components']['mappings'][$slug], input: $this->fixture(file: $input));
	}//end map()

	/**
	 * Whether a synchronization's conditions hold for an object, as the engine evaluates a list of groups.
	 *
	 * @param string               $slug   The synchronization slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return bool
	 */
	private function conditionsHold(string $slug, array $object): bool {
		foreach ($this->set()['components']['synchronizations'][$slug]['conditions'] as $group) {
			if (JsonLogic::apply($group, $object) === false) {
				return false;
			}
		}

		return true;
	}//end conditionsHold()

	/**
	 * A new employee becomes exactly the recorded Untis Teacher Management v2 create body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function testANewEmployeeBecomesTheRecordedTeacherCreate(): void {
		$sent = $this->map(slug: 'staff-to-untis-teacher', input: 'employee-new-teacher.json');

		$this->assertSame(expected: $this->fixture(file: 'untis-teacher-create.json'), actual: $sent);
	}//end testANewEmployeeBecomesTheRecordedTeacherCreate()

	/**
	 * A leaver is sent inactive with the exit date, never as a deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function testALeaverIsMadeInactiveNotDeleted(): void {
		$sent = $this->map(slug: 'staff-to-untis-teacher', input: 'employee-leaver.json');
		$this->assertSame(expected: $this->fixture(file: 'untis-teacher-leaver.json'), actual: $sent);

		$teachers = $this->set()['components']['synchronizations']['staff-to-untis-teachers'];
		$this->assertSame(expected: ['CREATE', 'UPDATE'], actual: $teachers['sourceConfig']['triggerOnlyOnEvents']);
		$this->assertArrayNotHasKey(key: 'deleteWhenConditionsFail', array: $teachers['targetConfig']);
	}//end testALeaverIsMadeInactiveNotDeleted()

	/**
	 * Neither the BSN nor the IBAN, nor any other personal field outside the allow-list, reaches Untis.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
	 */
	public function testABsnAndAnIbanNeverLeaveHumaniq(): void {
		$sent = json_encode([
			$this->map(slug: 'staff-to-untis-teacher', input: 'employee-new-teacher.json'),
			$this->map(slug: 'staff-to-untis-absence', input: 'leave-request-approved.json'),
		], JSON_THROW_ON_ERROR);

		foreach (self::NEVER_SENT as $value) {
			$this->assertStringNotContainsString(needle: $value, haystack: $sent);
		}
	}//end testABsnAndAnIbanNeverLeaveHumaniq()

	/**
	 * The set is marked as personal data, every mapping is an allow-list, and the guard lets it import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
	 */
	public function testTheSetIsPersonalDataWithAllowListMappingsOnly(): void {
		$set = $this->set();

		$this->assertTrue(condition: PersonalDataSetGuard::carriesPersonalData(document: $set));
		$this->assertCount(expectedCount: 2, haystack: $set['components']['mappings']);
		foreach ($set['components']['mappings'] as $mapping) {
			$this->assertFalse(condition: $mapping['passThrough']);
		}

		$this->assertSame(expected: [], actual: PersonalDataSetGuard::refusals(document: $set));
	}//end testTheSetIsPersonalDataWithAllowListMappingsOnly()

	/**
	 * A pass-through mapping slipped into the set refuses the install, naming the mapping.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
	 */
	public function testAPassThroughMappingInTheSetRefusesTheInstall(): void {
		$set = $this->set();
		$set['components']['mappings']['staff-to-untis-teacher']['passThrough'] = true;

		$refusals = PersonalDataSetGuard::refusals(document: $set);

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: '"staff-to-untis-teacher"', haystack: $refusals[0]);
	}//end testAPassThroughMappingInTheSetRefusesTheInstall()

	/**
	 * Approved leave becomes the recorded absence: the teacher's Untis id, the dates, no type or note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
	 */
	public function testApprovedLeaveBecomesTheRecordedAbsence(): void {
		$leave = $this->fixture(file: 'leave-request-approved.json');

		$this->assertTrue(condition: $this->conditionsHold(slug: 'staff-to-untis-absences', object: $leave));
		$sent = $this->map(slug: 'staff-to-untis-absence', input: 'leave-request-approved.json');
		$this->assertSame(expected: $this->fixture(file: 'untis-teacher-absence-create.json'), actual: $sent);
	}//end testApprovedLeaveBecomesTheRecordedAbsence()

	/**
	 * A neutral reason an administrator fills in is sent as an integer; left empty, none is sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
	 */
	public function testTheNeutralReasonIsTheAdministratorsChoice(): void {
		$set = $this->set();
		$this->assertSame(expected: '', actual: $set['components']['mappings']['staff-to-untis-absence']['mapping']['reasonId']);
		$this->assertArrayNotHasKey(key: 'reasonId', array: $this->map(slug: 'staff-to-untis-absence', input: 'leave-request-approved.json'));
	}//end testTheNeutralReasonIsTheAdministratorsChoice()

	/**
	 * Leave of an employee Untis does not know yet sends no teacher id, so Untis refuses it visibly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
	 */
	public function testLeaveOfAnUnknownTeacherSendsNoTeacherId(): void {
		$sent = $this->map(slug: 'staff-to-untis-absence', input: 'leave-request-approved.json', teacherId: null);

		$this->assertArrayNotHasKey(key: 'teacherId', array: $sent);
	}//end testLeaveOfAnUnknownTeacherSendsNoTeacherId()

	/**
	 * Only an approved request is pushed; a withdrawn approval deletes the absence it made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
	 */
	public function testOnlyApprovedLeaveIsSentAndAWithdrawalDeletes(): void {
		$leave = $this->fixture(file: 'leave-request-approved.json');
		foreach (['draft', 'submitted', 'manager-approved', 'rejected'] as $status) {
			$this->assertFalse(condition: $this->conditionsHold(slug: 'staff-to-untis-absences', object: ['status' => $status] + $leave), message: $status);
		}

		$absences = $this->set()['components']['synchronizations']['staff-to-untis-absences'];
		$this->assertTrue(condition: $absences['targetConfig']['deleteWhenConditionsFail']);
		$this->assertArrayNotHasKey(key: 'triggerOnlyOnEvents', array: ($absences['sourceConfig'] ?? []));
	}//end testOnlyApprovedLeaveIsSentAndAWithdrawalDeletes()

	/**
	 * Both synchronizations read humaniq and write to the set's Untis source on the documented paths.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function testTheSynchronizationsWriteToTheDocumentedUntisPaths(): void {
		$set = $this->set();
		$syncs = $set['components']['synchronizations'];

		$this->assertSame(expected: $set['synchronizations'], actual: array_keys($syncs));
		$this->assertSame(expected: $set['mappings'], actual: array_keys($set['components']['mappings']));

		$this->assertSame(expected: 'humaniq/Employee', actual: $syncs['staff-to-untis-teachers']['sourceId']);
		$this->assertSame(expected: '/api/rest/extern/v2/teachers', actual: $syncs['staff-to-untis-teachers']['targetConfig']['endpoint']);
		$this->assertSame(expected: '/api/rest/extern/v2/teachers', actual: $syncs['staff-to-untis-teachers']['targetConfig']['updateEndpoint']);
		$this->assertSame(expected: 'teachers.0.id', actual: $syncs['staff-to-untis-teachers']['targetConfig']['idPosition']);

		$this->assertSame(expected: 'humaniq/LeaveRequest', actual: $syncs['staff-to-untis-absences']['sourceId']);
		$this->assertSame(expected: '/api/rest/extern/v1/teacher-absence', actual: $syncs['staff-to-untis-absences']['targetConfig']['endpoint']);

		foreach ($syncs as $slug => $sync) {
			$this->assertSame(expected: 'register/schema', actual: $sync['sourceType'], message: $slug);
			$this->assertSame(expected: 'api', actual: $sync['targetType'], message: $slug);
			$this->assertArrayHasKey(key: $sync['targetId'], array: $set['components']['sources'], message: $slug);
			$this->assertArrayHasKey(key: $sync['sourceTargetMapping'], array: $set['components']['mappings'], message: $slug);
		}
	}//end testTheSynchronizationsWriteToTheDocumentedUntisPaths()

	/**
	 * The Untis source is dormant, reaches the central gateway and keeps its password in the broker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function testTheUntisSourceIsDormantAndBrokered(): void {
		$set = $this->set();
		$source = $set['components']['sources']['staff-to-untis-source'];

		$this->assertFalse(condition: $source['isEnabled']);
		$this->assertSame(expected: 'https://api.webuntis.com/WebUntis', actual: $source['location']);
		$secret = ['credentialRef' => ['credentialName' => 'staff-to-untis-client-secret']];
		$this->assertSame(expected: $secret, actual: $source['configuration']['authentication']['client_secret']);
		$this->assertSame(expected: 'basic_auth', actual: $source['configuration']['authentication']['authentication']);
		$this->assertStringContainsString(needle: '/WebUntis/api/sso/v3/', haystack: $source['configuration']['authentication']['tokenUrl']);
		$this->assertStringContainsString(needle: 'developer.untis.com', haystack: $set['description']);
	}//end testTheUntisSourceIsDormantAndBrokered()
}//end class
