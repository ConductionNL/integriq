<?php

/**
 * Unit tests for ApiDocumentExport, its settings and its job action.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ExternalApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ExternalApi;

use InvalidArgumentException;
use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;
use OCA\Integriq\Service\ExternalApi\ApiDocumentExport;
use OCA\Integriq\Service\ExternalApi\ApiDocumentExportSettings;
use OCA\Integriq\Service\Job\ApiDocumentExportAction;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentReader;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The document export fills `openApiDocument` from the gateway's export (REQ-FEDG-003, design D4).
 */
final class ApiDocumentExportTest extends TestCase {

	/**
	 * The Azure gateway's slug.
	 */
	private const GATEWAY = 'azure-apim-discovery';

	/**
	 * The recorded API id the export fixture answers for.
	 */
	private const API_ID = '/subscriptions/00000000-0000-0000-0000-000000000001/resourceGroups/rg-api'
		. '/providers/Microsoft.ApiManagement/service/apim-gemeente/apis/vergunningen';

	/**
	 * Calls made through the source: [slug, endpoint, config].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Saves made: [object, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string}>
	 */
	private array $saves = [];

	/**
	 * Links fetched through the reader.
	 *
	 * @var list<string>
	 */
	private array $fetched = [];

	/**
	 * A recorded fixture, decoded.
	 *
	 * @param string $name The file under tests/fixtures/federated-api/.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(string $name): array {
		$path = dirname(__DIR__, 4) . '/tests/fixtures/federated-api/' . $name;

		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * The Azure job row the fragment ships.
	 *
	 * @return array<string, mixed>
	 */
	private static function azureDocumentsJob(): array {
		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/azure-apim-discovery.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if ($object['@self']['slug'] === 'azure-apim-discovery-documents-daily') {
				return $object;
			}
		}

