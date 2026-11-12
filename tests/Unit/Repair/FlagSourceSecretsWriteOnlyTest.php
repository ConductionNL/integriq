<?php

/**
 * Characterisation of FlagSourceSecretsWriteOnly::run(): which credential
 * fields are flagged write-only, when the schema is written, and what the
 * step says.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\FlagSourceSecretsWriteOnly;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Repair\FlagSourceSecretsWriteOnly
 */
class FlagSourceSecretsWriteOnlyTest extends TestCase {

	/**
	 * Run the step over a source schema carrying the given properties.
	 *
	 * @param mixed $properties The live properties, or 'throw' for a failing mapper.
	 *
	 * @return array{0: mixed, 1: int, 2: array<int, string>, 3: array<int, string>, 4: array<int, string>} Final properties, updates, infos, warnings, errors.
	 */
	private function runOver(mixed $properties): array {
		$schema = new class ($properties) {
			/**
			 * Constructor.
			 *
			 * @param mixed $properties The properties.
			 */
			public function __construct(public mixed $properties) {
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
			 * @param array<string,mixed> $properties The properties.
			 *
			 * @return void
			 */
			public function setProperties(array $properties): void {
				$this->properties = $properties;
			}
		};
		$mapper = new class ($schema, $properties) {
			/**
			 * The updates written.
			 *
			 * @var integer
			 */
			public int $updates = 0;

			/**
			 * Constructor.
			 *
			 * @param object $schema     The schema.
			 * @param mixed  $properties The properties, or 'throw'.
			 */
			public function __construct(private object $schema, private mixed $properties) {
			}

			/**
			 * Find the schema.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object The schema.
			 */
			public function find(string $slug): object {
				if ($this->properties === 'throw' || $slug !== 'source') {
					throw new RuntimeException('no source schema');
				}

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
				$this->updates++;
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$infos = [];
		$warnings = [];
		$errors = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $m) use (&$infos): void {
			$infos[] = $m;
		});
		$output->method('warning')->willReturnCallback(static function (string $m) use (&$warnings): void {
			$warnings[] = $m;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $m) use (&$errors): void {
			$errors[] = $m;
		});

		(new FlagSourceSecretsWriteOnly($container, $logger))->run($output);

		return [$schema->properties, $mapper->updates, $infos, $warnings, $errors];
	}

	/**
	 * Every path: flagging, already flagged, nothing to flag, failure.
	 *
	 * @return void
	 */
	public function testEveryCredentialFieldEndsWriteOnly(): void {
		[$properties, $updates, $infos] = $this->runOver(
			['name' => ['type' => 'string'], 'apikey' => ['type' => 'string'], 'secret' => ['type' => 'string', 'writeOnly' => true], 'password' => 'junk', 'jwt' => ['writeOnly' => 'yes']]
		);
		$this->assertSame(
			['name' => ['type' => 'string'], 'apikey' => ['type' => 'string', 'writeOnly' => true], 'secret' => ['type' => 'string', 'writeOnly' => true], 'password' => 'junk', 'jwt' => ['writeOnly' => true]],
			$properties
		);
		$this->assertSame([1, ['Integriq: flagged source credential fields (apikey/secret/password/jwt/authenticationConfig) write-only.']], [$updates, $infos]);

		[, $updates, $infos] = $this->runOver(['apikey' => ['writeOnly' => true], 'name' => []]);
		$this->assertSame([0, ['Integriq: source-secret write-only flags already set.']], [$updates, $infos]);

		$this->assertSame(['not-an-array', 0, [], [], []], $this->runOver('not-an-array'));

		[, $updates, $infos, $warnings, $errors] = $this->runOver('throw');
		$this->assertSame([0, [], ['Integriq: could not flag source secrets write-only: no source schema'], ['Integriq: FlagSourceSecretsWriteOnly failed; source credentials may still be returned by the API']], [$updates, $infos, $warnings, $errors]);
	}
}
