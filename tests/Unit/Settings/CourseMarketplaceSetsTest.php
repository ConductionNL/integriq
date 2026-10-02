<?php

/**
 * The course marketplace sets, run through the real MappingService and checked
 * against learniq's own schemas.
 *
 * A mapping row whose output learniq's register refuses would pass a test that
 * only reads the fragment. This one runs each provider's three mappings over a
 * canned catalogue page, validates every object against the Course, Lesson and
 * LtiToolPlacement schemas copied from learniq's development branch, and checks
 * that the three objects of one course name each other.
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
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use JWadhams\JsonLogic;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers the three course marketplace sets in register.d.
 */
final class CourseMarketplaceSetsTest extends TestCase {

	/**
	 * The providers, by slug.
	 */
	private const PROVIDERS = ['go1', 'linkedin-learning', 'udemy-business'];

	/**
	 * The learniq schema each mapping kind writes.
	 */
	private const SCHEMA_OF = ['course' => 'Course', 'placement' => 'LtiToolPlacement', 'lesson' => 'Lesson'];

	/**
	 * The fragment's objects.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/course-marketplace-connectors.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		return $fragment['components']['objects'];
	}//end objects()

	/**
	 * One seeded object by schema and slug.
	 *
	 * @param string $schema The schema.
	 * @param string $slug   The slug.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function row(string $schema, string $slug): array {
		foreach ($this->objects() as $object) {
			if (($object['@self']['schema'] ?? '') === $schema && ($object['@self']['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		$this->fail(sprintf('No seeded %s row %s', $schema, $slug));
	}//end row()

	/**
	 * A provider's canned catalogue page, as the source answers it.
	 *
	 * @param string $provider The provider slug.
	 *
	 * @return array<int, array<string, mixed>> The courses on the page.
	 */
	private function catalogue(string $provider): array {
		$path = dirname(__DIR__, 2) . '/fixtures/course-marketplace/' . $provider . '/catalogue-page.json';
		$page = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$sync = $this->row(schema: 'synchronization', slug: 'course-marketplace-' . $provider . '-course');

		return $page[$sync['sourceConfig']['resultsPosition']];
	}//end catalogue()

