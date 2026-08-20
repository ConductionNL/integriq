<?php

/**
 * Unit tests for EndpointHandler export redaction (secret-hygiene).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\ConfigurationHandlers;

use OCA\OpenConnector\Service\ConfigurationHandlers\EndpointHandler;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-4 — EndpointHandler::export() redacts an inline auth-override header
 * from the endpoint's configuration array.
 */
class EndpointHandlerTest extends TestCase {

	/**
	 * @var EndpointHandler
	 */
	private EndpointHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new EndpointHandler(
			ObjectServiceMockBuilder::make($this),
			new SensitiveFieldRegistry(),
		);
	}//end setUp()

	/**
	 * TC-4 — `configuration.headers.X-Api-Key` is masked to ***REDACTED***
	 * and the key itself remains present (masking, not omission).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function testExportRedactsInlineAuthOverrideHeader(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'my-endpoint',
				'configuration' => [
					'headers' => [
						'X-Api-Key' => 'live_endpoint_key_123',
						'Accept' => 'application/json',
					],
				],
			],
			'endpoint-uuid-1'
		);

		$export = $this->handler->export($endpoint, []);

		$this->assertArrayHasKey('X-Api-Key', $export['configuration']['headers']);
		$this->assertSame('***REDACTED***', $export['configuration']['headers']['X-Api-Key']);
		$this->assertSame('application/json', $export['configuration']['headers']['Accept']);
		$this->assertStringNotContainsString('live_endpoint_key_123', json_encode($export));
	}//end testExportRedactsInlineAuthOverrideHeader()
}//end class
