<?php

/**
 * Unit tests for LogPsd2AggregatorProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Psd2;

use OCA\Integriq\Service\Psd2\LogPsd2AggregatorProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox PSD2 aggregator provider: the full path with no network call and no secret.
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */
class LogPsd2AggregatorProviderTest extends TestCase {

	/**
	 * @var LogPsd2AggregatorProvider
	 */
	private LogPsd2AggregatorProvider $provider;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->provider = new LogPsd2AggregatorProvider();

	}//end setUp()

	/**
	 * createRequisition returns a synthetic reference + canned SCA redirect URL,
	 * with NO configuration (and therefore no credential) required.
	 *
	 * @return void
	 */
	public function testCreateRequisitionReturnsCannedScaUrlWithoutConfiguration(): void {
		$result = $this->provider->createRequisition(
			sourceConfiguration: [],
			institutionId: 'SANDBOX_BANK',
			redirectUrl: 'https://nc.example/return'
		);

		$this->assertMatchesRegularExpression('/^REQ-SANDBOX-\d+$/', $result['reference']);
		$this->assertStringStartsWith('https://sandbox.bank.example/psd2/sca/', $result['redirectUrl']);
		$this->assertStringContainsString('SANDBOX_BANK', $result['redirectUrl']);
		$this->assertStringContainsString($result['reference'], $result['redirectUrl']);

	}//end testCreateRequisitionReturnsCannedScaUrlWithoutConfiguration()

	/**
	 * Consecutive requisitions get distinct references.
	 *
	 * @return void
	 */
	public function testCreateRequisitionReferencesAreDistinct(): void {
		$first = $this->provider->createRequisition(sourceConfiguration: [], institutionId: 'A', redirectUrl: 'https://a');
		$second = $this->provider->createRequisition(sourceConfiguration: [], institutionId: 'B', redirectUrl: 'https://b');

		$this->assertNotSame($first['reference'], $second['reference']);

	}//end testCreateRequisitionReferencesAreDistinct()

	/**
	 * finaliseConsent returns the reference, a ~90-day expiry, canned accounts, and NO token (REQ-002).
	 *
	 * @return void
	 */
	public function testFinaliseConsentReturnsCannedConsentWithoutToken(): void {
		$consent = $this->provider->finaliseConsent(sourceConfiguration: [], reference: 'REQ-SANDBOX-1');

		$this->assertSame('REQ-SANDBOX-1', $consent['consentReference']);
		$this->assertNull($consent['consentToken']);
		$this->assertNotEmpty($consent['accounts']);
		$this->assertSame('NL00BANK0000000001', $consent['accounts'][0]['iban']);
		$this->assertSame('SANDBOX-ACC-1', $consent['accounts'][0]['aggregatorAccountId']);

		$expiresAt = new \DateTime($consent['consentExpiresAt']);
		$now = new \DateTime();
		$days = (int)$now->diff($expiresAt)->format('%a');
		$this->assertGreaterThanOrEqual(89, $days);
		$this->assertLessThanOrEqual(90, $days);

	}//end testFinaliseConsentReturnsCannedConsentWithoutToken()

	/**
	 * listAccounts returns the canned account set with IBAN/BIC/currency/account-id.
	 *
	 * @return void
	 */
	public function testListAccountsReturnsCannedAccounts(): void {
		$accounts = $this->provider->listAccounts(sourceConfiguration: [], consentReference: 'REQ-SANDBOX-1');

		$this->assertCount(1, $accounts);
		$this->assertSame('NL00BANK0000000001', $accounts[0]['iban']);
		$this->assertSame('BANKNL2A', $accounts[0]['bic']);
		$this->assertSame('EUR', $accounts[0]['currency']);
		$this->assertSame('SANDBOX-ACC-1', $accounts[0]['aggregatorAccountId']);

	}//end testListAccountsReturnsCannedAccounts()

	/**
	 * listTransactions returns canned aggregator-shaped rows tied to the window and account.
	 *
	 * @return void
	 */
	public function testListTransactionsReturnsCannedRows(): void {
		$rows = $this->provider->listTransactions(
			sourceConfiguration: [],
			accountId: 'SANDBOX-ACC-1',
			since: '2026-06-30T00:00:00+00:00',
			until: '2026-07-01T00:00:00+00:00'
		);

		$this->assertCount(2, $rows);
		$this->assertSame('2026-06-30', $rows[0]['bookingDate']);
		$this->assertStringContainsString('SANDBOX-ACC-1', $rows[0]['transactionId']);
		$this->assertArrayHasKey('transactionAmount', $rows[0]);

	}//end testListTransactionsReturnsCannedRows()
}//end class
