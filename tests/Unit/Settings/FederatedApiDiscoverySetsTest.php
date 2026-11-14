<?php

/**
 * The three API discovery sets, run through the real MappingService over
 * recorded vendor answers and checked against the external_api schema.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Ownership\DisappearanceApplier;
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Covers kong-gateway-discovery.json, azure-apim-discovery.json and aws-apigateway-discovery.json.
 */
final class FederatedApiDiscoverySetsTest extends TestCase {

	/**
	 * Each set: its vendor value and the recorded list answer.
	 */
	private const SETS = [
		'kong-gateway-discovery'   => ['vendor' => 'kong', 'fixture' => 'kong-services.json'],
		'azure-apim-discovery'     => ['vendor' => 'azure-apim', 'fixture' => 'azure-apis.json'],
		'aws-apigateway-discovery' => ['vendor' => 'aws-apigateway', 'fixture' => 'aws-restapis.json'],
	];

	/**
	 * A fragment's objects keyed by schema and slug.
	 *
	 * @param string $set The set (fragment file name without .json).
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function objects(string $set): array {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $set . '.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$objects  = [];
		foreach ($fragment['components']['objects'] as $object) {
			$objects[$object['@self']['schema']][$object['@self']['slug']] = $object;
		}

		return $objects;
	}//end objects()

	/**
	 * The external_api schema the sets write into.
	 *
	 * @return array<string, mixed>
	 */
	private function externalApiSchema(): array {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/external-api-inventory.json';
		$fragment = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		return $fragment['components']['schemas']['external_api'];
	}//end externalApiSchema()

