<?php

/**
 * Unit tests for RuleHandler export redaction (secret-hygiene).
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

use OCA\OpenConnector\Service\ConfigurationHandlers\RuleHandler;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TC-6 — RuleHandler::export() redacts a deeply nested Authorization value
 * without disturbing the existing convertIdsToSlugs() slug translation.
 */
class RuleHandlerTest extends TestCase {

	/**
	 * @var RuleHandler
	 */
	private RuleHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new RuleHandler(
			ObjectServiceMockBuilder::make($this),
			new SensitiveFieldRegistry(),
		);
	}//end setUp()

	/**
	 * TC-6 — `configuration.action.headers.Authorization` is masked at any
	 * nesting depth, AND `configuration.sourceId` is still translated to its
	 * slug by the pre-existing convertIdsToSlugs() pass (the two passes are
	 * independent and do not interfere).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function testExportRedactsNestedAuthorizationAndKeepsSlugTranslation(): void {
		$rule = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug' => 'my-rule',
				'configuration' => [
					'action' => [
						'headers' => ['Authorization' => 'Bearer live_rule_token'],
						'method' => 'POST',
					],
					'sourceId' => 'source-id-42',
				],
			],
			'rule-uuid-1'
		);

		$mappings = [
			'source' => [
				'idToSlug' => ['source-id-42' => 'my-source-slug'],
				'slugToId' => ['my-source-slug' => 'source-id-42'],
			],
		];

		$mappingIds = [];
		$export = $this->handler->export($rule, $mappings, $mappingIds);

		// Nested secret masked regardless of depth.
		$this->assertSame('***REDACTED***', $export['configuration']['action']['headers']['Authorization']);

		// Non-secret sibling untouched.
		$this->assertSame('POST', $export['configuration']['action']['method']);

		// Slug translation of the id-reference key is unaffected by redaction.
		$this->assertSame('my-source-slug', $export['configuration']['sourceId']);

		// No plaintext secret survives anywhere.
		$this->assertStringNotContainsString('live_rule_token', json_encode($export));
	}//end testExportRedactsNestedAuthorizationAndKeepsSlugTranslation()
}//end class
