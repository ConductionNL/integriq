<?php

/**
 * Unit tests for the integriq list, run and test occ commands.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command\Integration;

use OCA\Integriq\Command\Integration\IntegrationCommand;
use OCA\Integriq\Command\Integration\IntegrationObjectLookup;
use OCA\Integriq\Command\Integration\JobList;
use OCA\Integriq\Command\Integration\JobRun;
use OCA\Integriq\Command\Integration\SourceList;
use OCA\Integriq\Command\Integration\SourceTest;
use OCA\Integriq\Command\Integration\SynchronizationList;
use OCA\Integriq\Command\Integration\SynchronizationRun;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\Integriq\Service\SourceTestServiceInterface;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Each command resolves its object, calls the screen action's service and exits 0, 1 or 2.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */
class IntegrationCommandsTest extends TestCase {

	/**
	 * @var IntegrationObjectLookup&MockObject
	 */
	private $lookup;

	/**
	 * Set up the lookup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lookup = $this->createMock(IntegrationObjectLookup::class);

	}//end setUp()

	/**
	 * An object with a uuid and data.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function entity(string $uuid, array $data=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * The lookup resolves exactly this schema and reference to this object.
	 *
	 * @param string            $schema    The schema.
	 * @param string            $reference The reference.
	 * @param ObjectEntity|null $object    The answer.
	 *
	 * @return void
	 */
	private function resolves(string $schema, string $reference, ?ObjectEntity $object): void {
		$this->lookup->expects($this->once())->method('resolve')->with($schema, $reference)->willReturn($object);

	}//end resolves()

	/**
	 * The command names are the ones the spec fixes.
	 *
	 * @return void
	 */
	public function testTheSixCommandNames(): void {
		$names = [
			(new SourceList($this->lookup))->getName(),
			(new SourceTest($this->lookup, $this->createMock(SourceTestServiceInterface::class)))->getName(),
			(new SynchronizationList($this->lookup))->getName(),
			(new SynchronizationRun($this->lookup, $this->createMock(SynchronizationServiceInterface::class)))->getName(),
			(new JobList($this->lookup))->getName(),
			(new JobRun($this->lookup, $this->createMock(JobServiceInterface::class)))->getName(),
		];

		$this->assertSame(
			[
				'integriq:source:list',
				'integriq:source:test',
				'integriq:synchronization:list',
				'integriq:synchronization:run',
				'integriq:job:list',
				'integriq:job:run',
			],
			$names
		);

	}//end testTheSixCommandNames()

	/**
	 * A source that answers 200 passes, with status and duration, as JSON.
	 *
	 * @return void
	 */
	public function testASourceAnswering200PassesAsJson(): void {
		$source = self::entity('s-1', ['slug' => 'brp-haalcentraal']);
		$this->resolves('source', 'brp-haalcentraal', $source);
		$tests = $this->createMock(SourceTestServiceInterface::class);
		$tests->expects($this->once())->method('run')->with($source, '/ping', 'POST')->willReturn(
			['outcome' => 'ok', 'result' => [], 'statusCode' => 200, 'statusMessage' => 'OK', 'error' => '']
		);

		$tester = new CommandTester(new SourceTest($this->lookup, $tests));
		$exit   = $tester->execute(['source' => 'brp-haalcentraal', '--endpoint' => '/ping', '--method' => 'post', '--output' => 'json']);

		$this->assertSame(IntegrationCommand::EXIT_OK, $exit);
		$printed = json_decode($tester->getDisplay(), true);
		$this->assertTrue($printed['passed']);
		$this->assertSame(200, $printed['statusCode']);
		$this->assertSame('OK', $printed['statusMessage']);
		$this->assertIsInt($printed['durationMs']);

	}//end testASourceAnswering200PassesAsJson()

	/**
	 * Each failed source test exits 1: an error status, no response, a failed call.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function failedTests(): array {
		return [
			'error status' => [['outcome' => 'ok', 'result' => [], 'statusCode' => 503, 'statusMessage' => 'Unavailable', 'error' => '']],
			'no response'  => [['outcome' => SourceTestServiceInterface::OUTCOME_NO_RESPONSE, 'result' => null, 'statusCode' => null, 'statusMessage' => '', 'error' => '']],
			'failed'       => [['outcome' => SourceTestServiceInterface::OUTCOME_FAILED, 'result' => null, 'statusCode' => null, 'statusMessage' => '', 'error' => 'refused']],
		];

	}//end failedTests()

	/**
	 * A failed source test exits 1.
	 *
	 * @param array $answer The test service's answer.
	 *
	 * @dataProvider failedTests
	 *
	 * @return void
	 */
	public function testAFailedSourceTestExits1(array $answer): void {
		$this->resolves('source', 's-1', self::entity('s-1'));
		$tests = $this->createMock(SourceTestServiceInterface::class);
		$tests->method('run')->willReturn($answer);

		$tester = new CommandTester(new SourceTest($this->lookup, $tests));

		$this->assertSame(IntegrationCommand::EXIT_FAILED, $tester->execute(['source' => 's-1']));
		$this->assertStringContainsString('passed: false', $tester->getDisplay());

	}//end testAFailedSourceTestExits1()