	/**
	 * The items of a set's recorded list answer, read at the synchronization's resultsPosition.
	 *
	 * @param string $set The set.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function listed(string $set): array {
		$path   = dirname(__DIR__, 2) . '/fixtures/federated-api/' . self::SETS[$set]['fixture'];
		$answer = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$sync   = $this->objects(set: $set)['synchronization'][$set];

		return $answer[$sync['sourceConfig']['resultsPosition']];
	}//end listed()

	/**
	 * The real MappingService with inert collaborators.
	 *
	 * @return MappingService
	 */
	private function mappingService(): MappingService {
		return new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ORObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end mappingService()

	/**
	 * Map every recorded item of a set.
	 *
	 * @param string $set The set.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mapped(string $set): array {
		$mapping = $this->objects(set: $set)['mapping'][$set . '-to-external-api'];
		$mapper  = $this->mappingService();

		return array_map(fn (array $item): array => $mapper->executeMapping(mapping: $mapping, input: $item), $this->listed(set: $set));
	}//end mapped()

	/**
	 * Every recorded API becomes an external_api the schema accepts, carrying its gateway, vendor and the vendor's id.
	 *
	 * @return void
	 */
	public function testEveryRecordedApiBecomesAnAcceptedInventoryRow(): void {
		$schema = $this->externalApiSchema();
		foreach (self::SETS as $set => $expect) {
			$items = $this->listed(set: $set);
			foreach ($this->mapped(set: $set) as $index => $row) {
				$this->assertSame($set, $row['gateway'], $set);
				$this->assertSame($expect['vendor'], $row['vendor'], $set);
				$this->assertSame((string)$items[$index]['id'], $row['gatewayApiId'], $set);
				$this->assertNotSame('', $row['name'], $set);
				$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $row['lastSeen']), $set . ' lastSeen is a date-time: ' . $row['lastSeen']);
				$this->assertSame([], RegisterSchemaValidator::errorsAgainst(schema: $schema, object: $row), $set . ' row ' . $index);
			}
		}
	}//end testEveryRecordedApiBecomesAnAcceptedInventoryRow()

	/**
	 * The vendor fields land where the inventory reads them.
	 *
	 * @return void
	 */
	public function testVendorFieldsAreMappedToTheInventoryColumns(): void {
		[$orders, $invoices] = $this->mapped(set: 'kong-gateway-discovery');
		$this->assertSame('orders', $orders['name']);
		$this->assertSame('http://orders.internal:8080/v1', $orders['baseUrl']);
		$this->assertSame('1.4', $orders['version'], 'a version: tag is the version');
		$this->assertSame('', $invoices['version'], 'no tags, no version');

		[$vergunningen, $afval] = $this->mapped(set: 'azure-apim-discovery');
		$this->assertSame('Vergunningen API', $vergunningen['name']);
		$this->assertSame('v2', $vergunningen['version']);
		$this->assertSame('https://backend.gemeente.example/vergunningen', $vergunningen['baseUrl']);
		$this->assertSame('', $vergunningen['environment'], 'the current revision has no environment');
		$this->assertSame('revision 3', $afval['environment']);

		[$parkeren, $meldingen] = $this->mapped(set: 'aws-apigateway-discovery');
		$this->assertSame('https://a1b2c3d4e5.execute-api.eu-west-1.amazonaws.com', $parkeren['baseUrl']);
		$this->assertSame('2026-06', $parkeren['version']);
		$this->assertSame('', $meldingen['version']);
	}//end testVendorFieldsAreMappedToTheInventoryColumns()

	/**
	 * Each set ships off, calls through the broker, runs daily into external_api and never deletes.
	 *
	 * @return void
	 */
	public function testEachSetIsDormantBrokeredDailyAndNeverDeletes(): void {
		foreach (array_keys(self::SETS) as $set) {
			$objects = $this->objects(set: $set);
			$source  = $objects['source'][$set];
			$this->assertFalse($source['isEnabled'], $set . ' ships off');
			$configuration = json_encode($source['configuration'], JSON_THROW_ON_ERROR);
			$this->assertStringContainsString('credentialRef', $configuration, $set . ' calls through the broker');
			$this->assertStringNotContainsString('"secret":"', str_replace(' ', '', $configuration));

			$sync = $objects['synchronization'][$set];
			$this->assertSame($set, $sync['sourceId']);
			$this->assertSame('integriq/external_api', $sync['targetId']);
			$this->assertSame(DisappearancePolicy::MARK_ENDED, $sync['sourceConfig']['disappearancePolicy']);
			$this->assertSame(DisappearancePolicy::MARK_ENDED, DisappearancePolicy::fromSourceConfig($sync['sourceConfig']));

			$job = $objects['job'][$set . '-daily'];
			$this->assertFalse($job['isEnabled'], $set . ' job ships off');
			$this->assertSame(86400, $job['interval']);
			$this->assertSame($set, $job['arguments']['synchronizationId']);
		}
	}//end testEachSetIsDormantBrokeredDailyAndNeverDeletes()

	/**
	 * Only the Amazon set asks the broker to sign, and it asks for AWS Signature Version 4.
	 *
	 * @return void
	 */
	public function testOnlyTheAmazonSetNeedsBrokerSigning(): void {
		$aws = $this->objects(set: 'aws-apigateway-discovery');
		$this->assertSame('aws-sigv4', $aws['synchronization']['aws-apigateway-discovery']['sourceConfig']['brokerSigning']);
		$this->assertSame('aws-sigv4', $aws['source']['aws-apigateway-discovery']['configuration']['authentication']['authScheme']);

		foreach (['kong-gateway-discovery', 'azure-apim-discovery'] as $set) {
			$this->assertArrayNotHasKey('brokerSigning', $this->objects(set: $set)['synchronization'][$set]['sourceConfig'], $set);
		}
	}//end testOnlyTheAmazonSetNeedsBrokerSigning()

	/**
	 * An API the gateway no longer returns keeps its row and says since when it is gone (REQ-FEDG-002).
	 *
	 * @return void
	 */
	public function testAnApiNoLongerFoundStaysListedAsNoLongerSeen(): void {
		$row     = $this->mapped(set: 'kong-gateway-discovery')[0];
		$applier = new DisappearanceApplier();
		$ended   = $applier->applyToObject(policy: DisappearancePolicy::MARK_ENDED, objectData: $row, runAt: '2026-11-15T02:00:00+00:00', values: []);

		$this->assertSame('2026-11-15T02:00:00+00:00', $ended[DisappearanceApplier::OBJECT_ENDED_AT]);
		$this->assertSame($row['name'], $ended['name'], 'the row keeps its values');
		$this->assertArrayHasKey(DisappearanceApplier::OBJECT_ENDED_AT, $this->externalApiSchema()['properties'], 'the inventory can show it');
		$this->assertSame([], RegisterSchemaValidator::errorsAgainst(schema: $this->externalApiSchema(), object: $ended));
	}//end testAnApiNoLongerFoundStaysListedAsNoLongerSeen()

	/**
	 * The example rows seeded for a demo install are rows the schema accepts.
	 *
	 * @return void
	 */
	public function testTheSeededExamplesAreAcceptedRows(): void {
		$schema = $this->externalApiSchema();
		$this->assertCount(2, $schema['x-openregister-seed']);
		foreach ($schema['x-openregister-seed'] as $seed) {
			unset($seed['@self']);
			$this->assertSame([], RegisterSchemaValidator::errorsAgainst(schema: $schema, object: $seed));
		}
	}//end testTheSeededExamplesAreAcceptedRows()
}//end class
