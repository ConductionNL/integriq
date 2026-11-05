<?php

/**
 * Unit tests for the Edurep CQL builder.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepCqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Filters map to their indexes and values cannot change the query.
 */
class EdurepCqlBuilderTest extends TestCase {
	/**
	 * @return void
	 */
	public function testALevelAndLicenceFilterNarrowTheQuery(): void {
		$cql = (new EdurepCqlBuilder())->search(query: 'betoog', filters: ['levels' => ['VO'], 'licenses' => ['CC-BY-4.0', 'CC-BY-SA-4.0']]);

		$this->assertSame(
			'betoog AND lom.educational.context=VO AND (lom.rights.copyrightandotherrestrictions=cc-by-40 OR '
			. 'lom.rights.copyrightandotherrestrictions=cc-by-sa-40) AND lom.general.language=nl',
			$cql
		);
	}//end testALevelAndLicenceFilterNarrowTheQuery()

	/**
	 * @return void
	 */
	public function testWordsAreEscapedSoTheyCannotInjectCql(): void {
		$cql = (new EdurepCqlBuilder())->search(query: 'a OR meta.repository.id="x" bet*', filters: ['language' => '']);

		$this->assertSame('a AND "OR" AND "meta.repository.id=\"x\"" AND "bet\*"', $cql);
	}//end testWordsAreEscapedSoTheyCannotInjectCql()

	/**
	 * @return void
	 */
	public function testFormatsCollectionsAndExclusionsMapToTheirIndexes(): void {
		$cql = (new EdurepCqlBuilder())->search(
			query: '',
			filters: ['formats' => ['application/pdf'], 'collection' => ['Schooltv'], 'excludeCollections' => ['KlasCement'], 'language' => 'en']
		);

		$this->assertSame(
			'lom.technical.format="application/pdf" AND meta.repository.id=Schooltv AND lom.general.language=en NOT meta.repository.id=KlasCement',
			$cql
		);
	}//end testFormatsCollectionsAndExclusionsMapToTheirIndexes()

	/**
	 * @return void
	 */
	public function testAnUnknownLicenceCodeIsDropped(): void {
		$cql = (new EdurepCqlBuilder())->search(query: 'x', filters: ['licenses' => ['GPL']]);

		$this->assertSame('x AND lom.general.language=nl', $cql);
	}//end testAnUnknownLicenceCodeIsDropped()

	/**
	 * @return void
	 */
	public function testFindQuotesTheIdentifierOrItsLastPathSegment(): void {
		$builder = new EdurepCqlBuilder();

		$this->assertSame('"urn:wikiwijsmaken:194413"', $builder->find(id: 'urn:wikiwijsmaken:194413'));
		$this->assertSame('"WO_NTR_20306689"', $builder->find(id: '/NTR/POMS/WO_NTR_20306689'));
	}//end testFindQuotesTheIdentifierOrItsLastPathSegment()
}//end class
