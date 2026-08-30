<?php

/**
 * Unit tests for LogSmsProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/notifynl-sms-channel/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Sms;

use OCA\Integriq\Service\Sms\LogSmsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox/mock SMS provider.
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#scenario-the-log-provider-sends-without-a-network-call-or-secret
 */
class LogSmsProviderTest extends TestCase {

	/**
	 * @var LogSmsProvider
	 */
	private LogSmsProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new LogSmsProvider();

	}//end setUp()

	/**
	 * getProviderId returns the stable `log` identifier.
	 *
	 * @return void
	 */
	public function testGetProviderId(): void {
		$this->assertSame('log', $this->provider->getProviderId());

	}//end testGetProviderId()

	/**
	 * send() never needs configuration/secret and returns a synthetic queued result.
	 *
	 * @return void
	 */
	public function testSendReturnsSyntheticQueuedResult(): void {
		$result = $this->provider->send(sourceConfiguration: [], to: '+31612345678', body: 'hello');

		$this->assertSame('queued', $result->status);
		$this->assertStringStartsWith('MOCK-SMS-', $result->providerMessageId);

	}//end testSendReturnsSyntheticQueuedResult()

	/**
	 * Successive sends yield distinct synthetic ids.
	 *
	 * @return void
	 */
	public function testSendYieldsDistinctIds(): void {
		$first = $this->provider->send(sourceConfiguration: [], to: '+31612345678', body: 'a');
		$second = $this->provider->send(sourceConfiguration: [], to: '+31612345678', body: 'b');

		$this->assertNotSame($first->providerMessageId, $second->providerMessageId);

	}//end testSendYieldsDistinctIds()

	/**
	 * fetchStatus() always reports a deterministic delivered result.
	 *
	 * @return void
	 */
	public function testFetchStatusReportsDelivered(): void {
		$result = $this->provider->fetchStatus(sourceConfiguration: [], providerMessageId: 'MOCK-SMS-1');

		$this->assertSame('delivered', $result->status);
		$this->assertSame('MOCK-SMS-1', $result->providerMessageId);

	}//end testFetchStatusReportsDelivered()
}//end class
