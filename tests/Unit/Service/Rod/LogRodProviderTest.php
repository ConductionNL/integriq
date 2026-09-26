<?php

/**
 * Unit tests for LogRodProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Service\Rod\LogRodProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox ROD provider.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class LogRodProviderTest extends TestCase {

	/**
	 * @var LogRodProvider
	 */
	private LogRodProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogRodProvider();

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
	 * send() returns a synthetic MOCK-ROD-<n> reference with no network call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function testSendReturnsSyntheticRef(): void {
		$ref = $this->provider->send([], 'inschrijving', 'kenmerk-1', '<RodBericht/>');
		$this->assertMatchesRegularExpression('/^MOCK-ROD-\d+$/', $ref);

	}//end testSendReturnsSyntheticRef()

	/**
	 * Each call returns a distinct incrementing reference.
	 *
	 * @return void
	 */
	public function testSendReturnsDistinctRefsAcrossCalls(): void {
		$first = $this->provider->send([], 'inschrijving', 'k1', '<RodBericht/>');
		$second = $this->provider->send([], 'inschrijving', 'k2', '<RodBericht/>');
		$this->assertNotSame($first, $second);

	}//end testSendReturnsDistinctRefsAcrossCalls()
}//end class
