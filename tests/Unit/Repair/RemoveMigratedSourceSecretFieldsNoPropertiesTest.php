<?php

/**
 * Phase D field removal when the source schema holds no property map.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-phase-d-remove-migrated-fields
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\RemoveMigratedSourceSecretFields;
use OCA\Integriq\Service\Security\InlineSecretMigrationFactoryInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlannerInterface;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A schema without a property array is not written and keeps the gate closed.
 */
#[CoversClass(RemoveMigratedSourceSecretFields::class)]
final class RemoveMigratedSourceSecretFieldsNoPropertiesTest extends TestCase {

	/**
	 * A clean fleet over a schema whose properties are not an array records
	 * the fields as not removed and never updates the schema.
	 *
	 * @return void
	 */
	public function testNonArrayPropertiesRecordTheFieldsAsNotRemoved(): void {
		$schema = new class {
			/**
			 * Whether a setter was reached.
			 *
			 * @var boolean
			 */
			public bool $written = false;

			/**
			 * @return null
			 */
			public function getProperties() {
				return null;
			}

			/**
			 * @param mixed $properties Ignored.
			 *
			 * @return void
			 */
			public function setProperties($properties): void {
				$this->written = true;
			}
		};
		$mapper = new class ($schema) {
			/**
			 * Slugs asked for.
			 *
			 * @var array<int, string>
			 */
			public array $found = [];

			/**
			 * Whether update() was called.
			 *
			 * @var boolean
			 */
			public bool $updated = false;

			/**
			 * @param object $schema The schema served.
			 */
			public function __construct(private object $schema) {
			}

			/**
			 * @param string $slug The slug.
			 *
			 * @return object
			 */
			public function find(string $slug): object {
				$this->found[] = $slug;
				return $this->schema;
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function update(object $schema): object {
				$this->updated = true;
				return $schema;
			}
		};

		$objectService = $this->createMock(OrObjectService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $mapper): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
					'OCA\\OpenRegister\\Db\\SchemaMapper' => $mapper,
				};
			}
		);

		$planner = $this->createMock(InlineSecretMigrationPlannerInterface::class);
		$planner->expects($this->once())->method('planAll')->willReturn(['wouldMigrate' => 0, 'needsReview' => 2]);
		$migrations = $this->createMock(InlineSecretMigrationFactoryInterface::class);
		$migrations->expects($this->once())->method('planner')->with($objectService)->willReturn($planner);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('setValueString')
			->with('integriq', RemoveMigratedSourceSecretFields::KEY_FIELDS_REMOVED, '0');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->expects($this->never())->method('warning');

		(new RemoveMigratedSourceSecretFields($container, $appConfig, $logger, $migrations))->run($output);

		$this->assertSame(['source'], $mapper->found);
		$this->assertFalse($mapper->updated);
		$this->assertFalse($schema->written);
	}//end testNonArrayPropertiesRecordTheFieldsAsNotRemoved()
}//end class
