<?php

/**
 * Unit tests for SynchronizationHandler export redaction (secret-hygiene).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-7 (Synchronization half) — SynchronizationHandler::export() redacts
 * configuration secrets.
 */
class SynchronizationHandlerTest extends TestCase {

	/**
	 * @var SynchronizationHandler
	 */
	private SynchronizationHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new SynchronizationHandler(
			ObjectServiceMockBuilder::make($this),
			new SensitiveFieldRegistry(),
		);
	}//end setUp()

	/**
	 * TC-7 — `configuration.token` is masked to ***REDACTED*** while
	 * non-secret configuration entries and the sourceId slug translation are
	 * left untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function testExportRedactsConfigurationToken(): void {
		$synchronization = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'my-sync',
				'sourceType' => 'api',
				'sourceId' => 'source-id-42',
				'configuration' => [
					'token' => 'live-sync-token-123',
					'pageSize' => 100,
				],
			],
			'sync-uuid-1'
		);

		$mappings = [
			'source' => [
				'idToSlug' => ['source-id-42' => 'my-source-slug'],
				'slugToId' => ['my-source-slug' => 'source-id-42'],
			],
		];

		$export = $this->handler->export($synchronization, $mappings);

		$this->assertSame('***REDACTED***', $export['configuration']['token']);
		$this->assertSame(100, $export['configuration']['pageSize']);

		// Pre-existing sourceId slug translation still works.
		$this->assertSame('my-source-slug', $export['sourceId']);

		$this->assertStringNotContainsString('live-sync-token-123', json_encode($export));
	}//end testExportRedactsConfigurationToken()
}//end class
