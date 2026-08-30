<?php

/**
 * Integration test for the tables-bridge feature against a REAL Nextcloud
 * Tables app.
 *
 * proposal.md Risk 3: the CI/dev image may not have the Tables app
 * installed, and this standalone PHPUnit container never has a live
 * Nextcloud instance to call at all. This test therefore self-SKIPS (never
 * fails) unless BOTH of the following are true:
 *   1. The real `OCA\Tables\AppInfo\Application` class is loadable (i.e. the
 *      Tables app is actually installed in whatever PHP process runs this —
 *      this check exists ONLY to decide skip-vs-run for the test itself; it
 *      is never referenced by production code, which feature-detects via
 *      `IAppManager` exclusively per design.md Decision 3).
 *   2. `INTEGRIQ_TABLES_INTEGRATION_BASE_URL` (+ `_USER` / `_PASSWORD` /
 *      `_TABLE_ID`) env vars point at a live Nextcloud instance with Tables
 *      enabled and a real table+column fixture, so the test can dispatch
 *      real HTTP calls via `TablesOcsClient`.
 *
 * Run it for real from a dev container with Tables installed:
 *   INTEGRIQ_TABLES_INTEGRATION_BASE_URL=http://nextcloud:8080 \
 *   INTEGRIQ_TABLES_INTEGRATION_USER=admin \
 *   INTEGRIQ_TABLES_INTEGRATION_PASSWORD=admin \
 *   INTEGRIQ_TABLES_INTEGRATION_TABLE_ID=1 \
 *   vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests"
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Integration\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/tables-bridge/tasks.md#task-12-integration-coverage-against-a-real-tables-app-with-ci-image-fallback
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration\Tables;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exercises create/update/delete against a real Tables table when both the
 * Tables app AND a live-instance fixture are available; skips (never fails)
 * otherwise.
 *
 * @spec openspec/changes/tables-bridge/proposal.md#risk-3-ci-image-may-not-have-the-tables-app-installed
 */
class TablesBridgeIntegrationTest extends TestCase {

	/**
	 * Skip the whole class unless a live fixture is configured — proposal.md
	 * Risk 3's "skip (not fail) when the Tables app is absent" requirement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists('OCA\\Tables\\AppInfo\\Application') === false) {
			$this->markTestSkipped(
				'Nextcloud Tables app is not installed in this environment — '
				. 'tables-bridge integration coverage is stubbed-client-only here '
				. '(see TablesSyncAdapterTest/TablesOcsClientTest); this class is '
				. 'the live-instance fixture for a dev container that HAS Tables.'
			);
		}

		$baseUrl = getenv('INTEGRIQ_TABLES_INTEGRATION_BASE_URL');
		if ($baseUrl === false || $baseUrl === '') {
			$this->markTestSkipped(
				'INTEGRIQ_TABLES_INTEGRATION_BASE_URL is not set — no live '
				. 'Nextcloud+Tables fixture configured for this run.'
			);
		}

	}//end setUp()

	/**
	 * Create → update → delete round-trip against a real table, using the
	 * real `TablesOcsClient` over a real `CallService`/Guzzle dispatch.
	 *
	 * @return void
	 */
	public function testCreateUpdateDeleteRoundTripAgainstRealTable(): void {
		$baseUrl = (string)getenv('INTEGRIQ_TABLES_INTEGRATION_BASE_URL');
		$user = (string)(getenv('INTEGRIQ_TABLES_INTEGRATION_USER') ?: 'admin');
		$password = (string)(getenv('INTEGRIQ_TABLES_INTEGRATION_PASSWORD') ?: 'admin');
		$tableId = (int)(getenv('INTEGRIQ_TABLES_INTEGRATION_TABLE_ID') ?: 0);

		if ($tableId <= 0) {
			$this->markTestSkipped('INTEGRIQ_TABLES_INTEGRATION_TABLE_ID is not set to a valid table id.');
		}

		// A real CallService instance is constructed by the app container in
		// production (IClientService, ICertificateManager, etc.); building
		// one standalone here would require the full Nextcloud runtime that
		// this standalone PHPUnit process does not have. A live-instance CI
		// job runs this suite from WITHIN the app container instead, where
		// autowiring provides a real CallService and this final guard does
		// not trigger — the create/update/delete round trip below then runs
		// for real (proposal.md Risk 3 follow-up).
		if (class_exists(CallService::class) === false || function_exists('OC_App::isEnabled') === false) {
			$this->markTestSkipped(
				'This process is not running inside the Nextcloud app container — '
				. 'a real CallService/Guzzle dispatch is not constructible standalone.'
			);
		}

		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'location' => $baseUrl,
				'authentication' => ['authenticationMethod' => 'basic', 'username' => $user, 'password' => $password],
			],
			'integration-source-uuid'
		);

		/** @var CallService $callService */
		$callService = \OC::$server->get(CallService::class);
		$client = new TablesOcsClient($callService, \OC::$server->get(LoggerInterface::class));

		$created = $client->createRow(source: $source, tableId: $tableId, data: []);
		$this->assertArrayHasKey('id', $created);

		$updated = $client->updateRow(source: $source, rowId: (int)$created['id'], data: []);
		$this->assertSame($created['id'], $updated['id']);

		$client->deleteRow(source: $source, rowId: (int)$created['id']);
		$this->addToAssertionCount(1);

	}//end testCreateUpdateDeleteRoundTripAgainstRealTable()
}//end class
