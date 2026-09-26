<?php

/**
 * Unit tests for LogVerzuimloketProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use OCA\Integriq\Service\Verzuimloket\LogVerzuimloketProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox Verzuimloket provider.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class LogVerzuimloketProviderTest extends TestCase {

	/**
	 * @var LogVerzuimloketProvider
	 */
	private LogVerzuimloketProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogVerzuimloketProvider();

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
	 * send() returns a synthetic MOCK-VERZUIM-<n> reference with no network call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function testSendReturnsSyntheticRef(): void {
		$ref = $this->provider->send([], 'eerste-melding', 'kenmerk-1', '<VerzuimloketMelding/>');
		$this->assertMatchesRegularExpression('/^MOCK-VERZUIM-\d+$/', $ref);

	}//end testSendReturnsSyntheticRef()

	/**
	 * Each call returns a distinct incrementing reference.
	 *
	 * @return void
	 */
	public function testSendReturnsDistinctRefsAcrossCalls(): void {
		$first = $this->provider->send([], 'eerste-melding', 'k1', '<VerzuimloketMelding/>');
		$second = $this->provider->send([], 'eerste-melding', 'k2', '<VerzuimloketMelding/>');
		$this->assertNotSame($first, $second);

	}//end testSendReturnsDistinctRefsAcrossCalls()
}//end class
