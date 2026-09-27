<?php

/**
 * Unit tests for LogOsoProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Oso;

use OCA\Integriq\Service\Oso\LogOsoProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox OSO export provider.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */
class LogOsoProviderTest extends TestCase {

	/**
	 * @var LogOsoProvider
	 */
	private LogOsoProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogOsoProvider();

	}//end setUp()

	/**
	 * getProviderId() returns "log".
	 *
	 * @return void
	 */
	public function testGetProviderIdReturnsLog(): void {
		$this->assertSame('log', $this->provider->getProviderId());

	}//end testGetProviderIdReturnsLog()

	/**
	 * getConfigSchema() needs no configuration.
	 *
	 * @return void
	 */
	public function testGetConfigSchemaIsEmpty(): void {
		$schema = $this->provider->getConfigSchema();
		$this->assertSame('object', $schema['type']);
		$this->assertSame([], $schema['properties']);

	}//end testGetConfigSchemaIsEmpty()

	/**
	 * sendExport() returns a synthetic MOCK-OSO-<n> reference with no network call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function testSendExportReturnsSyntheticRef(): void {
		$ref = $this->provider->sendExport([], 'kenmerk-1', '<OsoOverstapdossier/>');
		$this->assertMatchesRegularExpression('/^MOCK-OSO-\d+$/', $ref);

	}//end testSendExportReturnsSyntheticRef()

	/**
	 * Each call returns a distinct incrementing reference.
	 *
	 * @return void
	 */
	public function testSendExportReturnsDistinctRefsAcrossCalls(): void {
		$first = $this->provider->sendExport([], 'k1', '<OsoOverstapdossier/>');
		$second = $this->provider->sendExport([], 'k2', '<OsoOverstapdossier/>');
		$this->assertNotSame($first, $second);

	}//end testSendExportReturnsDistinctRefsAcrossCalls()
}//end class
