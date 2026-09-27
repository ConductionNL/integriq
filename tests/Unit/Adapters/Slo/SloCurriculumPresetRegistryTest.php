<?php

/**
 * Unit tests for the SLO curriculum preset registry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Exception\UnknownSloCurriculumSetException;
use PHPUnit\Framework\TestCase;

/**
 * The registry reads the real register.d fragment.
 */
class SloCurriculumPresetRegistryTest extends TestCase {
	/**
	 * @return void
	 */
	public function testSeededProfilesAreListed(): void {
		$registry = new SloCurriculumPresetRegistry();
		$keys = $registry->setKeys();

		foreach (['fo-kerndoelen', 'fo-examenprogramma', 'kerndoelen-2006-po', 'kerndoelen-2006-onderbouw-vo', 'examenprogramma', 'leerdoelenkaarten'] as $key) {
			$this->assertContains($key, $keys);
		}

		$described = array_column($registry->describeSets(), null, 'key');
		$this->assertSame('slo-kerndoelen', $described['fo-kerndoelen']['sourceAuthority']);
		$this->assertSame('po', $described['kerndoelen-2006-po']['level']);
		$this->assertSame('aggregate', $described['kerndoelen-2006-po']['framework']);
		$this->assertNull($described['fo-kerndoelen']['level']);
	}//end testSeededProfilesAreListed()

	/**
	 * @return void
	 */
	public function testSetFillsDefaults(): void {
		$profile = (new SloCurriculumPresetRegistry())->set('examenprogramma');

		$this->assertSame('examenprogramma', $profile['key']);
		$this->assertSame('versie', $profile['editionFrom']);
		$this->assertSame(['perPage' => 1000], $profile['discover']['query']);
		$this->assertSame([], $profile['leafNiveauFilter']);
	}//end testSetFillsDefaults()

	/**
	 * @return void
	 */
	public function testUnknownSetNamesTheKnownKeys(): void {
		try {
			(new SloCurriculumPresetRegistry())->set('mbo-kwalificatiedossiers');
			$this->fail('An unknown set must throw.');
		} catch (UnknownSloCurriculumSetException $exception) {
			$this->assertSame('mbo-kwalificatiedossiers', $exception->getSetKey());
			$this->assertStringContainsString('fo-kerndoelen', $exception->getMessage());
		}
	}//end testUnknownSetNamesTheKnownKeys()

	/**
	 * @return void
	 */
	public function testMappingsAttributionAndScale(): void {
		$registry = new SloCurriculumPresetRegistry();

		$this->assertSame('tenantId', $registry->frameworkMapping()['tenant_id']);
		$this->assertSame('applicableYears', $registry->competencyMapping()['applicableYears']);
		$this->assertSame('CC BY 4.0', $registry->attribution()['licence']);
		$this->assertSame(['introduce', 'practise', 'master'], array_column($registry->proficiencyLevels(), 'levelId'));
		$this->assertSame('slo-curriculum', $registry->source()['@self']['slug']);
	}//end testMappingsAttributionAndScale()

	/**
	 * A missing or malformed fragment leaves the registry empty, and asking
	 * for a mapping then fails loudly.
	 *
	 * @return void
	 */
	public function testMissingFragmentIsEmptyAndMappingThrows(): void {
		$registry = new SloCurriculumPresetRegistry(__DIR__ . '/does-not-exist.json');

		$this->assertSame([], $registry->setKeys());
		$this->assertSame([], $registry->attribution());
		$this->assertSame([], $registry->proficiencyLevels());
		$this->assertSame([], $registry->yearNiveaus());
		$this->assertSame([], $registry->source());

		$this->expectException(SloCurriculumException::class);
		$registry->competencyMapping();
	}//end testMissingFragmentIsEmptyAndMappingThrows()

	/**
	 * @return void
	 */
	public function testMalformedMembersDegradeToEmpty(): void {
		$path = tempnam(sys_get_temp_dir(), 'slo');
		file_put_contents(
			$path,
			json_encode(
				[
					'components' => [
						'objects' => [
							'not an object',
							[
								'@self' => ['schema' => 'source', 'slug' => 'slo-curriculum'],
								'configuration' => [
									'sets' => ['broken' => 'x', 'ok' => ['discover' => 'x']],
									'attribution' => 'x',
									'proficiencyLevels' => 'x',
									'yearNiveaus' => ['a' => 'x', 'b' => ['groep 1']],
								],
							],
							['@self' => ['schema' => 'mapping', 'slug' => 'm'], 'mapping' => ['a' => 'b']],
						],
					],
				]
			)
		);

		try {
			$registry = new SloCurriculumPresetRegistry($path);
			$this->assertSame(['broken', 'ok'], $registry->setKeys());
			$this->assertSame('', $registry->set('ok')['discover']['path']);
			$this->assertSame([], $registry->attribution());
			$this->assertSame([], $registry->proficiencyLevels());
			$this->assertSame(['b' => ['groep 1']], $registry->yearNiveaus());
			$this->assertSame(['a' => 'b'], $registry->mapping('m'));
			$this->expectException(UnknownSloCurriculumSetException::class);
			$registry->set('broken');
		} finally {
			unlink($path);
		}
	}//end testMalformedMembersDegradeToEmpty()
}//end class
