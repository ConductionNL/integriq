<?php

/**
 * Unit tests for LogUwlrEduVProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use OCA\Integriq\Service\UwlrEduV\LogUwlrEduVProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox UWLR/Edu-V provider.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
 */
class LogUwlrEduVProviderTest extends TestCase {

	/**
	 * @var LogUwlrEduVProvider
	 */
	private LogUwlrEduVProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogUwlrEduVProvider();

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
	 * send() returns a synthetic MOCK-UWLREDUV-<n> reference with no network call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
	 */
	public function testSendReturnsSyntheticRef(): void {
		$ref = $this->provider->send([], 'uwlr', 'kenmerk-1', '<UwlrExport/>');
		$this->assertMatchesRegularExpression('/^MOCK-UWLREDUV-\d+$/', $ref);

	}//end testSendReturnsSyntheticRef()

	/**
	 * Each call returns a distinct incrementing reference.
	 *
	 * @return void
	 */
	public function testSendReturnsDistinctRefsAcrossCalls(): void {
		$first = $this->provider->send([], 'uwlr', 'k1', '<UwlrExport/>');
		$second = $this->provider->send([], 'edu-v', 'k2', '<EduVExport/>');
		$this->assertNotSame($first, $second);

	}//end testSendReturnsDistinctRefsAcrossCalls()
}//end class