		self::fail(message: 'The Azure fragment ships no documents job.');
	}//end azureDocumentsJob()

	/**
	 * An inventory row.
	 *
	 * @param string               $uuid The object's uuid.
	 * @param array<string, mixed> $data The row's values.
	 *
	 * @return ObjectEntity
	 */
	private static function api(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(
			array_merge(
				['gateway' => self::GATEWAY, 'vendor' => 'azure-apim', 'name' => 'Vergunningen', 'version' => 'v2', 'gatewayApiId' => self::API_ID],
				$data
			)
		);

		return $entity;
	}//end api()

	/**
	 * The export over doubles of the source call, the inventory and the link reader.
	 *
	 * @param list<ObjectEntity>                     $rows    The rows the inventory holds.
	 * @param array<string, mixed>|\Throwable        $answer  The gateway's export answer, or what the call throws.
	 * @param array<string, mixed>|\Throwable|null   $linked  The document behind the link, or what the fetch throws.
	 *
	 * @return ApiDocumentExport
	 */
	private function export(array $rows, array|\Throwable $answer, array|\Throwable|null $linked = null): ApiDocumentExport {
		$sourceCall = $this->createMock(originalClassName: SupplierSourceCall::class);
		$sourceCall->method('json')->willReturnCallback(
			function (string $slug, string $endpoint, string $method = 'GET', array $config = []) use ($answer): array {
				$this->calls[] = [$slug, $endpoint, $config];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->assertSame(
					expected: ['register' => 'integriq', 'schema' => 'external_api', 'gateway' => self::GATEWAY],
					actual: $config['filters']
				);

				return ['results' => $rows, 'total' => count($rows)];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $object, ObjectSaveOptions $options): ObjectEntity {
				$this->assertSame(expected: 'integriq', actual: $options->target->register);
				$this->assertSame(expected: 'external_api', actual: $options->target->schema);
				$this->saves[] = [$object, (string)$options->uuid];

				return new ObjectEntity();
			}
		);

		$reader = $this->createMock(originalClassName: OpenApiDocumentReader::class);
		$reader->method('fetch')->willReturnCallback(
			function (string $url) use ($linked): array {
				$this->fetched[] = $url;
				if ($linked instanceof \Throwable) {
					throw $linked;
				}

				return ($linked ?? []);
			}
		);

		return new ApiDocumentExport(sourceCall: $sourceCall, objectService: $objects, reader: $reader, normaliser: new OpenApiDocumentNormaliser());
	}//end export()

	/**
	 * The settings the shipped Azure job carries.
	 *
	 * @param bool $force True to fetch unchanged APIs again.
	 *
	 * @return ApiDocumentExportSettings
	 */
	private static function azureSettings(bool $force = false): ApiDocumentExportSettings {
		$arguments          = self::azureDocumentsJob()['arguments'];
		$arguments['force'] = $force;

		return new ApiDocumentExportSettings(gateway: $arguments['gateway'], exportEndpoint: $arguments['exportEndpoint'], options: $arguments);
	}//end azureSettings()

	/**
	 * The shipped Azure job asks for the link export of each API and stores the linked document.
	 *
	 * @return void
	 */
	public function testTheAzureExportIsFetchedThroughItsLinkAndStored(): void {
		$export = $this->export(
			rows: [self::api(uuid: 'api-1', data: [])],
			answer: self::fixture(name: 'azure-api-export.json'),
			linked: self::fixture(name: 'azure-vergunningen-openapi.json')
		);

		$report = $export->run(settings: self::azureSettings());

		$this->assertSame(expected: ['stored' => ['Vergunningen'], 'unchanged' => [], 'failed' => []], actual: $report);
		$this->assertSame(expected: self::GATEWAY, actual: $this->calls[0][0]);
		$this->assertSame(expected: '/apis/vergunningen', actual: $this->calls[0][1]);
		$this->assertSame(
			expected: ['format' => 'openapi+json-link', 'export' => 'true', 'api-version' => '2022-08-01'],
			actual: $this->calls[0][2]['query']
		);
		$this->assertSame(expected: [self::fixture(name: 'azure-api-export.json')['value']['link']], actual: $this->fetched);

		[$saved, $uuid] = $this->saves[0];
		$this->assertSame(expected: 'api-1', actual: $uuid);
		$this->assertSame(expected: 'v2', actual: $saved['openApiDocumentVersion']);
		$this->assertSame(expected: 'Vergunningen', actual: $saved['openApiDocument']['info']['title']);
		$this->assertArrayHasKey(key: '/aanvragen', array: $saved['openApiDocument']['paths']);
		$this->assertSame(expected: 'Vergunningen', actual: $saved['name'], message: 'the row keeps its discovered values');
	}//end testTheAzureExportIsFetchedThroughItsLinkAndStored()

	/**
	 * A security scheme is stored as its shape only, as on an import.
	 *
	 * @return void
	 */
	public function testASecuritySchemeIsStoredAsItsShapeOnly(): void {
		$export = $this->export(
			rows: [self::api(uuid: 'api-1', data: [])],
			answer: self::fixture(name: 'azure-api-export.json'),
			linked: self::fixture(name: 'azure-vergunningen-openapi.json')
		);

		$export->run(settings: self::azureSettings());

		$scheme = $this->saves[0][0]['openApiDocument']['components']['securitySchemes']['apiKeyHeader'];
		$this->assertSame(expected: 'Ocp-Apim-Subscription-Key', actual: $scheme['name']);
		$this->assertArrayNotHasKey(key: 'x-example', array: $scheme);
		$this->assertStringNotContainsString(needle: '4f6c0a7b2d9e4c1a8b3f5e6d7c8b9a0f', haystack: (string)json_encode($this->saves[0][0]));
	}//end testASecuritySchemeIsStoredAsItsShapeOnly()

	/**
	 * The stored row is one the external_api schema accepts.
	 *
	 * @return void
	 */
	public function testTheStoredRowIsAcceptedByTheSchema(): void {
		$export = $this->export(
			rows: [self::api(uuid: 'api-1', data: ['baseUrl' => 'https://apim-gemeente.azure-api.net/vergunningen'])],
			answer: self::fixture(name: 'azure-api-export.json'),
			linked: self::fixture(name: 'azure-vergunningen-openapi.json')
		);

		$export->run(settings: self::azureSettings());

		$path     = dirname(__DIR__, 4) . '/lib/Settings/register.d/external-api-inventory.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$schema   = $fragment['components']['schemas']['external_api'];
		$this->assertArrayHasKey(key: 'openApiDocumentVersion', array: $schema['properties']);
		$this->assertSame(expected: [], actual: RegisterSchemaValidator::errorsAgainst(schema: $schema, object: $this->saves[0][0]));
	}//end testTheStoredRowIsAcceptedByTheSchema()

	/**
	 * An API whose version matches its stored document is skipped; force fetches it again.
	 *
	 * @return void
	 */
	public function testAnUnchangedApiIsSkippedUnlessForced(): void {
		$stored = ['openApiDocument' => ['openapi' => '3.0.1', 'paths' => []], 'openApiDocumentVersion' => 'v2'];
		$rows   = [self::api(uuid: 'api-1', data: $stored)];
		$answer = self::fixture(name: 'azure-api-export.json');
		$linked = self::fixture(name: 'azure-vergunningen-openapi.json');

		$report = $this->export(rows: $rows, answer: $answer, linked: $linked)->run(settings: self::azureSettings());
		$this->assertSame(expected: ['stored' => [], 'unchanged' => ['Vergunningen'], 'failed' => []], actual: $report);
		$this->assertSame(expected: [], actual: $this->calls, message: 'nothing is called for an unchanged API');

		$report = $this->export(rows: $rows, answer: $answer, linked: $linked)->run(settings: self::azureSettings(force: true));
		$this->assertSame(expected: ['Vergunningen'], actual: $report['stored']);
	}//end testAnUnchangedApiIsSkippedUnlessForced()

	/**
	 * A new version, or a document stored without its version, is fetched again.
	 *
	 * @return void
	 */
	public function testANewVersionIsFetchedAgain(): void {
		$rows = [
			self::api(uuid: 'api-1', data: ['openApiDocument' => ['openapi' => '3.0.1'], 'openApiDocumentVersion' => 'v1']),
			self::api(uuid: 'api-2', data: ['name' => 'Afval', 'openApiDocument' => ['openapi' => '3.0.1']]),
		];

		$report = $this->export(
			rows: $rows,
			answer: self::fixture(name: 'azure-api-export.json'),
			linked: self::fixture(name: 'azure-vergunningen-openapi.json')
		)->run(settings: self::azureSettings());

		$this->assertSame(expected: ['Vergunningen', 'Afval'], actual: $report['stored']);
	}//end testANewVersionIsFetchedAgain()

	/**
	 * An API the gateway no longer lists, or one of another gateway, is left alone.
	 *
	 * @return void
	 */
	public function testAnEndedApiAndAnotherGatewaysApiAreSkipped(): void {
		$rows = [
			self::api(uuid: 'api-1', data: ['sourceEndedAt' => '2026-11-15T02:00:00+00:00']),
			self::api(uuid: 'api-2', data: ['gateway' => 'kong-gateway-discovery']),
		];

		$report = $this->export(rows: $rows, answer: [])->run(settings: self::azureSettings());

		$this->assertSame(expected: ['stored' => [], 'unchanged' => [], 'failed' => []], actual: $report);
		$this->assertSame(expected: [], actual: $this->calls);
	}//end testAnEndedApiAndAnotherGatewaysApiAreSkipped()

	/**
	 * A refused export, a missing link, a non-https link and a non-OpenAPI document fail that API only.
	 *
	 * @return void
	 */
	public function testAFailedExportFailsThatApiOnly(): void {
		$row = [self::api(uuid: 'api-1', data: [])];

		$report = $this->export(rows: $row, answer: new SupplierCallException(message: 'azure-apim-discovery answered /apis/vergunningen with status 403.', status: 403))
			->run(settings: self::azureSettings());
		$this->assertSame(expected: ['Vergunningen' => 'azure-apim-discovery answered /apis/vergunningen with status 403.'], actual: $report['failed']);

		$report = $this->export(rows: $row, answer: ['value' => []])->run(settings: self::azureSettings());
		$this->assertStringContainsString(needle: 'no https link at value.link', haystack: $report['failed']['Vergunningen']);

		$report = $this->export(rows: $row, answer: ['value' => ['link' => 'http://blob.example/x.json']])->run(settings: self::azureSettings());
		$this->assertStringContainsString(needle: 'no https link', haystack: $report['failed']['Vergunningen']);
		$this->assertSame(expected: [], actual: $this->fetched, message: 'a plain http link is never fetched');

		$report = $this->export(rows: $row, answer: self::fixture(name: 'azure-api-export.json'), linked: ['title' => 'not a spec'])
			->run(settings: self::azureSettings());
		$this->assertStringContainsString(needle: 'not an OpenAPI 3.0, 3.1 or Swagger 2.0 document', haystack: $report['failed']['Vergunningen']);

		$report = $this->export(rows: $row, answer: self::fixture(name: 'azure-api-export.json'), linked: new InvalidArgumentException('The document URL answered HTTP 403.'))
			->run(settings: self::azureSettings());
		$this->assertSame(expected: 'The document URL answered HTTP 403.', actual: $report['failed']['Vergunningen']);

		$this->assertSame(expected: [], actual: $this->saves, message: 'a failed API is never saved');
	}//end testAFailedExportFailsThatApiOnly()

	/**
	 * A gateway that answers with the document itself is read at documentPosition, or whole.
	 *
	 * @return void
	 */
	public function testADocumentInTheAnswerIsReadAtItsPosition(): void {
		$document = self::fixture(name: 'azure-vergunningen-openapi.json');
		$row      = [self::api(uuid: 'api-1', data: ['gatewayApiId' => 'abc123'])];

		$settings = new ApiDocumentExportSettings(gateway: self::GATEWAY, exportEndpoint: '/restapis/{apiId}/exports/oas30');
		$report   = $this->export(rows: $row, answer: $document)->run(settings: $settings);
		$this->assertSame(expected: ['Vergunningen'], actual: $report['stored']);
		$this->assertSame(expected: '/restapis/abc123/exports/oas30', actual: $this->calls[0][1]);

		$settings = new ApiDocumentExportSettings(gateway: self::GATEWAY, exportEndpoint: '/apis/{apiId}', options: ['documentPosition' => 'value']);
		$report   = $this->export(rows: $row, answer: ['value' => $document])->run(settings: $settings);
		$this->assertSame(expected: ['Vergunningen'], actual: $report['stored']);

		$report = $this->export(rows: $row, answer: ['other' => $document])->run(settings: $settings);
		$this->assertStringContainsString(needle: 'no document at value', haystack: $report['failed']['Vergunningen']);

		$report = $this->export(rows: [self::api(uuid: 'api-2', data: ['gatewayApiId' => ''])], answer: $document)->run(settings: $settings);
		$this->assertSame(expected: ['Vergunningen' => 'The API has no gateway id to export it by.'], actual: $report['failed']);
	}//end testADocumentInTheAnswerIsReadAtItsPosition()

	/**
	 * The settings need a gateway and a placeholder, and encode the API id as one segment.
	 *
	 * @return void
	 */
	public function testTheSettingsNeedAGatewayAndAPlaceholder(): void {
		foreach ([['', '/apis/{apiId}'], ['g', '/apis/export']] as [$gateway, $endpoint]) {
			try {
				new ApiDocumentExportSettings(gateway: $gateway, exportEndpoint: $endpoint);
				$this->fail(message: 'Settings without a gateway or a placeholder must be refused.');
			} catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString(needle: 'A document export needs', haystack: $exception->getMessage());
			}
		}

		$settings = new ApiDocumentExportSettings(
			gateway: ' g ',
			exportEndpoint: '/apis/{apiName}/{apiId}',
			options: ['query' => 'x', 'limit' => -1, 'force' => 'true']
		);
		$this->assertSame(expected: 'g', actual: $settings->gateway);
		$this->assertSame(expected: [], actual: $settings->query);
		$this->assertSame(expected: ApiDocumentExport::DEFAULT_LIMIT, actual: $settings->limit);
		$this->assertTrue(condition: $settings->force);
		$this->assertSame(expected: '/apis/afval;rev=3/a%2Fb%20c%2Fafval;rev=3', actual: $settings->endpointFor(apiId: 'a/b c/afval;rev=3'));
	}//end testTheSettingsNeedAGatewayAndAPlaceholder()

	/**
	 * The shipped job is off, daily, and names a class the container can build.
	 *
	 * @return void
	 */
	public function testTheShippedJobIsDormantAndDaily(): void {
		$job = self::azureDocumentsJob();

		$this->assertSame(expected: ApiDocumentExportAction::class, actual: $job['jobClass']);
		$this->assertTrue(condition: class_exists($job['jobClass']));
		$this->assertFalse(condition: $job['isEnabled']);
		$this->assertSame(expected: 86400, actual: $job['interval']);
		$arguments = $job['arguments'];
		$settings  = new ApiDocumentExportSettings(gateway: $arguments['gateway'], exportEndpoint: $arguments['exportEndpoint'], options: $arguments);
		$this->assertSame(expected: '/apis/vergunningen', actual: $settings->endpointFor(apiId: self::API_ID));
		$this->assertSame(expected: 'value.link', actual: $settings->linkPosition);
	}//end testTheShippedJobIsDormantAndDaily()

	/**
	 * The action reports refused arguments as an error and failures as a warning.
	 *
	 * @return void
	 */
	public function testTheActionReportsWhatTheRunDid(): void {
		$action = new ApiDocumentExportAction(export: $this->export(rows: [], answer: []));
		$this->assertSame(expected: 'ERROR', actual: $action->run(arguments: [])['level']);

		$action = new ApiDocumentExportAction(
			export: $this->export(rows: [self::api(uuid: 'api-1', data: [])], answer: ['value' => []])
		);
		$result = $action->run(arguments: self::azureDocumentsJob()['arguments']);
		$this->assertSame(expected: 'WARNING', actual: $result['level']);
		$this->assertSame(expected: 'azure-apim-discovery: 0 documents stored, 0 unchanged, 1 failed.', actual: $result['message']);

		$action = new ApiDocumentExportAction(
			export: $this->export(
				rows: [self::api(uuid: 'api-1', data: [])],
				answer: self::fixture(name: 'azure-api-export.json'),
				linked: self::fixture(name: 'azure-vergunningen-openapi.json')
			)
		);
		$result = $action->run(arguments: self::azureDocumentsJob()['arguments']);
		$this->assertSame(expected: 'INFO', actual: $result['level']);
		$this->assertSame(expected: ['Vergunningen'], actual: $result['stored']);
	}//end testTheActionReportsWhatTheRunDid()
}//end class
