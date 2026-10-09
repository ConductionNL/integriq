<?php

/**
 * A marketplace synchronization does not run before its LTI deployment is set.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\RunPrerequisiteGuard;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Runs the guard over the real seeded marketplace synchronizations and mappings.
 */
class RunPrerequisiteGuardTest extends TestCase {

	/**
	 * The seeded objects, keyed by schema and slug.
	 *
	 * @var array<string, array<string, array>>
	 */
	private array $seeds = [];

	/**
	 * Load the marketplace fragment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/course-marketplace-connectors.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$this->seeds[$self['schema']][$self['slug']] = $object;
		}
	}//end setUp()

	/**
	 * A guard reading mappings from the seeds.
	 *
	 * @return RunPrerequisiteGuard
	 */
	private function guard(): RunPrerequisiteGuard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) {
				if (isset($this->seeds[$schema][$id]) === false) {
					throw new \RuntimeException('Object not found');
				}

				return ObjectServiceMockBuilder::objectEntity($this, $this->seeds[$schema][$id], 'uuid-' . $id);
			}
		);

		return new RunPrerequisiteGuard(objectService: $objects);
	}//end guard()

	/**
	 * The nine marketplace synchronizations.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function marketplaceSynchronizations(): array {
		$cases = [];
		foreach (['go1' => 'Go1', 'linkedin-learning' => 'LinkedIn Learning', 'udemy-business' => 'Udemy Business'] as $provider => $name) {
			foreach (['course', 'lesson', 'placement'] as $object) {
				$cases[$provider . ' ' . $object] = ['course-marketplace-' . $provider . '-' . $object, $provider, $name];
			}
		}

		return $cases;
	}//end marketplaceSynchronizations()

	/**
	 * Without a deployment no synchronization of the set runs, and the refusal names the deployment.
	 *
	 * @dataProvider marketplaceSynchronizations
	 *
	 * @param string $slug     The synchronization.
	 * @param string $provider The provider key.
	 * @param string $name     The provider's name.
	 *
	 * @return void
	 */
	public function testWithoutADeploymentNothingRunsAndTheLogNamesIt(string $slug, string $provider, string $name): void {
		$missing = $this->guard()->missing(sourceConfig: $this->seeds['synchronization'][$slug]['sourceConfig']);

		$this->assertNotNull($missing, $slug . ' runs without its deployment.');
		$this->assertStringContainsString('lti_deployment', $missing);
		$this->assertStringContainsString($name, $missing);
		$this->assertStringContainsString('course-marketplace-' . $provider . '-placement', $missing, 'The refusal says where to set it.');
	}//end testWithoutADeploymentNothingRunsAndTheLogNamesIt()

	/**
	 * Once the deployment is set, every synchronization of the set runs.
	 *
	 * @dataProvider marketplaceSynchronizations
	 *
	 * @param string $slug     The synchronization.
	 * @param string $provider The provider key.
	 *
	 * @return void
	 */
	public function testWithTheDeploymentSetTheSynchronizationRuns(string $slug, string $provider): void {
		$this->seeds['mapping']['course-marketplace-' . $provider . '-placement']['mapping']['openconnectorDeploymentId'] = 'deployment-uuid-1';

		$this->assertNull($this->guard()->missing(sourceConfig: $this->seeds['synchronization'][$slug]['sourceConfig']));
	}//end testWithTheDeploymentSetTheSynchronizationRuns()

	/**
	 * A synchronization that declares nothing runs as before, without a lookup.
	 *
	 * @return void
	 */
	public function testASynchronizationWithoutPrerequisitesRuns(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('find');

		$this->assertNull((new RunPrerequisiteGuard(objectService: $objects))->missing(sourceConfig: ['endpoint' => '/x']));
	}//end testASynchronizationWithoutPrerequisitesRuns()

	/**
	 * A mapping that is not on this instance is named, not skipped.
	 *
	 * @return void
	 */
	public function testAMissingMappingIsNamed(): void {
		unset($this->seeds['mapping']['course-marketplace-go1-placement']);

		$missing = $this->guard()->missing(sourceConfig: $this->seeds['synchronization']['course-marketplace-go1-course']['sourceConfig']);

		$this->assertStringContainsString('course-marketplace-go1-placement', (string)$missing);
	}//end testAMissingMappingIsNamed()

	/**
	 * A malformed declaration is refused rather than read as "nothing required".
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsRefused(): void {
		$this->assertNotNull($this->guard()->missing(sourceConfig: ['requiredMappingValues' => 'openconnectorDeploymentId']));
		$this->assertNotNull($this->guard()->missing(sourceConfig: ['requiredMappingValues' => [['key' => 'openconnectorDeploymentId']]]));
	}//end testAMalformedDeclarationIsRefused()
}//end class
