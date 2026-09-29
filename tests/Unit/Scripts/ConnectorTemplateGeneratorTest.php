<?php

/**
 * The SaaS template generator against a small directory snapshot.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Scripts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Scripts;

use OCA\Integriq\Scripts\ConnectorTemplateGenerator;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ConnectorTemplateGenerator.php';

/**
 * @spec openspec/changes/connectors-catalogue-expansion/specs/connector-catalog/spec.md#requirement-generated-saas-templates-come-from-a-pinned-directory-and-a-reviewed-allow-list-req-ccx-003
 */
class ConnectorTemplateGeneratorTest extends TestCase {

	/**
	 * A directory index with two allow-listed entries and one that is not.
	 *
	 * @return array
	 */
	private function index(): array {
		return [
			'googleapis.com:sheets' => ['preferred' => 'v4', 'title' => 'Google Sheets API', 'categories' => ['analytics', 'media'], 'swaggerUrl' => 'https://api.apis.guru/v2/specs/googleapis.com/sheets/v4/openapi.json', 'updated' => '2023-04-21'],
			'slack.com' => ['preferred' => '1.7.0', 'title' => 'Slack Web API', 'categories' => ['collaboration', 'messaging'], 'swaggerUrl' => 'https://api.apis.guru/v2/specs/slack.com/1.7.0/openapi.json', 'updated' => '2021-06-21'],
			'example.com:shadow' => ['preferred' => '1', 'title' => 'Not on the list', 'categories' => [], 'swaggerUrl' => 'https://api.apis.guru/v2/specs/example.com/shadow/1/openapi.json', 'updated' => '2024-01-01'],
		];
	}//end index()

	/**
	 * Trimmed descriptions for all three.
	 *
	 * @return array
	 */
	private function specs(): array {
		$oauth = static fn (string $flow, array $urls): array => ['components' => ['securitySchemes' => ['oauth' => ['type' => 'oauth2', 'flows' => [$flow => $urls + ['scopes' => ['a' => 'b']]]]]]];
		return [
			'googleapis.com:sheets' => ['openapi' => '3.0.0', 'info' => ['title' => 'Google Sheets API', 'x-providerName' => 'googleapis.com'], 'externalDocs' => ['url' => 'https://developers.google.com/sheets/'], 'servers' => [['url' => 'https://sheets.googleapis.com/']]]
				+ $oauth('implicit', ['authorizationUrl' => 'https://accounts.google.com/o/oauth2/auth']),
			'slack.com' => ['openapi' => '3.0.0', 'info' => ['title' => 'Slack Web API', 'x-providerName' => 'slack.com'], 'servers' => [['url' => 'https://slack.com/api']]]
				+ $oauth('authorizationCode', ['authorizationUrl' => 'https://slack.com/oauth/authorize', 'tokenUrl' => 'https://slack.com/api/oauth.access']),
			'example.com:shadow' => ['openapi' => '3.0.0', 'info' => ['title' => 'Shadow'], 'servers' => [['url' => 'https://shadow.example.com']], 'components' => ['securitySchemes' => ['key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key']]]],
		];
	}//end specs()

	/**
	 * The first allow-list.
	 *
	 * @return array
	 */
	private function allowList(): array {
		return [
			['key' => 'googleapis.com:sheets', 'slug' => 'google-sheets', 'name' => 'Google Sheets', 'category' => 'Spreadsheets'],
			['key' => 'slack.com', 'slug' => 'slack', 'name' => 'Slack', 'category' => 'Messaging'],
			['key' => 'not-in.snapshot', 'slug' => 'ghost'],
		];
	}//end allowList()

	/**
	 * REQ-CCX-003: one template per allow-listed entry, with base URL, auth
	 * scheme and snapshot date.
	 *
	 * @return void
	 */
	public function testTheAllowListedEntriesAreWritten(): void {
		$templates = (new ConnectorTemplateGenerator())->generate($this->index(), $this->specs(), $this->allowList(), '2026-09-29');

		$this->assertSame(['google-sheets', 'slack'], array_keys($templates));

		$sheets = $templates['google-sheets'];
		$this->assertSame('https://sheets.googleapis.com', $sheets['source']['location']);
		$this->assertSame('oauth', $sheets['source']['auth']);
		$this->assertSame('generated', $sheets['x-template']['tier']);
		$this->assertSame('2026-09-29', $sheets['x-template']['snapshotDate']);
		$this->assertSame('https://api.apis.guru/v2/specs/googleapis.com/sheets/v4/openapi.json', $sheets['x-template']['verifiedAgainst']);
		$this->assertSame('Spreadsheets', $sheets['x-template']['category']);
		$this->assertStringContainsString('OAuth 2.0 (implicit)', $sheets['source']['description']);
		$this->assertFalse($sheets['source']['isEnabled']);

		$this->assertSame('https://slack.com/api/oauth.access', $templates['slack']['source']['configuration']['authentication']['tokenUrl']);
	}//end testTheAllowListedEntriesAreWritten()

	/**
	 * REQ-CCX-003: an entry off the allow-list gets no template, and an
	 * allow-listed key the snapshot lacks is skipped rather than invented.
	 *
	 * @return void
	 */
	public function testNothingOffTheAllowListIsWritten(): void {
		$templates = (new ConnectorTemplateGenerator())->generate($this->index(), $this->specs(), $this->allowList(), '2026-09-29');
		$names = array_column(array_column($templates, 'source'), 'name');

		$this->assertNotContains('Shadow', $names);
		$this->assertArrayNotHasKey('ghost', $templates);
	}//end testNothingOffTheAllowListIsWritten()

	/**
	 * REQ-CCX-003: a generated source is one the register accepts, and it
	 * carries no credential.
	 *
	 * @return void
	 */
	public function testAGeneratedSourceIsAcceptedAndCarriesNoCredential(): void {
		$templates = (new ConnectorTemplateGenerator())->generate($this->index(), $this->specs(), $this->allowList(), '2026-09-29');

		foreach ($templates as $slug => $template) {
			$this->assertSame([], RegisterSchemaValidator::errors('source', $template['source']), $slug);
			$encoded = (string)json_encode($template['source']);
			foreach (['client_secret', 'password', 'apikey', 'secret"'] as $secretKey) {
				$this->assertStringNotContainsString('"' . trim($secretKey, '"') . '"', $encoded, $slug);
			}
		}
	}//end testAGeneratedSourceIsAcceptedAndCarriesNoCredential()

	/**
	 * The committed snapshot keeps servers and schemes, not scope lists.
	 *
	 * @return void
	 */
	public function testTrimKeepsWhatATemplateReads(): void {
		$trimmed = (new ConnectorTemplateGenerator())->trimSpec(
			['openapi' => '3.0.0', 'info' => ['title' => 'T', 'description' => 'long'], 'paths' => ['/a' => []], 'servers' => [['url' => 'https://t']]]
			+ ['components' => ['schemas' => ['X' => []], 'securitySchemes' => ['o' => ['type' => 'oauth2', 'description' => 'd', 'flows' => ['implicit' => ['authorizationUrl' => 'u', 'scopes' => ['s' => 's']]]]]]]
		);

		$this->assertArrayNotHasKey('paths', $trimmed);
		$this->assertSame(['title' => 'T'], $trimmed['info']);
		$this->assertSame(['securitySchemes' => ['o' => ['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'u']]]]], $trimmed['components']);
	}//end testTrimKeepsWhatATemplateReads()
}//end class
