<?php

/**
 * Unit tests for MappingHandler export redaction (secret-hygiene).
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

use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-5 — MappingHandler::export() redacts a client_secret configuration value.
 */
class MappingHandlerTest extends TestCase {

	/**
	 * @var MappingHandler
	 */
	private MappingHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new MappingHandler(
			ObjectServiceMockBuilder::make($this),
			new SensitiveFieldRegistry(),
		);
	}//end setUp()

	/**
	 * TC-5 — `configuration.client_secret` is masked to ***REDACTED***.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function testExportRedactsClientSecretConfigurationValue(): void {
		$mapping = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'my-mapping',
				'name' => 'My Mapping',
				'configuration' => [
					'client_secret' => 'live-mapping-secret-123',
					'format' => 'json',
				],
			],
			'mapping-uuid-1'
		);

		$mappingIds = [];
		$export = $this->handler->export($mapping, [], $mappingIds);

		$this->assertSame('***REDACTED***', $export['configuration']['client_secret']);
		$this->assertSame('json', $export['configuration']['format']);
		$this->assertStringNotContainsString('live-mapping-secret-123', json_encode($export));
	}//end testExportRedactsClientSecretConfigurationValue()
}//end class