	/**
	 * An unknown object exits 2 and calls no service, for each command that takes one.
	 *
	 * @return void
	 */
	public function testAnUnknownObjectExits2(): void {
		$this->lookup->method('resolve')->willReturn(null);
		$tests = $this->createMock(SourceTestServiceInterface::class);
		$tests->expects($this->never())->method('run');
		$sync = $this->createMock(SynchronizationServiceInterface::class);
		$sync->expects($this->never())->method('synchronize');
		$jobs = $this->createMock(JobServiceInterface::class);
		$jobs->expects($this->never())->method('executeJob');

		$cases = [
			[new SourceTest($this->lookup, $tests), 'source'],
			[new SynchronizationRun($this->lookup, $sync), 'synchronization'],
			[new JobRun($this->lookup, $jobs), 'job'],
		];
		foreach ($cases as [$command, $argument]) {
			$tester = new CommandTester($command);
			$this->assertSame(IntegrationCommand::EXIT_NOT_FOUND, $tester->execute([$argument => 'does-not-exist']), $argument);
			$this->assertStringContainsString('does-not-exist', $tester->getDisplay(), $argument);
		}

	}//end testAnUnknownObjectExits2()

	/**
	 * `--test` runs the engine in test mode, which writes nothing.
	 *
	 * @return void
	 */
	public function testATestRunAsksTheEngineForTestMode(): void {
		$synchronization = self::entity('y-1', ['slug' => 'woo']);
		$this->resolves('synchronization', 'woo', $synchronization);
		$sync = $this->createMock(SynchronizationServiceInterface::class);
		$sync->expects($this->once())->method('synchronize')
			->with($synchronization, true, false)
			->willReturn(['result' => ['objects' => ['created' => 3]]]);

		$tester = new CommandTester(new SynchronizationRun($this->lookup, $sync));
		$exit   = $tester->execute(['synchronization' => 'woo', '--test' => true, '--output' => 'json']);

		$this->assertSame(IntegrationCommand::EXIT_OK, $exit);
		$printed = json_decode($tester->getDisplay(), true);
		$this->assertTrue($printed['test']);
		$this->assertSame(3, $printed['result']['result']['objects']['created']);

	}//end testATestRunAsksTheEngineForTestMode()

	/**
	 * A plain run is a real run, with force when asked; a run that throws exits 1.
	 *
	 * @return void
	 */
	public function testARunThatThrowsExits1(): void {
		$synchronization = self::entity('y-1');
		$this->resolves('synchronization', 'y-1', $synchronization);
		$sync = $this->createMock(SynchronizationServiceInterface::class);
		$sync->expects($this->once())->method('synchronize')
			->with($synchronization, false, true)
			->willThrowException(new RuntimeException('source down'));

		$tester = new CommandTester(new SynchronizationRun($this->lookup, $sync));

		$this->assertSame(IntegrationCommand::EXIT_FAILED, $tester->execute(['synchronization' => 'y-1', '--force' => true]));
		$this->assertStringContainsString("error: 'source down'", $tester->getDisplay());

	}//end testARunThatThrowsExits1()

	/**
	 * A job whose log entry is an error exits 1; one that ran exits 0.
	 *
	 * @return void
	 */
	public function testAJobExitsOnItsLogLevel(): void {
		$job = self::entity('j-1');
		$this->lookup->method('resolve')->with('job', 'nightly')->willReturn($job);
		$jobs = $this->createMock(JobServiceInterface::class);
		$jobs->expects($this->exactly(2))->method('executeJob')->with($job, true)->willReturnOnConsecutiveCalls(
			self::entity('l-1', ['level' => 'INFO']),
			self::entity('l-2', ['level' => 'ERROR'])
		);

		$tester = new CommandTester(new JobRun($this->lookup, $jobs));

		$this->assertSame(IntegrationCommand::EXIT_OK, $tester->execute(['job' => 'nightly', '--force' => true]));
		$this->assertSame(IntegrationCommand::EXIT_FAILED, $tester->execute(['job' => 'nightly', '--force' => true]));

	}//end testAJobExitsOnItsLogLevel()

	/**
	 * The list prints uuid, slug and name, sorted by slug, for the command's own schema.
	 *
	 * @return void
	 */
	public function testAListPrintsSlugsAndUuidsSorted(): void {
		$this->lookup->expects($this->once())->method('list')->with('job')->willReturn(
			[
				self::entity('u-2', ['slug' => 'zeta', 'name' => 'Zeta']),
				self::entity('u-1', ['slug' => 'alpha', 'name' => 'Alpha']),
			]
		);

		$tester = new CommandTester(new JobList($this->lookup));

		$this->assertSame(IntegrationCommand::EXIT_OK, $tester->execute(['--output' => 'json']));
		$this->assertSame(
			[
				['uuid' => 'u-1', 'slug' => 'alpha', 'name' => 'Alpha'],
				['uuid' => 'u-2', 'slug' => 'zeta', 'name' => 'Zeta'],
			],
			json_decode($tester->getDisplay(), true)
		);

	}//end testAListPrintsSlugsAndUuidsSorted()

}//end class
