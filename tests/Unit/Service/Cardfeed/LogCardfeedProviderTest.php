<?php

/**
 * Unit tests for LogCardfeedProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Cardfeed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Cardfeed;

use OCA\Integriq\Service\Cardfeed\LogCardfeedProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox card-feed provider: the full path with no network call and no secret.
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */
class LogCardfeedProviderTest extends TestCase {

	/**
	 * @var LogCardfeedProvider
	 */
	private LogCardfeedProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->provider = new LogCardfeedProvider();

	}//end setUp()

	/**
	 * listCards returns the canned card set with no configuration (no secret) required.
	 *
	 * @return void
	 */
	public function testListCardsReturnsCannedCardsWithoutConfiguration(): void {
		$cards = $this->provider->listCards(sourceConfiguration: []);

		$this->assertCount(1, $cards);
		$this->assertSame('SANDBOX-CARD-1', $cards[0]['cardId']);
		$this->assertSame('4242', $cards[0]['last4']);
		$this->assertSame('EUR', $cards[0]['currency']);

	}//end testListCardsReturnsCannedCardsWithoutConfiguration()

	/**
	 * listTransactions returns canned rows with STABLE ids per card so a replayed
	 * pull returns the same ids (the connector's dedup is what prevents re-emit).
	 *
	 * @return void
	 */
	public function testListTransactionsReturnsStableIdRows(): void {
		$first = $this->provider->listTransactions(
			sourceConfiguration: [],
			cardId: 'SANDBOX-CARD-1',
			since: '2026-06-30T00:00:00+00:00',
			until: '2026-07-01T00:00:00+00:00'
		);
		$second = $this->provider->listTransactions(
			sourceConfiguration: [],
			cardId: 'SANDBOX-CARD-1',
			since: '2026-07-01T00:00:00+00:00',
			until: '2026-07-02T00:00:00+00:00'
		);

		$this->assertCount(2, $first);
		$this->assertStringContainsString('SANDBOX-CARD-1', $first[0]['transactionId']);
		$this->assertArrayHasKey('transactionAmount', $first[0]);

		// Stable ids across windows — the idempotency guarantee relies on this.
		$this->assertSame(
			array_column($first, 'transactionId'),
			array_column($second, 'transactionId')
		);

	}//end testListTransactionsReturnsStableIdRows()
}//end class
