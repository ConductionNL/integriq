<?php

/**
 * ApplyObjectRightsTest
 *
 * After a register import the stored object rights go back onto the schemas,
 * and the step never throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\ApplyObjectRights;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ObjectRightsService;
use OCA\Integriq\Service\ObjectRightsServiceInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the ApplyObjectRights repair step.
 */
class ApplyObjectRightsTest extends TestCase {

	/**
	 * The step over a stored matrix and an object rights writer.
	 *
	 * @param array<string, array<int, string>> $matrix       The stored matrix.
	 * @param ObjectRightsServiceInterface      $objectRights The writer.
	 * @param LoggerInterface|null              $logger       The logger.
	 *
	 * @return ApplyObjectRights
	 */
	private function step(array $matrix, ObjectRightsServiceInterface $objectRights, ?LoggerInterface $logger = null): ApplyObjectRights {
		$actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$actionAuth->method('getMatrix')->willReturn($matrix);
		return new ApplyObjectRights(actionAuth: $actionAuth, objectRights: $objectRights, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end step()

	public function testAnImportedSchemaGetsTheDelegatedRightBack(): void {
		// The import wrote the register's block: no update entry for mapping.
		$schema = new class {
			/** @var array|null */
			public ?array $authorization = ['read' => ['admin', 'authenticated']];

			/** @return array|null */
			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			/** @param array|null $authorization The block. */
			public function setAuthorization(?array $authorization): void {
				$this->authorization = $authorization;
			}
		};
		$mapper = new class ($schema) {
			/** @var int */
			public int $updates = 0;

			/** @param object $schema The mapping schema. */
			public function __construct(private object $schema) {
			}

			/**
			 * @param string $slug        The slug.
			 * @param string $application The application.
			 *
			 * @return object|null
			 */
			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				return ($slug === 'mapping' && $application === 'integriq') ? $this->schema : null;
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function update(object $schema): object {
				$this->updates++;
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$service = new ObjectRightsService(container: $container, logger: $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Integriq: object rights applied to the configuration schemas.');

		$this->step(['object.mapping.update' => ['admin', 'integratie-beheer']], $service)->run($output);

		$this->assertSame(['read' => ['admin', 'authenticated'], 'update' => ['admin', 'integratie-beheer']], $schema->authorization);
		$this->assertSame(1, $mapper->updates);
	}//end testAnImportedSchemaGetsTheDelegatedRightBack()

	public function testASchemaNotAppliedIsReported(): void {
		$objectRights = $this->createMock(ObjectRightsServiceInterface::class);
		$objectRights->expects($this->once())->method('apply')->with(['object.job.read' => ['x']])->willReturn(['job']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Integriq: object rights not applied to: job; tried again on the next run.');
		$output->expects($this->never())->method('info');

		$this->step(['object.job.read' => ['x']], $objectRights)->run($output);
	}//end testASchemaNotAppliedIsReported()

	public function testAFailureIsLoggedAndNeverThrown(): void {
		$objectRights = $this->createMock(ObjectRightsServiceInterface::class);
		$objectRights->method('apply')->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Integriq: ApplyObjectRights failed', ['exception' => 'boom']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Integriq: applying the object rights failed: boom');

		$this->step([], $objectRights, $logger)->run($output);
	}//end testAFailureIsLoggedAndNeverThrown()

	public function testItHasAName(): void {
		$step = $this->step([], $this->createMock(ObjectRightsServiceInterface::class));
		$this->assertSame('Apply the Integriq object rights to the configuration schemas', $step->getName());
	}//end testItHasAName()
}//end class
