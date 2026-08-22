<?php

/**
 * Unit tests for the MigrateStoredJobClasses repair step.
 *
 * Covers the openconnector -> integriq rewrite of the PHP class name STORED
 * inside job objects. The failure this step prevents is silent — a job whose
 * `jobClass` still names the old namespace does not error, it simply stops
 * executing while cron reports success — so the behaviours asserted here are
 * the ones nothing downstream would notice were broken.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec exclude One-off openconnector->integriq app-id rename plumbing; the
 *       step rewrites a stored class-name string and adds no domain behaviour.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateStoredJobClasses;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the stored-jobClass rewrite: it moves stale values, leaves everything
 * else alone, walks every page, and survives a single failing object.
 */
class MigrateStoredJobClassesTest extends TestCase {
	/**
	 * Build a quiet IOutput double.
	 *
	 * @return IOutput
	 */
	private function makeOutput(): IOutput {
		return $this->createMock(IOutput::class);
	}//end makeOutput()

	/**
	 * Build the repair step with a container resolving the OR object service.
	 *
	 * @param OrObjectService $orObjectService The OR object service double.
	 *
	 * @return MigrateStoredJobClasses
	 */
	private function makeStep(OrObjectService $orObjectService): MigrateStoredJobClasses {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($orObjectService) {
				if ($id === OrObjectService::class) {
					return $orObjectService;
				}

				throw new \RuntimeException('unexpected service: ' . $id);
			}
		);

		return new MigrateStoredJobClasses($container, new NullLogger());
	}//end makeStep()

	/**
	 * A stored jobClass on the old namespace is rewritten to the new one and
	 * saved back over the SAME object — this is the whole point of the step.
	 *
	 * @return void
	 */
	public function testRewritesStaleJobClassInPlace(): void {
		$stale = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'Nightly sync', 'jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction'],
			'job-uuid-1'
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [$stale], 'total' => 1]);

		$orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register = null, $schema = null, $uuid = null) {
					$this->assertSame(
						'OCA\\Integriq\\Action\\SynchronizationAction',
						$object['jobClass'],
						'the old namespace prefix must be rewritten to the new one'
					);
					$this->assertSame('Nightly sync', $object['name'], 'other properties must be preserved');
					// The register SLUG is deliberately frozen on the old name.
					$this->assertSame('openconnector', $register);
					$this->assertSame('job', $schema);
					$this->assertSame('job-uuid-1', $uuid, 'must update in place, never create a duplicate');
					return ObjectServiceMockBuilder::objectEntity($this, $object, 'job-uuid-1');
				}
			);

		$this->makeStep($orObjectService)->run($this->makeOutput());
	}//end testRewritesStaleJobClassInPlace()

	/**
	 * A value that already names the new namespace is not written again, so a
	 * second run of the step is a genuine no-op.
	 *
	 * @return void
	 */
	public function testAlreadyMigratedValueIsNotRewritten(): void {
		$fresh = ObjectServiceMockBuilder::objectEntity(
			$this,
			['jobClass' => 'OCA\\Integriq\\Action\\SynchronizationAction'],
			'job-uuid-2'
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [$fresh], 'total' => 1]);
		$orObjectService->expects($this->never())->method('saveObject');

		$this->makeStep($orObjectService)->run($this->makeOutput());
	}//end testAlreadyMigratedValueIsNotRewritten()

	/**
	 * Anything that is not this app's old namespace is left exactly as it is
	 * rather than guessed at — a third-party job class, and an empty value.
	 *
	 * @return void
	 */
	public function testUnrecognisedValuesAreLeftAlone(): void {
		$thirdParty = ObjectServiceMockBuilder::objectEntity(
			$this,
			['jobClass' => 'OCA\\SomeOtherApp\\Action\\Whatever'],
			'job-uuid-3'
		);
		$empty = ObjectServiceMockBuilder::objectEntity($this, ['jobClass' => ''], 'job-uuid-4');
		$missing = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'no class at all'], 'job-uuid-5');

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(
			['results' => [$thirdParty, $empty, $missing], 'total' => 3]
		);
		$orObjectService->expects($this->never())->method('saveObject');

		$this->makeStep($orObjectService)->run($this->makeOutput());
	}//end testUnrecognisedValuesAreLeftAlone()

	/**
	 * The enumeration must walk EVERY page. findAll() is server-paged, so an
	 * implementation that took only the first page would migrate the first
	 * batch, leave every later job broken, and still report success — the
	 * exact silent-partial-success this step exists to avoid.
	 *
	 * @return void
	 */
	public function testEnumerationWalksBeyondTheFirstPage(): void {
		$pageSize = 100;

		// A full first page forces a second request; the short second page ends
		// the walk.
		$firstPage = [];
		for ($i = 0; $i < $pageSize; $i++) {
			$firstPage[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction'],
				'page1-' . $i
			);
		}

		$secondPage = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['jobClass' => 'OCA\\OpenConnector\\Action\\PingAction'],
				'page2-0'
			),
		];

		$seenOffsets = [];
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$seenOffsets, $firstPage, $secondPage) {
				$offset = (int)($config['offset'] ?? 0);
				$seenOffsets[] = $offset;
				if ($offset === 0) {
					return ['results' => $firstPage, 'total' => 101];
				}

				return ['results' => $secondPage, 'total' => 101];
			}
		);

		$savedUuids = [];
		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) use (&$savedUuids) {
				$savedUuids[] = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid);
			}
		);

		$this->makeStep($orObjectService)->run($this->makeOutput());

		$this->assertSame([0, $pageSize], $seenOffsets, 'must request the second page');
		$this->assertCount($pageSize + 1, $savedUuids, 'every job on every page must be migrated');
		$this->assertContains('page2-0', $savedUuids, 'a job on the second page must not be skipped');
	}//end testEnumerationWalksBeyondTheFirstPage()

	/**
	 * One object failing to save must not abort the pass. This step also runs
	 * under <install>, where a throwing repair step means the app never enables
	 * at all — and the remaining jobs still need migrating.
	 *
	 * @return void
	 */
	public function testOneFailingObjectDoesNotAbortTheRest(): void {
		$bad = ObjectServiceMockBuilder::objectEntity(
			$this,
			['jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction'],
			'bad-uuid'
		);
		$good = ObjectServiceMockBuilder::objectEntity(
			$this,
			['jobClass' => 'OCA\\OpenConnector\\Action\\PingAction'],
			'good-uuid'
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [$bad, $good], 'total' => 2]);

		$savedUuids = [];
		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) use (&$savedUuids) {
				if ($uuid === 'bad-uuid') {
					throw new \RuntimeException('write rejected');
				}

				$savedUuids[] = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid);
			}
		);

		$this->makeStep($orObjectService)->run($this->makeOutput());

		$this->assertSame(['good-uuid'], $savedUuids, 'the job after the failing one must still be migrated');
	}//end testOneFailingObjectDoesNotAbortTheRest()

	/**
	 * A failed enumeration is reported as a warning rather than thrown, because
	 * this step runs under <install> and a throw there stops the app enabling.
	 *
	 * @return void
	 */
	public function testEnumerationFailureIsWarnedNotThrown(): void {
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$orObjectService->expects($this->never())->method('saveObject');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->atLeastOnce())->method('warning');

		$this->makeStep($orObjectService)->run($output);
	}//end testEnumerationFailureIsWarnedNotThrown()
}//end class
