<?php

/**
 * ObjectRightsServiceTest
 *
 * The matrix's object rights land on the configuration schemas' OpenRegister
 * authorization blocks: a changed row as its groups with admin, an untouched
 * row as the register's own posture, and a refused schema named.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ObjectRightsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ObjectRightsService.
 */
class ObjectRightsServiceTest extends TestCase {

	/**
	 * A stand-in for OpenRegister's schema mapper holding schemas by slug.
	 *
	 * @param array<string, array<string, mixed>|null> $blocks  Slug to the schema's authorization block.
	 * @param array<int, string>                       $refuse  Slugs whose update throws.
	 *
	 * @return object The mapper, with public `schemas`, `asked` and `updated`.
	 */
	private function mapper(array $blocks, array $refuse = []): object {
		return new class ($blocks, $refuse) {
			/** @var array<string, object> */
			public array $schemas = [];

			/** @var array<int, array{0: string, 1: string}> */
			public array $asked = [];

			/** @var array<int, string> */
			public array $updated = [];

			/**
			 * @param array<string, array<string, mixed>|null> $blocks Slug to block.
			 * @param array<int, string>                       $refuse Slugs whose update throws.
			 */
			public function __construct(array $blocks, private array $refuse) {
				foreach ($blocks as $slug => $block) {
					$this->schemas[$slug] = new class ($slug, $block) {
						/**
						 * @param string     $slug          The slug.
						 * @param array|null $authorization The block.
						 */
						public function __construct(public string $slug, public ?array $authorization) {
						}

						/** @return array|null */
						public function getAuthorization(): ?array {
							return $this->authorization;
						}

						/** @param array|null $authorization The block. */
						public function setAuthorization(?array $authorization): void {
							$this->authorization = $authorization;
						}
					};
				}
			}

			/**
			 * @param string $slug        The slug.
			 * @param string $application The application.
			 *
			 * @return object|null
			 */
			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				$this->asked[] = [$slug, $application];
				return ($this->schemas[$slug] ?? null);
			}

			/**
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function update(object $schema): object {
				if (in_array($schema->slug, $this->refuse, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->updated[] = $schema->slug;
				return $schema;
			}
		};
	}//end mapper()

	/**
	 * The service over a mapper, with a logger mock.
	 *
	 * @param object               $mapper The mapper.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ObjectRightsService
	 */
	private function service(object $mapper, ?LoggerInterface $logger = null): ObjectRightsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Db\\SchemaMapper')->willReturn($mapper);
		return new ObjectRightsService(container: $container, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	public function testAGrantedGroupLandsOnTheSchemaWithAdmin(): void {
		$mapper = $this->mapper(['mapping' => null]);

		$refused = $this->service($mapper)->apply(['object.mapping.update' => ['integratie-beheer']]);

		$this->assertSame([], $refused);
		$this->assertSame(['update' => ['admin', 'integratie-beheer']], $mapper->schemas['mapping']->authorization);
		$this->assertSame([['mapping', 'integriq']], $mapper->asked, 'the schema is looked up as this app\'s own');
	}//end testAGrantedGroupLandsOnTheSchemaWithAdmin()

	public function testAnUntouchedOpenSchemaKeepsNoBlock(): void {
		$mapper = $this->mapper(['mapping' => null]);
		$matrix = [];
		foreach (ObjectRightsService::VERBS as $verb) {
			$matrix['object.mapping.' . $verb] = ['admin', 'authenticated'];
		}

		$this->assertSame([], $this->service($mapper)->apply($matrix));
		$this->assertSame([], $mapper->updated, 'the seeded posture is what OpenRegister already enforces');
		$this->assertNull($mapper->schemas['mapping']->authorization);
	}//end testAnUntouchedOpenSchemaKeepsNoBlock()

	public function testAnUntouchedLockedSchemaKeepsAdminOnly(): void {
		$locked = ['create' => ['admin'], 'read' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']];
		$mapper = $this->mapper(['source' => $locked]);
		$matrix = [];
		foreach (ObjectRightsService::VERBS as $verb) {
			$matrix['object.source.' . $verb] = ['admin'];
		}

		$this->assertSame([], $this->service($mapper)->apply($matrix));
		$this->assertSame([], $mapper->updated);
		$this->assertSame($locked, $mapper->schemas['source']->authorization);
	}//end testAnUntouchedLockedSchemaKeepsAdminOnly()

	public function testReturningARowToItsSeedRestoresTheRegistersPosture(): void {
		$mapper = $this->mapper(['mapping' => ['update' => ['admin', 'integratie-beheer'], 'read' => ['admin', 'x']]]);

		$this->service($mapper)->apply(['object.mapping.update' => ['authenticated', 'admin']]);

		// The block still holds `read`, and OpenRegister reads a verb missing from a
		// block as admin only, so the open verb is written out rather than dropped.
		$this->assertSame(
			expected: ['read' => ['admin', 'x'], 'update' => ['admin', 'authenticated']],
			actual: $mapper->schemas['mapping']->authorization,
			message: 'the verb is open to every signed-in user again, the verbs it does not govern stay'
		);
		$this->assertSame(expected: ['mapping'], actual: $mapper->updated);
	}//end testReturningARowToItsSeedRestoresTheRegistersPosture()

	/**
	 * Granting one verb to a team keeps the other open verbs open.
	 *
	 * Found live: granting `object.mapping.update` to a group wrote `{update: [admin, group]}`
	 * alone, and every non-admin lost read access to mappings (404), the team included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	public function testGrantingOneVerbKeepsTheOtherOpenVerbsOpen(): void {
		$mapper = $this->mapper(['mapping' => null]);
		$matrix = [];
		foreach (ObjectRightsService::VERBS as $verb) {
			$matrix['object.mapping.' . $verb] = ['admin', 'authenticated'];
		}

		$matrix['object.mapping.update'] = ['admin', 'integratie-beheer'];

		$this->service($mapper)->apply($matrix);

		$this->assertSame(
			expected: [
				'update' => ['admin', 'integratie-beheer'],
				'create' => ['admin', 'authenticated'],
				'read' => ['admin', 'authenticated'],
				'delete' => ['admin', 'authenticated'],
			],
			actual: $mapper->schemas['mapping']->authorization
		);
	}//end testGrantingOneVerbKeepsTheOtherOpenVerbsOpen()

	/**
	 * A schema whose every verb is back at its open seed has no block at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	public function testAllVerbsBackAtTheirOpenSeedLeaveNoBlock(): void {
		$mapper = $this->mapper(['mapping' => ['update' => ['admin', 'integratie-beheer']]]);
		$matrix = [];
		foreach (ObjectRightsService::VERBS as $verb) {
			$matrix['object.mapping.' . $verb] = ['admin', 'authenticated'];
		}

		$this->service($mapper)->apply($matrix);

		$this->assertSame(expected: [], actual: $mapper->schemas['mapping']->authorization);
	}//end testAllVerbsBackAtTheirOpenSeedLeaveNoBlock()

	public function testNarrowingAnOpenSchemaWritesTheGroups(): void {
		$mapper = $this->mapper(['job' => null]);

		$this->service($mapper)->apply(['object.job.delete' => ['admin']]);

		$this->assertSame(['delete' => ['admin']], $mapper->schemas['job']->authorization);
	}//end testNarrowingAnOpenSchemaWritesTheGroups()

	public function testKeysTheMatrixDoesNotGovernAreKept(): void {
		$mapper = $this->mapper(['rule' => ['read' => ['admin'], 'inheritFromPublic' => false, 'approve' => ['boss']]]);

		$this->service($mapper)->apply(['object.rule.read' => ['admin', 'ops', 'ops', '']]);

		$this->assertSame(['read' => ['admin', 'ops'], 'inheritFromPublic' => false, 'approve' => ['boss']], $mapper->schemas['rule']->authorization);
	}//end testKeysTheMatrixDoesNotGovernAreKept()

	public function testARefusedOrMissingSchemaIsNamedAndTheOthersApplied(): void {
		$mapper = $this->mapper(['mapping' => null, 'endpoint' => null], refuse: ['mapping']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$refused = $this->service($mapper, $logger)->apply(
			[
				'object.mapping.update' => ['ops'],
				'object.endpoint.update' => ['ops'],
				'object.api_product.update' => ['ops'],
			]
		);

		$this->assertSame(['mapping', 'api_product'], $refused);
		$this->assertSame(['endpoint'], $mapper->updated);
	}//end testARefusedOrMissingSchemaIsNamedAndTheOthersApplied()

	public function testRowsThatAreNotObjectRightsAreIgnored(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$service = new ObjectRightsService(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $service->apply(['job.run' => ['ops'], 'object.unknown.update' => ['ops'], 'object.mapping.approve' => ['ops']]));
	}//end testRowsThatAreNotObjectRightsAreIgnored()

	public function testWithoutOpenRegisterEverySchemaWithRowsIsNamed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$service = new ObjectRightsService(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(['source', 'mapping'], $service->apply(['object.mapping.read' => ['x'], 'object.source.read' => ['x']]));
	}//end testWithoutOpenRegisterEverySchemaWithRowsIsNamed()

	public function testTheSeedHoldsTheRegistersPostureForEveryObjectRight(): void {
		$seed    = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/lib/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);
		$locked  = ['source', 'rule', 'consumer'];
		foreach (ObjectRightsService::SCHEMAS as $slug) {
			foreach (ObjectRightsService::VERBS as $verb) {
				$expected = in_array($slug, $locked, true) === true ? ['admin'] : ['admin', 'authenticated'];
				$this->assertSame($expected, ($seed['actions']['object.' . $slug . '.' . $verb] ?? null), 'object.' . $slug . '.' . $verb);
				$this->assertSame('Configuration objects', $seed['areas']['object.' . $slug . '.' . $verb]);
			}
		}
	}//end testTheSeedHoldsTheRegistersPostureForEveryObjectRight()
}//end class
