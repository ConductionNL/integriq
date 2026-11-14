<?php

/**
 * Golden master of JobToFlowGenerator::refusalsFor().
 *
 * Every case is one job (and the acting-account configuration) and records
 * every refusal sentence the generator gives, in order. Set RECORD_GOLDEN=1
 * to rewrite the fixture.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\JobIntervalCron;
use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\MigrationEntityReader;
use OCA\Integriq\Service\MigrationSubject;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\JobToFlowGenerator
 */
class JobToFlowRefusalsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/job-to-flow-refusals.json';

	private const SYNC = 'OCA\Integriq\Service\Job\SynchronizationAction';

	/**
	 * The generator, acting as the given account.
	 *
	 * @param string $runAs      The configured acting account.
	 * @param bool   $userExists Whether that account exists.
	 *
	 * @return JobToFlowGenerator The generator.
	 */
	private function generator(string $runAs, bool $userExists): JobToFlowGenerator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($runAs);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($userExists === true ? $this->createMock(IUser::class) : null);

		return new JobToFlowGenerator(
			reader: new MigrationEntityReader(objectService: $this->createMock(ORObjectService::class), l10n: $l10n),
			cadence: new JobIntervalCron(),
			subject: new MigrationSubject(),
			l10n: $l10n,
			appConfig: $appConfig,
			userManager: $users
		);
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheRefusalsMatchTheRecording(): void {
		$base = [
			'uuid' => 'j0000000-0000-4000-8000-000000000001',
			'name' => 'Nightly sync',
			'jobClass' => self::SYNC,
			'interval' => 3600,
			'arguments' => ['synchronizationId' => 's-1'],
		];
		$cases = [
			'migratable' => [[], ['svc', true]],
			'no-run-as' => [[], ['', true]],
			'run-as-missing' => [[], ['ghost', false]],
			'no-reference' => [['uuid' => null], ['svc', true]],
			'zero-interval' => [['interval' => 0], ['svc', true]],
			'missing-interval' => [['interval' => null], ['svc', true]],
			'odd-interval' => [['interval' => 7], ['svc', true]],
			'schedule-after' => [['scheduleAfter' => ' 2026-01-01 '], ['svc', true]],
			'single-run' => [['isSingleRun' => true, 'singleRun' => true], ['svc', true]],
			'user-id' => [['userId' => ' anja '], ['svc', true]],
			'no-job-class' => [['jobClass' => ''], ['svc', true]],
			'ping' => [['jobClass' => 'OCA\Integriq\Service\Job\PingAction'], ['svc', true]],
			'event' => [['jobClass' => 'OCA\Integriq\Service\Job\EventAction'], ['svc', true]],
			'unknown-class' => [['jobClass' => 'OCA\Other\Job'], ['svc', true]],
			'legacy-class-name' => [['jobClass' => 'OCA\OpenConnector\Action\SynchronizationAction'], ['svc', true]],
			'flow-no-target' => [['jobClass' => 'OCA\Integriq\Service\Job\FlowAction', 'arguments' => []], ['svc', true]],
			'flow-extra-arguments' => [['jobClass' => 'OCA\Integriq\Service\Job\FlowAction', 'arguments' => ['flowId' => 'f', 'extra' => 1, 'more' => 2]], ['svc', true]],
			'sync-no-target' => [['arguments' => ['force' => true]], ['svc', true]],
			'everything' => [['uuid' => null, 'interval' => 7, 'scheduleAfter' => 'x', 'isSingleRun' => true, 'userId' => 'u', 'jobClass' => 'OCA\Other\Job'], ['', true]],
		];

		$found = [];
		foreach ($cases as $name => [$overrides, [$runAs, $exists]]) {
			$found[$name] = $this->generator(runAs: $runAs, userExists: $exists)->refusalsFor(job: array_merge($base, $overrides));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], 'case ' . $name);
		}
	}
}