	/**
	 * A learniq schema, copied from learniq's development branch.
	 *
	 * @param string $name The schema name.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function learniqSchema(string $name): array {
		$path = dirname(__DIR__, 2) . '/fixtures/course-marketplace/learniq-schemas.json';
		$schemas = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		return $schemas['schemas'][$name];
	}//end learniqSchema()

	/**
	 * The real MappingService with inert collaborators.
	 *
	 * @return MappingService The service.
	 */
	private function mappingService(): MappingService {
		return new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end mappingService()

	/**
	 * Every provider course becomes a Course, a Lesson and a placement learniq accepts, and they name each other.
	 *
	 * @return void
	 */
	public function testEveryProviderCourseBecomesThreeLinkedObjectsLearniqAccepts(): void {
		$mapper = $this->mappingService();
		foreach (self::PROVIDERS as $provider) {
			foreach ($this->catalogue(provider: $provider) as $course) {
				$mapped = [];
				foreach (self::SCHEMA_OF as $kind => $schema) {
					$mapping = $this->row(schema: 'mapping', slug: 'course-marketplace-' . $provider . '-' . $kind);
					if ($kind === 'placement') {
						// What the administrator fills in: the deployment this install registered with the provider.
						$mapping['mapping']['openconnectorDeploymentId'] = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
					}

					$mapped[$kind] = $mapper->executeMapping(mapping: $mapping, input: $course);
					$errors = RegisterSchemaValidator::errorsAgainst(schema: $this->learniqSchema(name: $schema), object: $mapped[$kind]);
					$this->assertSame([], $errors, sprintf('%s %s is refused by learniq %s', $provider, $kind, $schema));
				}

				$this->assertSame($mapped['course']['id'], $mapped['lesson']['courseId'], $provider . ': the lesson names its course');
				$this->assertSame($mapped['course']['id'], $mapped['placement']['courseId'], $provider . ': the placement names its course');
				$this->assertSame($mapped['lesson']['id'], $mapped['placement']['lessonId'], $provider . ': the placement names its lesson');
				$this->assertSame($mapped['placement']['id'], $mapped['lesson']['contentRef'], $provider . ': the lti lesson opens the placement');
				$this->assertSame('lti', $mapped['lesson']['contentType']);
				$this->assertSame(1, $mapped['lesson']['order']);
				$this->assertSame('resource-link', $mapped['placement']['launchMode']);
				$this->assertArrayNotHasKey('lifecycle', $mapped['course'], 'a re-run must not put a published course back in draft');
				$this->assertStringStartsWith(['go1' => 'GO1-', 'linkedin-learning' => 'LIL-', 'udemy-business' => 'UDB-'][$provider], $mapped['course']['code']);
			}
		}
	}//end testEveryProviderCourseBecomesThreeLinkedObjectsLearniqAccepts()

	/**
	 * A second run over the same course derives the same ids, so it updates instead of duplicating.
	 *
	 * @return void
	 */
	public function testASecondRunDerivesTheSameIdsAndDifferentCoursesDoNot(): void {
		$mapper = $this->mappingService();
		$mapping = $this->row(schema: 'mapping', slug: 'course-marketplace-linkedin-learning-course');
		[$first, $second] = $this->catalogue(provider: 'linkedin-learning');

		$once = $mapper->executeMapping(mapping: $mapping, input: $first);
		$renamed = $first;
		$renamed['title']['value'] = 'De AVG voor leidinggevenden (2026)';
		$again = $mapper->executeMapping(mapping: $mapping, input: $renamed);

		$this->assertSame($once['id'], $again['id']);
		$this->assertSame('De AVG voor leidinggevenden (2026)', $again['name']);
		$this->assertNotSame($once['id'], $mapper->executeMapping(mapping: $mapping, input: $second)['id']);

		// The same provider course id under another provider is another course.
		$go1 = $mapper->executeMapping(
			mapping: $this->row(schema: 'mapping', slug: 'course-marketplace-go1-course'),
			input: ['id' => 'x', 'title' => 'x', 'language' => 'nl']
		);
		$udemy = $mapper->executeMapping(
			mapping: $this->row(schema: 'mapping', slug: 'course-marketplace-udemy-business-course'),
			input: ['id' => 'x', 'title' => 'x', 'locale' => ['locale' => 'nl_NL']]
		);
		$this->assertNotSame($go1['id'], $udemy['id']);
	}//end testASecondRunDerivesTheSameIdsAndDifferentCoursesDoNot()

	/**
	 * A placement without its deployment is refused by learniq, so the seed cannot write one unnoticed.
	 *
	 * @return void
	 */
	public function testASeededPlacementWithoutADeploymentIsRefusedByLearniq(): void {
		$mapping = $this->row(schema: 'mapping', slug: 'course-marketplace-go1-placement');
		$this->assertSame('', $mapping['mapping']['openconnectorDeploymentId'], 'the deployment is the install\'s own and is not guessed');

		$mapping['mapping']['openconnectorDeploymentId'] = '';
		$mapped = $this->mappingService()->executeMapping(mapping: $mapping, input: $this->catalogue(provider: 'go1')[0]);
		$errors = RegisterSchemaValidator::errorsAgainst(schema: $this->learniqSchema(name: 'LtiToolPlacement'), object: $mapped);
		$this->assertNotSame([], $errors);
	}//end testASeededPlacementWithoutADeploymentIsRefusedByLearniq()

	/**
	 * A selection of language `nl` and the text `AVG` lets only Dutch AVG courses through.
	 *
	 * @return void
	 */
	public function testASelectionOfDutchAvgCoursesLetsOnlyThoseThrough(): void {
		$selections = [
			'go1' => ['and' => [['==' => [['var' => 'language'], 'nl']], ['in' => ['AVG', ['var' => 'title']]]]],
			'linkedin-learning' => ['and' => [['==' => [['var' => 'title.locale.language'], 'nl']], ['in' => ['AVG', ['var' => 'title.value']]]]],
			'udemy-business' => ['and' => [['==' => [['substr' => [['var' => 'locale.locale'], 0, 2]], 'nl']], ['in' => ['AVG', ['var' => 'title']]]]],
		];
		$expected = ['go1' => [1830612], 'linkedin-learning' => ['urn:li:lyndaCourse:2814005'], 'udemy-business' => [5678281]];

		foreach ($selections as $provider => $conditions) {
			$sync = $this->row(schema: 'synchronization', slug: 'course-marketplace-' . $provider . '-course');
			$passed = [];
			foreach ($this->catalogue(provider: $provider) as $course) {
				if (JsonLogic::apply($conditions, $course) === true) {
					$passed[] = $course[$sync['sourceConfig']['idPosition']];
				}
			}

			$this->assertSame($expected[$provider], $passed, $provider);
		}
	}//end testASelectionOfDutchAvgCoursesLetsOnlyThoseThrough()

	/**
	 * Each set is dormant, brokered, never deletes, and its three synchronizations share one source and one selection.
	 *
	 * @return void
	 */
	public function testEachSetIsDormantBrokeredAndNeverDeletes(): void {
		foreach (self::PROVIDERS as $provider) {
			$source = $this->row(schema: 'source', slug: 'course-marketplace-' . $provider);
			$this->assertFalse($source['isEnabled'], $provider . ' ships off');
			$this->assertStringContainsString('credentialRef', json_encode($source['configuration'], JSON_THROW_ON_ERROR), $provider . ' calls through the broker');
			$this->assertStringNotContainsString('secret":"', str_replace(' ', '', json_encode($source['configuration'], JSON_THROW_ON_ERROR)));
			$this->assertStringStartsWith('https://', $source['location']);
			$this->assertStringStartsWith('https://', $source['documentation']);

			$conditions = [];
			foreach (array_keys(self::SCHEMA_OF) as $kind) {
				$sync = $this->row(schema: 'synchronization', slug: 'course-marketplace-' . $provider . '-' . $kind);
				$this->assertSame('course-marketplace-' . $provider, $sync['sourceId']);
				$this->assertSame('course-marketplace-' . $provider . '-' . $kind, $sync['sourceTargetMapping']);
				$this->assertSame('keepAndFlag', $sync['sourceConfig']['disappearancePolicy'], 'a withdrawn course is never deleted');
				$this->assertStringStartsWith('learniq/', $sync['targetId']);
				$conditions[] = $sync['conditions'];
			}

			$this->assertCount(1, array_unique(array_map('json_encode', $conditions)), $provider . ': one selection for all three');
		}
	}//end testEachSetIsDormantBrokeredAndNeverDeletes()

	/**
	 * Every seeded row is one integriq's own register accepts.
	 *
	 * @return void
	 */
	public function testEverySeededRowIsAcceptedByTheIntegriqRegister(): void {
		$objects = $this->objects();
		$this->assertCount(21, $objects, 'three providers, each one source, three mappings and three synchronizations');

		foreach ($objects as $object) {
			$row = $object;
			unset($row['@self']);
			$errors = RegisterSchemaValidator::errors(schemaSlug: $object['@self']['schema'], object: $row);
			$this->assertSame([], $errors, $object['@self']['slug'] . ' is refused by the integriq ' . $object['@self']['schema'] . ' schema');
		}
	}//end testEverySeededRowIsAcceptedByTheIntegriqRegister()
}//end class
