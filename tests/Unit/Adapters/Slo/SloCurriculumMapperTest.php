<?php

/**
 * Unit tests for the SLO curriculum mapper.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-mapping-presets-name-learniqs-contract-fields-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\SloCurriculumMapper;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloYearAllocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Records carry exactly the preset's learniq fields and stable ids.
 */
class SloCurriculumMapperTest extends TestCase {
	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * @return SloCurriculumMapper
	 */
	private function mapper(): SloCurriculumMapper {
		return new SloCurriculumMapper(new SloYearAllocator());
	}//end mapper()

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function nodes(): array {
		return [
			[
				'sloUuid' => 'top-1', 'sloType' => 'LdkVakinhoud', 'code' => 'Opmaak', 'title' => 'Opmaak', 'description' => null,
				'parentSloUuid' => null, 'order' => 0, 'isLeaf' => false, 'niveaus' => [],
				'subjectKeys' => ['vlg-uuid', 'nederlands', 'top-1', 'opmaak'],
			],
			[
				'sloUuid' => 'leaf-1', 'sloType' => 'Doelniveau', 'code' => 'D1', 'title' => 'Een doel', 'description' => 'Uitleg',
				'parentSloUuid' => 'top-1', 'order' => 0, 'isLeaf' => true,
				'niveaus' => [['uuid' => 'n', 'title' => 'po'], ['uuid' => 'm', 'title' => 'groep 3-4']],
				'subjectKeys' => ['nederlands'],
			],
		];
	}//end nodes()

	/**
	 * @return void
	 */
	public function testCompetencyObjectCarriesExactlyThePresetKeys(): void {
		$registry = new SloCurriculumPresetRegistry();
		$mapper = $this->mapper();
		$framework = $mapper->frameworkUuid(self::TENANT, 'leerdoelenkaarten', 'root-1');

		$records = $mapper->competencyRecords(
			$this->nodes(),
			['frameworkUuid' => $framework, 'tenantId' => self::TENANT, 'yearNiveaus' => [], 'subjectCourseIds' => [], 'subjectFrom' => 'root', 'rootSubjectKeys' => []],
			$registry->competencyMapping()
		);

		$this->assertCount(2, $records);
		foreach ($records as $record) {
			$this->assertSame(array_keys($registry->competencyMapping()), array_keys($record['object']));
			$this->assertSame('learniq', $record['register']);
			$this->assertSame('competency', $record['schema']);
			$this->assertSame(self::TENANT, $record['object']['tenant_id']);
			$this->assertTrue(Uuid::isValid($record['uuid']));
			$this->assertSame(64, strlen($record['originHash']));
		}

		$this->assertNull($records[0]['object']['parentId']);
		$this->assertSame($records[0]['uuid'], $records[1]['object']['parentId']);
		$this->assertSame(['groep 3', 'groep 4'], $records[1]['object']['applicableYears']);
		$this->assertSame([], $records[0]['object']['applicableYears']);
		$this->assertSame('leaf-1', $records[1]['originId']);
		$this->assertNull($records[0]['object']['description']);
	}//end testCompetencyObjectCarriesExactlyThePresetKeys()

	/**
	 * @return void
	 */
	public function testIdsAreStableAndScopedByTenantSetAndRoot(): void {
		$mapper = $this->mapper();
		$first = $mapper->frameworkUuid(self::TENANT, 'fo-kerndoelen', 'root');

		$this->assertSame($first, $mapper->frameworkUuid(self::TENANT, 'fo-kerndoelen', 'root'));
		$this->assertNotSame($first, $mapper->frameworkUuid('00000000-0000-4000-8000-000000000002', 'fo-kerndoelen', 'root'));
		$this->assertNotSame($first, $mapper->frameworkUuid(self::TENANT, 'examenprogramma', 'root'));
		$this->assertNotSame($first, $mapper->frameworkUuid(self::TENANT, 'fo-kerndoelen', 'other-root'));
		$this->assertSame($mapper->frameworkUuid(self::TENANT, 'x', null), $mapper->frameworkUuid(self::TENANT, 'x', ''));
		$this->assertSame($mapper->competencyUuid($first, 'n'), $mapper->competencyUuid($first, 'n'));
	}//end testIdsAreStableAndScopedByTenantSetAndRoot()

	/**
	 * @return void
	 */
	public function testSubjectOnlyOnTopLevelAndOnlyFromTheMap(): void {
		$mapper = $this->mapper();
		$course = '00000000-0000-4000-8000-0000000000c1';
		$context = [
			'frameworkUuid' => $mapper->frameworkUuid(self::TENANT, 's', 'r'), 'tenantId' => self::TENANT, 'yearNiveaus' => [],
			'subjectCourseIds' => ['nederlands' => $course], 'subjectFrom' => 'node', 'rootSubjectKeys' => [],
		];
		$mapping = (new SloCurriculumPresetRegistry())->competencyMapping();

		$records = $mapper->competencyRecords($this->nodes(), $context, $mapping);
		$this->assertSame($course, $records[0]['object']['subjectId']);
		$this->assertNull($records[1]['object']['subjectId']);

		$context['subjectFrom'] = 'root';
		$records = $mapper->competencyRecords($this->nodes(), $context, $mapping);
		$this->assertNull($records[0]['object']['subjectId'], 'root keys are empty, so no subject');

		$context['rootSubjectKeys'] = ['nederlands'];
		$records = $mapper->competencyRecords($this->nodes(), $context, $mapping);
		$this->assertSame($course, $records[0]['object']['subjectId']);
	}//end testSubjectOnlyOnTopLevelAndOnlyFromTheMap()

	/**
	 * @return void
	 */
	public function testApplyCopiesPathsAndPassesLiterals(): void {
		$output = $this->mapper()->apply(
			['a' => 'x', 'b' => 'not-a-field', 'c' => 3, 'd' => ['k' => 'v'], 'e' => 'nullField'],
			['x' => 'copied', 'nullField' => null]
		);

		$this->assertSame(['a' => 'copied', 'b' => 'not-a-field', 'c' => 3, 'd' => ['k' => 'v'], 'e' => null], $output);
	}//end testApplyCopiesPathsAndPassesLiterals()

	/**
	 * @return void
	 */
	public function testFrameworkRecordUsesTheFrameworkPreset(): void {
		$registry = new SloCurriculumPresetRegistry();
		$mapper = $this->mapper();
		$uuid = $mapper->frameworkUuid(self::TENANT, 'examenprogramma', 'r');

		$record = $mapper->frameworkRecord(
			$uuid,
			[
				'name' => 'Examenprogramma Tekenen vwo', 'sourceAuthority' => 'slo-eindtermen', 'sourceRef' => 'https://x',
				'edition' => '2020', 'level' => 'vo', 'description' => 'd', 'proficiencyLevels' => [], 'tenantId' => self::TENANT,
			],
			$registry->frameworkMapping(),
			'r'
		);

		$this->assertSame('competency-framework', $record['schema']);
		$this->assertSame($uuid, $record['uuid']);
		$this->assertSame(array_keys($registry->frameworkMapping()), array_keys($record['object']));
		$this->assertSame(self::TENANT, $record['object']['tenant_id']);
		$this->assertSame($mapper->originHash($record['object']), $record['originHash']);
	}//end testFrameworkRecordUsesTheFrameworkPreset()
}//end class
