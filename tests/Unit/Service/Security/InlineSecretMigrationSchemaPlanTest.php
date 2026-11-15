<?php

/**
 * Planning the inline-secret migration per schema and across every
 * migratable schema, and the per-schema field and reference lookups.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Tests\Helpers\RenderBoundarySimulatingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InlineSecretMigrationPlanner::planSchema(), planEverything(), fieldsFor() and referenceFieldFor().
 *
 * @covers \OCA\Integriq\Service\Security\InlineSecretMigrationPlanner
 */
class InlineSecretMigrationSchemaPlanTest extends TestCase {

	/**
	 * The object store double.
	 *
	 * @var RenderBoundarySimulatingObjectService
	 */
	private RenderBoundarySimulatingObjectService $objects;

	/**
	 * The planner under test.
	 *
	 * @var InlineSecretMigrationPlanner
	 */
	private InlineSecretMigrationPlanner $planner;

	/**
	 * Wire the planner over the store double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new RenderBoundarySimulatingObjectService();
		$this->planner = new InlineSecretMigrationPlanner(
			objectService: $this->objects,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The sender identity schema declares its key field and where its reference goes; source writes in place.
	 *
	 * @return void
	 */
	public function testFieldsAndReferencesPerSchema(): void {
		$this->assertSame(expected: ['smimePrivateKey'], actual: $this->planner->fieldsFor(schema: 'sender_identity'));
		$this->assertSame(expected: InlineSecretMigrationPlanner::SECRET_FIELDS, actual: $this->planner->fieldsFor(schema: 'source'));
		$this->assertSame(expected: [], actual: $this->planner->fieldsFor(schema: 'unknown'));
		$this->assertSame(
			expected: 'smimePrivateKeyRef',
			actual: $this->planner->referenceFieldFor(schema: 'sender_identity', field: 'smimePrivateKey')
		);
		$this->assertNull(actual: $this->planner->referenceFieldFor(schema: 'source', field: 'apikey'));
		$this->assertNull(actual: $this->planner->referenceFieldFor(schema: 'sender_identity', field: 'apikey'));
	}//end testFieldsAndReferencesPerSchema()

	/**
	 * A schema plan lists only objects with something to report, but counts every listed object.
	 *
	 * @return void
	 */
	public function testASchemaPlanReportsOnlyObjectsWithSecrets(): void {
		$this->objects->stored['id-1'] = ['name' => 'Signer', 'smimePrivateKey' => 'PRIVATE KEY MATERIAL'];
		$this->objects->stored['id-2'] = ['name' => 'No key yet', 'smimePrivateKey' => ''];
		$this->objects->stored['id-3'] = ['name' => 'Other fields', 'apikey' => 'not this schema'];

		$plan = $this->planner->planSchema(schema: 'sender_identity');

		$this->assertSame(expected: 'sender_identity', actual: $plan['schema']);
		$this->assertSame(expected: 3, actual: $plan['totalObjects']);
		$this->assertSame(expected: 1, actual: $plan['wouldMigrate']);
		$this->assertSame(expected: 0, actual: $plan['needsReview']);
		$this->assertFalse(condition: $plan['clean']);
		$this->assertSame(
			expected: [
				[
					'uuid' => 'id-1',
					'name' => 'Signer',
					'fields' => [['field' => 'smimePrivateKey', 'state' => 'would-migrate', 'provider' => 'generic-apikey']],
					'wouldMigrate' => 1,
					'needsReview' => 0,
				],
			],
			actual: $plan['objects']
		);
	}//end testASchemaPlanReportsOnlyObjectsWithSecrets()

	/**
	 * A schema with nothing inline is clean.
	 *
	 * @return void
	 */
	public function testASchemaWithoutInlineSecretsIsClean(): void {
		$this->objects->stored['id-1'] = ['name' => 'Signer'];

		$plan = $this->planner->planSchema(schema: 'sender_identity');

		$this->assertTrue(condition: $plan['clean']);
		$this->assertSame(expected: [], actual: $plan['objects']);
		$this->assertSame(expected: 1, actual: $plan['totalObjects']);
	}//end testASchemaWithoutInlineSecretsIsClean()

	/**
	 * The whole estate sums every schema, and is clean only when every schema is.
	 *
	 * @return void
	 */
	public function testTheEstateSumsEverySchema(): void {
		$this->objects->stored['src-1'] = ['name' => 'Source', 'apikey' => 'inline', 'authenticationConfig' => ['x' => 'y']];
		$this->objects->stored['sid-1'] = ['name' => 'Signer', 'smimePrivateKey' => 'PRIVATE KEY MATERIAL'];

		$estate = $this->planner->planEverything();

		$this->assertSame(expected: ['source', 'sender_identity'], actual: array_keys($estate['schemas']));
		$this->assertSame(expected: 1, actual: $estate['schemas']['source']['wouldMigrate']);
		$this->assertSame(expected: 1, actual: $estate['schemas']['source']['needsReview']);
		$this->assertSame(expected: 1, actual: $estate['schemas']['sender_identity']['wouldMigrate']);
		$this->assertSame(expected: 2, actual: $estate['wouldMigrate']);
		$this->assertSame(expected: 1, actual: $estate['needsReview']);
		$this->assertFalse(condition: $estate['clean']);
	}//end testTheEstateSumsEverySchema()

	/**
	 * An empty estate is clean.
	 *
	 * @return void
	 */
	public function testAnEmptyEstateIsClean(): void {
		$estate = $this->planner->planEverything();

		$this->assertSame(expected: 0, actual: $estate['wouldMigrate']);
		$this->assertSame(expected: 0, actual: $estate['needsReview']);
		$this->assertTrue(condition: $estate['clean']);
	}//end testAnEmptyEstateIsClean()
}//end class
