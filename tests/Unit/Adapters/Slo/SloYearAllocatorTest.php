<?php

/**
 * Unit tests for the SLO year allocator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloYearAllocator;
use PHPUnit\Framework\TestCase;

/**
 * Years come only from SLO niveaus that name a year.
 */
class SloYearAllocatorTest extends TestCase {
	/**
	 * @return void
	 */
	public function testGroepBandBecomesTwoYears(): void {
		$years = (new SloYearAllocator())->allocate([['uuid' => 'x', 'title' => 'po'], ['title' => 'groep 3-4']], []);

		$this->assertSame(['groep 3', 'groep 4'], $years);
	}//end testGroepBandBecomesTwoYears()

	/**
	 * @return void
	 */
	public function testPhasesSchoolTypesAndReferenceLevelsNameNoYear(): void {
		$allocator = new SloYearAllocator();

		foreach (['po', 'fase 3', 'ob vo', 'bb havo', '1F', 'A2', 'vso vo', ''] as $title) {
			$this->assertSame([], $allocator->labelsFromTitle($title), $title);
		}
	}//end testPhasesSchoolTypesAndReferenceLevelsNameNoYear()

	/**
	 * @return void
	 */
	public function testVoLeerjarenBecomeLeerjaarLabels(): void {
		$allocator = new SloYearAllocator();

		$this->assertSame(['leerjaar 2'], $allocator->labelsFromTitle('vwo, 2'));
		$this->assertSame(['leerjaar 3'], $allocator->labelsFromTitle('havo 3'));
		$this->assertSame(['leerjaar 4'], $allocator->labelsFromTitle('vmbo tl, 4'));
		$this->assertSame(['groep 8'], $allocator->labelsFromTitle('Groep 8'));
		$this->assertSame([], $allocator->labelsFromTitle('groep 6-5'));
	}//end testVoLeerjarenBecomeLeerjaarLabels()

	/**
	 * The seeded table resolves by uuid first, whatever the title says.
	 *
	 * @return void
	 */
	public function testSeededTableResolvesByUuidFirst(): void {
		$table = (new SloCurriculumPresetRegistry())->yearNiveaus();

		$this->assertCount(39, $table);
		// SLO niveau "groep 1-2" (curriculum-basis@2026.7).
		$this->assertSame(['groep 1', 'groep 2'], $table['e222c093-f0c6-4895-9dfb-c08eafb27aef']);

		$years = (new SloYearAllocator())->allocate(
			[['uuid' => 'e222c093-f0c6-4895-9dfb-c08eafb27aef', 'title' => 'renamed upstream']],
			$table
		);
		$this->assertSame(['groep 1', 'groep 2'], $years);
	}//end testSeededTableResolvesByUuidFirst()

	/**
	 * @return void
	 */
	public function testLabelsAreUniqueAndOrdered(): void {
		$years = (new SloYearAllocator())->allocate(
			[['title' => 'havo 2'], ['title' => 'groep 8'], ['title' => 'groep 3'], ['title' => 'groep 3-4'], ['title' => 'vwo, 1']],
			[]
		);

		$this->assertSame(['groep 3', 'groep 4', 'groep 8', 'leerjaar 1', 'leerjaar 2'], $years);
	}//end testLabelsAreUniqueAndOrdered()
}//end class
