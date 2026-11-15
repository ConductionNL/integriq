<?php

/**
 * Characterisation test for DsoAccountRights::missing.
 *
 * Pins the allowed and refused outcomes of the account rights check: which
 * actions come back missing, and every case where OpenRegister cannot answer
 * and the check answers null (unverifiable) instead of "all held".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the rights check.
 */
class DsoAccountRightsGoldenTest extends TestCase {

	/**
	 * Everything recorded, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Build the rights check.
	 *
	 * @param string $handlerMode `grants`, `throws-on-update`, `no-method`, `absent`.
	 * @param string $schemaMode  `found`, `null` or `throws`.
	 *
	 * @return DsoAccountRights
	 */
	private function rights(string $handlerMode, string $schemaMode): DsoAccountRights {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend = [], bool $rbac = true, bool $multi = true) use ($schemaMode): mixed {
				$this->log[] = ['find' => [$id, $extend, $rbac, $multi]];
				if ($schemaMode === 'throws') {
					throw new RuntimeException('no such schema');
				}

				return ($schemaMode === 'null' ? null : (object)['slug' => (string)$id]);
			}
		);

		$log = &$this->log;
		$handler = new class($log, $handlerMode) {
			/**
			 * @param list<array<string, mixed>> $log  The shared log.
			 * @param string                     $mode The handler mode.
			 */
			public function __construct(private array &$log, private readonly string $mode) {
			}

			/**
			 * Grants read and create; update throws in that mode; 1 for delete (truthy, not true).
			 *
			 * @param object      $schema The schema.
			 * @param string      $action The action.
			 * @param string|null $userId The user.
			 *
			 * @return mixed
			 */
			public function hasPermission(object $schema, string $action, ?string $userId = null): mixed {
				$this->log[] = ['hasPermission' => [$schema->slug, $action, $userId]];
				if ($action === 'update' && $this->mode === 'throws-on-update') {
					throw new RuntimeException('rbac down');
				}

				return match ($action) {
					'read', 'create' => true,
					'delete' => 1,
					default => false,
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($handlerMode, $handler): mixed {
				$this->log[] = ['container' => $id];
				if ($handlerMode === 'absent') {
					throw new RuntimeException('not registered');
				}

				return ($handlerMode === 'no-method' ? new \stdClass() : $handler);
			}
		);

		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<array<string, mixed>> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};

		return new DsoAccountRights($schemaMapper, $container, $logger);
	}//end rights()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$all = ['read', 'create', 'update', 'delete'];
		$cases = [
			'all held' => ['grants', 'found', ['read', 'create'], 'dso_verzoek'],
			'some missing' => ['grants', 'found', $all, 'openformulieren_submission'],
			'no actions' => ['grants', 'found', [], 'dso_verzoek'],
			'check throws' => ['throws-on-update', 'found', $all, 'dso_verzoek'],
			'schema null' => ['grants', 'null', $all, 'dso_verzoek'],
			'schema throws' => ['grants', 'throws', $all, 'dso_verzoek'],
			'handler without method' => ['no-method', 'found', $all, 'dso_verzoek'],
			'handler absent' => ['absent', 'found', $all, 'dso_verzoek'],
		];
		foreach ($cases as $name => [$handlerMode, $schemaMode, $actions, $schema]) {
			$this->log[] = [$name => $this->rights($handlerMode, $schemaMode)->missing('svc-dso', $actions, $schema)];
		}

		$this->log[] = ['default schema' => $this->rights('grants', 'found')->missing('svc-dso', ['update'])];

		$path = __DIR__ . '/../../../fixtures/dso/dso-account-rights.json';
		$actual = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
