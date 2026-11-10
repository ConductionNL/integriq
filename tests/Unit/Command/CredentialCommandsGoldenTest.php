<?php

/**
 * Golden master of the integriq:authentication-config and
 * integriq:migrate-inline-secrets console output.
 *
 * Every case scripts what the auditor, remover, planner and executor answer
 * (or throws), runs the command and records its exit code, its full display
 * and every collaborator call (the schema write and the Phase D gate writes
 * included). Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\AuthenticationConfig;
use OCA\Integriq\Command\MigrateInlineSecrets;
use OCA\Integriq\Service\Security\AuthenticationConfigAuditorInterface;
use OCA\Integriq\Service\Security\AuthenticationConfigRemoverInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutorInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlannerInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\AuthenticationConfig
 * @covers \OCA\Integriq\Command\MigrateInlineSecrets
 */
class CredentialCommandsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/credential-commands.json';

	/**
	 * Every collaborator call of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A two-source audit report: one with a fingerprinted shape, one Twig-referenced.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function auditReport(): array {
		return [
			'sources' => [
				[
					'name' => 'OAuth Source',
					'uuid' => 'src-1',
					'state' => 'holds-value',
					'keys' => ['client_id', 'client_secret'],
					'shapes' => ['client_id' => ['shape' => 'string'], 'client_secret' => ['shape' => 'string', 'fingerprint' => 'ab12']],
					'referenced' => false,
				],
				['uuid' => 'src-2', 'state' => 'holds-value', 'keys' => ['token'], 'referenced' => true, 'references' => ['headers.Authorization', 'query.key']],
			],
			'totalSources' => 3,
			'holdValue' => 2,
			'clear' => 1,
			'unreadable' => 0,
			'referenced' => 1,
			'schemaPropertyRemovable' => false,
		];
	}

	/**
	 * The audit command over scripted answers.
	 *
	 * @param array<string, mixed> $world `audit` (array or 'throw'), `remove` (array or 'throw'), `schema` (see schemaMapper()).
	 *
	 * @return AuthenticationConfig The command.
	 */
	private function authenticationConfig(array $world): AuthenticationConfig {
		$auditor = $this->createMock(AuthenticationConfigAuditorInterface::class);
		$auditor->method('auditAll')->willReturnCallback(
			function (int $limit) use ($world): array {
				$this->calls[] = ['auditAll', $limit];
				if (($world['audit'] ?? []) === 'throw') {
					throw new RuntimeException('audit broke');
				}

				return ($world['audit'] ?? []);
			}
		);
		$remover = $this->createMock(AuthenticationConfigRemoverInterface::class);
		$remover->method('removeAll')->willReturnCallback(
			function (int $limit, bool $optIn) use ($world): array {
				$this->calls[] = ['removeAll', $limit, $optIn];
				if (($world['remove'] ?? []) === 'throw') {
					throw new RuntimeException('remover refused');
				}

				return ($world['remove'] ?? []);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($world): object {
				$this->calls[] = ['container.get', $id];
				if (($world['schema'] ?? null) === 'throw') {
					throw new RuntimeException('no schema mapper');
				}

				return $this->schemaMapper(properties: ($world['schema'] ?? []));
			}
		);

		return new AuthenticationConfig($auditor, $remover, $container);
	}

	/**
	 * A schema mapper whose source schema carries the given properties.
	 *
	 * @param mixed $properties The live properties.
	 *
	 * @return object The mapper.
	 */
	private function schemaMapper(mixed $properties): object {
		$test = $this;
		$schema = new class ($properties, $test) {
			/**
			 * Constructor.
			 *
			 * @param mixed                        $properties The properties.
			 * @param CredentialCommandsGoldenTest $test       The recorder.
			 */
			public function __construct(public mixed $properties, private CredentialCommandsGoldenTest $test) {
			}

			/**
			 * The properties.
			 *
			 * @return mixed
			 */
			public function getProperties(): mixed {
				return $this->properties;
			}

			/**
			 * Set the properties.
			 *
			 * @param array<string, mixed> $properties The properties.
			 *
			 * @return void
			 */
			public function setProperties(array $properties): void {
				$this->test->record(['schema.setProperties', $properties]);
				$this->properties = $properties;
			}
		};

		return new class ($schema, $test) {
			/**
			 * Constructor.
			 *
			 * @param object                       $schema The schema.
			 * @param CredentialCommandsGoldenTest $test   The recorder.
			 */
			public function __construct(private object $schema, private CredentialCommandsGoldenTest $test) {
			}

			/**
			 * Find the schema.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object The schema.
			 */
			public function find(string $slug): object {
				$this->test->record(['schemaMapper.find', $slug]);
				return $this->schema;
			}

			/**
			 * Update the schema.
			 *
			 * @param object $schema The schema.
			 *
			 * @return object The schema.
			 */
			public function update(object $schema): object {
				$this->test->record(['schemaMapper.update']);
				return $schema;
			}
		};
	}

	/**
	 * Record one collaborator call.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}

	/**
	 * The migration command over scripted answers.
	 *
	 * @param array<string, mixed> $world `plan` (array or 'throw'), `migrate` (array or 'throw').
	 *
	 * @return MigrateInlineSecrets The command.
	 */
	private function migrateInlineSecrets(array $world): MigrateInlineSecrets {
		$planner = $this->createMock(InlineSecretMigrationPlannerInterface::class);
		$planner->method('planEverything')->willReturnCallback(
			function (int $limit) use ($world): array {
				$this->calls[] = ['planEverything', $limit];
				if (($world['plan'] ?? []) === 'throw') {
					throw new RuntimeException('planner broke');
				}

				return ($world['plan'] ?? []);
			}
		);
		$executor = $this->createMock(InlineSecretMigrationExecutorInterface::class);
		$executor->method('migrateEverything')->willReturnCallback(
			function (int $limit) use ($world): array {
				$this->calls[] = ['migrateEverything', $limit];
				if (($world['migrate'] ?? []) === 'throw') {
					throw new RuntimeException('broker too old');
				}

				return ($world['migrate'] ?? []);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = ['appConfig.setValueString', $app, $key, $value];
				return true;
			}
		);

		return new MigrateInlineSecrets($planner, $executor, $appConfig);
	}

	/**
	 * A migration estate with a source run and a second schema.
	 *
	 * @param bool $clean  Whether the post-run gate is clean.
	 * @param int  $failed How many fields failed.
	 *
	 * @return array<string, mixed> The estate.
	 */
	private static function estate(bool $clean, int $failed): array {
		return [
			'migrated' => 2,
			'failed' => $failed,
			'blocked' => 1,
			'skipped' => 0,
			'schemas' => [
				'source' => [
					'objects' => [
						['name' => 'OAuth Source', 'uuid' => 'src-1', 'fields' => [['field' => 'apikey', 'outcome' => 'migrated'], ['field' => 'password', 'outcome' => 'failed', 'reason' => 'broker said no']]],
						['uuid' => 'src-2', 'fields' => []],
					],
					'totalObjects' => 2,
					'migrated' => 1,
					'failed' => $failed,
					'postRun' => ['clean' => $clean, 'pending' => 3, 'manual' => 1],
				],
				'sender_identity' => ['migrated' => 1, 'blocked' => 1],
			],
		];
	}

	/**
	 * Every case: command, world and input.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$removable = ['totalSources' => 1, 'clear' => 1, 'schemaPropertyRemovable' => true];
		$removeResult = [
			'sources' => [['name' => 'OAuth Source', 'uuid' => 'src-1', 'outcome' => 'removed'], ['uuid' => 'src-2', 'outcome' => 'blocked', 'reason' => 'twig']],
			'removed' => 1,
			'skipped' => 2,
			'blocked' => 1,
			'failed' => 0,
		];
		$plan = [
			'schemas' => [
				'source' => [
					'objects' => [['name' => 'OAuth Source', 'uuid' => 'src-1', 'fields' => [['field' => 'apikey', 'state' => 'pending', 'provider' => 'apikey'], ['field' => 'authenticationConfig', 'state' => 'manual']]]],
					'totalObjects' => 4,
					'wouldMigrate' => 1,
					'needsReview' => 1,
				],
				'sender_identity' => ['objects' => [], 'totalObjects' => 2],
				'other' => ['objects' => [['uuid' => 'o-1', 'fields' => [['field' => 'key']]]], 'wouldMigrate' => 1],
			],
		];

		return [
			'auth-limit-not-a-number' => ['auth', [], ['--limit' => 'abc']],
			'auth-limit-zero' => ['auth', [], ['--limit' => '0']],
			'auth-limit-too-large' => ['auth', [], ['--limit' => '100001']],
			'auth-audit' => ['auth', ['audit' => self::auditReport()], ['--limit' => '50']],
			'auth-audit-removable' => ['auth', ['audit' => $removable], []],
			'auth-audit-empty' => ['auth', ['audit' => []], []],
			'auth-audit-json' => ['auth', ['audit' => self::auditReport()], ['--json' => true]],
			'auth-audit-throws' => ['auth', ['audit' => 'throw'], []],
			'auth-remove' => ['auth', ['remove' => $removeResult], ['--' . AuthenticationConfig::FLAG_REMOVE => true]],
			'auth-remove-empty' => ['auth', ['remove' => []], ['--' . AuthenticationConfig::FLAG_REMOVE => true]],
			'auth-remove-json-failed' => ['auth', ['remove' => ['failed' => 1] + $removeResult], ['--' . AuthenticationConfig::FLAG_REMOVE => true, '--json' => true]],
			'auth-remove-human-failed' => ['auth', ['remove' => ['failed' => 2] + $removeResult], ['--' . AuthenticationConfig::FLAG_REMOVE => true]],
			'auth-remove-throws' => ['auth', ['remove' => 'throw'], ['--' . AuthenticationConfig::FLAG_REMOVE => true]],
			'auth-drop-and-remove-drop-wins' => ['auth', ['audit' => $removable, 'schema' => ['name' => [], 'authenticationConfig' => []]], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true, '--' . AuthenticationConfig::FLAG_REMOVE => true]],
			'auth-drop-audit-throws' => ['auth', ['audit' => 'throw'], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'auth-drop-refused' => ['auth', ['audit' => self::auditReport()], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'auth-drop-mapper-throws' => ['auth', ['audit' => $removable, 'schema' => 'throw'], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'auth-drop-properties-unreadable' => ['auth', ['audit' => $removable, 'schema' => 'not-an-array'], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'auth-drop-already-absent' => ['auth', ['audit' => $removable, 'schema' => ['name' => []]], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'auth-drop' => ['auth', ['audit' => $removable, 'schema' => ['name' => [], 'authenticationConfig' => ['type' => 'object']]], ['--' . AuthenticationConfig::FLAG_DROP_SCHEMA => true]],
			'migrate-limit-not-a-number' => ['migrate', [], ['--limit' => '1.5']],
			'migrate-limit-zero' => ['migrate', [], ['--limit' => '0', '--dry-run' => true]],
			'migrate-dry-run' => ['migrate', ['plan' => $plan], ['--dry-run' => true, '--limit' => '20']],
			'migrate-dry-run-empty' => ['migrate', ['plan' => []], ['--dry-run' => true]],
			'migrate-dry-run-json' => ['migrate', ['plan' => $plan], ['--dry-run' => true, '--json' => true]],
			'migrate-dry-run-throws' => ['migrate', ['plan' => 'throw'], ['--dry-run' => true]],
			'migrate-run-clean' => ['migrate', ['migrate' => self::estate(clean: true, failed: 0)], ['--limit' => '7']],
			'migrate-run-not-clean-failed' => ['migrate', ['migrate' => self::estate(clean: false, failed: 1)], []],
			'migrate-run-json' => ['migrate', ['migrate' => self::estate(clean: false, failed: 1)], ['--json' => true]],
			'migrate-run-no-schemas' => ['migrate', ['migrate' => ['migrated' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0, 'schemas' => []]], []],
			'migrate-run-throws' => ['migrate', ['migrate' => 'throw'], []],
		];
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheCommandsMatchTheRecording(): void {
		$columns = getenv('COLUMNS');
		putenv('COLUMNS=120');
		try {
			$found = $this->runCases();
		} finally {
			putenv($columns === false ? 'COLUMNS' : 'COLUMNS=' . $columns);
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

	/**
	 * Run every case at a fixed console width.
	 *
	 * @return array<string, array<string, mixed>> Exit code, display lines and calls per case.
	 */
	private function runCases(): array {
		$found = [];
		foreach (self::cases() as $name => [$command, $world, $input]) {
			$this->calls = [];
			$built = $this->migrateInlineSecrets(world: $world);
			if ($command === 'auth') {
				$built = $this->authenticationConfig(world: $world);
			}

			$tester = new CommandTester($built);
			$status = $tester->execute($input);
			$found[$name] = ['status' => $status, 'display' => explode("\n", $tester->getDisplay()), 'calls' => $this->calls];
		}

		return $found;
	}
}
