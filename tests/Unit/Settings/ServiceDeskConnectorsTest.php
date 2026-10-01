<?php

/**
 * The service desk templates, their mapping presets and their synchronizations.
 *
 * Every preset is executed by the REAL MappingService against recorded
 * answers (tests/fixtures/itsm/, the shapes of TOPdesk's Assets API 1.91.3
 * and ServiceNow's Table API as the mocks under tests/mocks replay them), and
 * the result is checked against the stackiq schema it is written into:
 * stackiq development lib/Settings/softwarecatalogus_register.json, schemas
 * usage, connection and catalogContract, plus register.d/
 * contracts-licence-seats.json for licenceMetric.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Flow\MappingOwnership;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Seed, ownership and payload tests for connectors-service-desk-templates.
 */
class ServiceDeskConnectorsTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/service-desk-connectors.json';

	private const FIXTURES = __DIR__ . '/../../fixtures/itsm/';

	/**
	 * stackiq usage.status enum.
	 */
	private const USAGE_STATUS = ['Acquisition', 'Planned', 'In production', 'To be phased out', 'Phased out'];

	/**
	 * stackiq connection.type enum.
	 */
	private const CONNECTION_TYPE = ['n/a', 'file transfer', 'digikoppeling', 'message que', 'upload to portal', 'webservices', 'api'];

	/**
	 * stackiq connection.dataExchangeDirection enum.
	 */
	private const DIRECTION = ['AtoB', 'BtoA', 'bi-directional'];

	/**
	 * stackiq catalogContract.contractType enum.
	 */
	private const CONTRACT_TYPE = ['SLA', 'Licence', 'Maintenance'];

	/**
	 * stackiq catalogContract.costPeriod enum.
	 */
	private const COST_PERIOD = ['Monthly', 'Annually', 'One-off'];

	/**
	 * stackiq catalogContract.licenceMetric enum (contracts-licence-seats.json).
	 */
	private const LICENCE_METRIC = ['Per named user', 'Per concurrent user', 'Per device', 'Per inhabitant', 'Per organisation', 'Other'];

	private const APPLICATION_KEYS = ['recordId', 'recordUrl', 'name', 'supplierName', 'installedVersion', 'status', 'description'];

	private const RELATION_KEYS = ['recordId', 'fromRecordId', 'toRecordId', 'name', 'type', 'direction'];

	private const TOPDESK = 'https://acme.topdesk.net';

	private const SERVICENOW = 'https://acme.service-now.com';

	private MappingService $mappingService;

	/**
	 * The fragment, decoded.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Build the real mapping engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
		$this->mappingService = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);

	}//end setUp()

	/**
	 * Every mapping the fragment seeds declares an owner for every field.
	 *
	 * @return void
	 */
	public function testEveryPresetOwnsEveryField(): void {
		$presets = $this->objectsOf(schema: 'mapping');
		$this->assertCount(13, $presets);

		foreach ($presets as $slug => $preset) {
			$this->assertSame([], MappingOwnership::unownedFields(definition: $preset), $slug);
			$this->assertSame(array_keys($preset['mapping']), array_keys($preset['ownership']), $slug);
			foreach ($preset['ownership'] as $field => $owner) {
				$this->assertContains($owner, ['source', 'stackiq'], $slug . ' ' . $field);
			}

			$this->assertSame($slug, $preset['slug']);
			$this->assertFalse($preset['passThrough'], $slug);
		}

		$property = $this->fragment['components']['schemas']['mapping']['properties']['ownership'];
		$this->assertSame('object', $property['type']);
		$this->assertSame('1.3.0', $this->fragment['components']['schemas']['mapping']['version']);

	}//end testEveryPresetOwnsEveryField()

	/**
	 * The sources ship dormant, with a placeholder host and only broker references.
	 *
	 * @return void
	 */
	public function testSourcesAreDormantAndHoldNoSecret(): void {
		$sources = $this->objectsOf(schema: 'source');
		$this->assertSame(['topdesk', 'servicenow', 'glpi'], array_keys($sources));

		foreach ($sources as $slug => $source) {
			$this->assertFalse($source['isEnabled'], $slug);
			$authentication = $source['configuration']['authentication'];
			foreach ($authentication as $field => $value) {
				if ($field === 'username') {
					$this->assertSame('', $value);
					continue;
				}

				$this->assertSame(['credentialRef'], array_keys($value), $slug . ' ' . $field);
				$this->assertArrayHasKey('credentialName', $value['credentialRef']);
			}

			$this->assertArrayNotHasKey('password', $source);
			$this->assertArrayNotHasKey('apikey', $source);
		}

		$this->assertSame('topdesk-application-password', $sources['topdesk']['configuration']['authentication']['password']['credentialRef']['credentialName']);
		$this->assertStringEndsWith('/tas/api', $sources['topdesk']['location']);

	}//end testSourcesAreDormantAndHoldNoSecret()

	/**
	 * Every synchronization names a seeded source (or stackiq's register) and seeded presets.
	 *
	 * @return void
	 */
	public function testSynchronizationsNameSeededSourcesAndPresets(): void {
		$sources = array_keys($this->objectsOf(schema: 'source'));
		$presets = array_keys($this->objectsOf(schema: 'mapping'));
		$syncs = $this->objectsOf(schema: 'synchronization');

		$this->assertSame(
			[
				'itsm-topdesk-applications', 'itsm-topdesk-licences', 'itsm-topdesk-contracts',
				'itsm-servicenow-applications', 'itsm-servicenow-relations', 'itsm-servicenow-licences',
				'itsm-servicenow-contracts', 'itsm-topdesk-outbound', 'itsm-servicenow-outbound', 'itsm-file-applications',
			],
			array_keys($syncs)
		);

		foreach ($syncs as $slug => $sync) {
			foreach ($sync['configurations'] as $reference) {
				if ($reference !== 'itsm') {
					$this->assertContains($reference, $presets, $slug);
				}
			}

			if (($sync['sourceType'] ?? '') === 'api') {
				$this->assertContains($sync['sourceId'], $sources, $slug);
				$this->assertSame('offset', $sync['sourceConfig']['paginationMode'], $slug);
				$this->assertNotSame('', $sync['sourceConfig']['resultsPosition'], $slug);
			}
		}

		$this->assertSame('dataSet', $syncs['itsm-topdesk-applications']['sourceConfig']['resultsPosition']);
		$this->assertSame('pageStart', $syncs['itsm-topdesk-applications']['sourceConfig']['paginationQuery']);
		$this->assertSame('result', $syncs['itsm-servicenow-applications']['sourceConfig']['resultsPosition']);
		$this->assertSame('sysparm_offset', $syncs['itsm-servicenow-applications']['sourceConfig']['paginationQuery']);

	}//end testSynchronizationsNameSeededSourcesAndPresets()

	/**
	 * TOPdesk applications map onto stackiq's fields, Dutch life cycle values included.
	 *
	 * @return void
	 */
	public function testTopdeskApplicationsInbound(): void {
		$page = $this->fixture(name: 'topdesk-applications-page.json');
		$this->assertCount(5, $page['dataSet']);

		$results = [];
		foreach ($page['dataSet'] as $record) {
			$record['_desk'] = ['baseUrl' => self::TOPDESK . '/'];
			$results[] = $this->map(slug: 'itsm-topdesk-application-inbound', input: $record);
		}

		foreach ($results as $result) {
			$this->assertApplication(result: $result);
		}

		$this->assertSame(
			[
				'recordId' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000001',
				'recordUrl' => self::TOPDESK . '/tas/secure/assetmgmt/card.html?unid=0b6f4d2e-1a2b-4c3d-8e9f-000000000001',
				'name' => 'Zaaksysteem',
				'supplierName' => 'Dimpact',
				'installedVersion' => '2.4.1',
				'status' => 'In production',
				'description' => 'Zaakgericht werken voor alle afdelingen',
			],
			$results[0]
		);
		$this->assertSame(
			['In production', 'In production', 'To be phased out', 'Planned', 'Acquisition'],
			array_column($results, 'status')
		);

		// Without the tenant address the link is empty, never a relative path.
		$bare = $this->map(slug: 'itsm-topdesk-application-inbound', input: $page['dataSet'][0]);
		$this->assertSame('', $bare['recordUrl']);

	}//end testTopdeskApplicationsInbound()

	/**
	 * A TOPdesk asset link becomes a connection between two application records.
	 *
	 * @return void
	 */
	public function testTopdeskRelationInbound(): void {
		$links = $this->fixture(name: 'topdesk-assetlinks.json');
		$this->assertCount(1, $links);

		$result = $this->map(
			slug: 'itsm-topdesk-relation-inbound',
			input: ['applicationRecordId' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000001', 'link' => $links[0]]
		);

		$this->assertSame(self::RELATION_KEYS, array_keys($result));
		$this->assertSame('0b6f4d2e-1a2b-4c3d-8e9f-000000000001', $result['fromRecordId']);
		$this->assertSame('0b6f4d2e-1a2b-4c3d-8e9f-000000000002', $result['toRecordId']);
		$this->assertSame('Uses data from', $result['name']);
		$this->assertContains($result['type'], self::CONNECTION_TYPE);
		$this->assertContains($result['direction'], self::DIRECTION);
		$this->assertSame('AtoB', $result['direction']);

	}//end testTopdeskRelationInbound()

	/**
	 * TOPdesk licences and contracts carry the contract terms in stackiq's types.
	 *
	 * @return void
	 */
	public function testTopdeskLicenceAndContractInbound(): void {
		$licence = $this->map(slug: 'itsm-topdesk-licence-inbound', input: $this->fixture(name: 'topdesk-licences-page.json')['dataSet'][0]);
		$this->assertContract(result: $licence);
		$this->assertSame('Licence', $licence['contractType']);
		$this->assertSame('2026-01-01', $licence['startDate']);
		$this->assertSame('2028-12-31', $licence['endDate']);
		$this->assertSame(48000.0, $licence['cost']);
		$this->assertSame('Annually', $licence['costPeriod']);
		$this->assertSame('Per named user', $licence['licenceMetric']);
		$this->assertSame(350, $licence['licencesBought']);
		$this->assertSame('0b6f4d2e-1a2b-4c3d-8e9f-000000000001', $licence['applicationRecordId']);

		$contract = $this->map(slug: 'itsm-topdesk-contract-inbound', input: $this->fixture(name: 'topdesk-contracts-page.json')['dataSet'][0]);
		$this->assertContract(result: $contract);
		$this->assertSame('SLA', $contract['contractType']);
		$this->assertSame('Monthly', $contract['costPeriod']);
		$this->assertSame(1250.0, $contract['cost']);
		$this->assertArrayNotHasKey('licencesBought', $contract);

	}//end testTopdeskLicenceAndContractInbound()

	/**
	 * ServiceNow applications, read with sysparm_display_value=all, map onto stackiq's fields.
	 *
	 * @return void
	 */
	public function testServicenowApplicationsInbound(): void {
		$page = $this->fixture(name: 'servicenow-cmdb_ci_appl-page.json');
		$this->assertCount(5, $page['result']);

		$results = [];
		foreach ($page['result'] as $record) {
			$record['_desk'] = ['baseUrl' => self::SERVICENOW];
			$result = $this->map(slug: 'itsm-servicenow-application-inbound', input: $record);
			$this->assertApplication(result: $result);
			$results[] = $result;
		}

		$this->assertSame('Zaaksysteem', $results[0]['name']);
		$this->assertSame('Dimpact', $results[0]['supplierName']);
		$this->assertSame(self::SERVICENOW . '/cmdb_ci_appl.do?sys_id=' . $results[0]['recordId'], $results[0]['recordUrl']);
		$this->assertSame(['In production', 'In production', 'Phased out', 'Planned', 'Acquisition'], array_column($results, 'status'));
		$this->assertSame('', $results[3]['supplierName']);

	}//end testServicenowApplicationsInbound()

	/**
	 * ServiceNow CI relations map onto connections, the data direction read from the relation type.
	 *
	 * @return void
	 */
	public function testServicenowRelationsInbound(): void {
		$page = $this->fixture(name: 'servicenow-cmdb_rel_ci-page.json');
		$directions = [];
		foreach ($page['result'] as $record) {
			$result = $this->map(slug: 'itsm-servicenow-relation-inbound', input: $record);
			$this->assertSame(self::RELATION_KEYS, array_keys($result));
			$this->assertContains($result['type'], self::CONNECTION_TYPE);
			$this->assertContains($result['direction'], self::DIRECTION);
			$this->assertSame(32, strlen($result['fromRecordId']));
			$directions[] = $result['direction'];
		}

		$this->assertSame(['AtoB', 'bi-directional', 'AtoB'], $directions);

	}//end testServicenowRelationsInbound()

	/**
	 * ServiceNow licences and contracts carry the contract terms in stackiq's types.
	 *
	 * @return void
	 */
	public function testServicenowLicenceAndContractInbound(): void {
		$licence = $this->map(slug: 'itsm-servicenow-licence-inbound', input: $this->fixture(name: 'servicenow-alm_license-page.json')['result'][0]);
		$this->assertContract(result: $licence);
		$this->assertSame('SL-2026-01', $licence['contractNumber']);
		$this->assertSame('PO-77812', $licence['vendorReference']);
		$this->assertSame(350, $licence['licencesBought']);
		$this->assertSame('2028-12-31', $licence['endDate']);
		$this->assertSame('EUR', $licence['currency']);

		$contract = $this->map(slug: 'itsm-servicenow-contract-inbound', input: $this->fixture(name: 'servicenow-ast_contract-page.json')['result'][0]);
		$this->assertContract(result: $contract);
		$this->assertSame('SLA', $contract['contractType']);
		$this->assertSame('Monthly', $contract['costPeriod']);
		$this->assertSame('CNTR0010014', $contract['contractNumber']);
		$this->assertSame(32, strlen($contract['applicationRecordId']));

	}//end testServicenowLicenceAndContractInbound()

	/**
	 * An outbound create sends every field; an update only what stackiq owns.
	 *
	 * @return void
	 */
	public function testOutboundPresetsSendOnlyStackiqFieldsOnUpdate(): void {
		$usage = [
			'uuid' => '5d3c1a7e-0000-4000-8000-000000000001',
			'catalogueUrl' => 'https://stackiq.example.org/usages/5d3c1a7e',
			'recordId' => '',
			'name' => 'Zaaksysteem',
			'supplierName' => 'Dimpact',
			'installedVersion' => '2.4.1',
			'status' => 'Phased out',
			'bbnLevel' => 'BBN2',
			'timeClassification' => 'Tolerate',
			'publicationDate' => '',
			'licencesBought' => 350,
			'licencesInUse' => 280,
			'licenceMetric' => 'Per named user',
			'contractNumber' => 'LIC-2026-001',
			'contractEndDate' => '2028-12-31',
			'_desk' => ['templateId' => 'a72b24c1-0553-4f88-9add-5b5bb85c7d4e'],
		];

		$topdesk = $this->map(slug: 'itsm-topdesk-application-outbound', input: $usage);
		$this->assertSame('a72b24c1-0553-4f88-9add-5b5bb85c7d4e', $topdesk['type_id']);
		$this->assertSame('Zaaksysteem', $topdesk['name']);
		$this->assertSame('Phased out', $topdesk['lifecycleStatus']);
		$this->assertSame('350', $topdesk['licencesBought']);
		$this->assertArrayNotHasKey('publicationDate', $topdesk, 'an empty value is not sent');

		$update = MappingOwnership::keepWriterFields(
			mapped: $topdesk,
			ownership: $this->objectsOf(schema: 'mapping')['itsm-topdesk-application-outbound']['ownership'],
			mode: 'outbound'
		);
		foreach (['type_id', 'name', 'supplier', 'version', 'lifecycleStatus'] as $deskOwned) {
			$this->assertArrayNotHasKey($deskOwned, $update);
		}

		$this->assertSame('BBN2', $update['bbnLevel']);
		$this->assertSame($usage['uuid'], $update['stackiqId']);

		$servicenow = $this->map(slug: 'itsm-servicenow-application-outbound', input: $usage);
		$this->assertSame('7', $servicenow['install_status']);
		$this->assertSame('Dimpact', $servicenow['vendor']);
		$update = MappingOwnership::keepWriterFields(
			mapped: $servicenow,
			ownership: $this->objectsOf(schema: 'mapping')['itsm-servicenow-application-outbound']['ownership'],
			mode: 'outbound'
		);
		$this->assertSame(
			['u_stackiq_id', 'u_catalogue_url', 'u_bbn_level', 'u_time_classification', 'u_licences_bought',
				'u_licences_in_use', 'u_licence_metric', 'u_contract_number', 'u_contract_end_date'],
			array_keys($update)
		);

	}//end testOutboundPresetsSendOnlyStackiqFieldsOnUpdate()

	/**
	 * An inbound update keeps what the desk owns and drops every stackiq-owned contract term.
	 *
	 * @return void
	 */
	public function testInboundUpdateNeverCarriesAStackiqField(): void {
		$licence = $this->map(slug: 'itsm-topdesk-licence-inbound', input: $this->fixture(name: 'topdesk-licences-page.json')['dataSet'][0]);

		$update = MappingOwnership::keepWriterFields(
			mapped: $licence,
			ownership: $this->objectsOf(schema: 'mapping')['itsm-topdesk-licence-inbound']['ownership'],
			mode: 'inbound'
		);

		$this->assertSame(['recordId', 'recordUrl', 'applicationRecordId', 'supplierName'], array_keys($update));

	}//end testInboundUpdateNeverCarriesAStackiqField()

	/**
	 * A spreadsheet row with stackiq's column names maps onto the application fields.
	 *
	 * @return void
	 */
	public function testFileRowInbound(): void {
		$result = $this->map(
			slug: 'itsm-file-application-inbound',
			input: ['recordId' => 'row-7', 'name' => 'Parkeren', 'status' => 'uitgefaseerd', 'supplierName' => 'Centric']
		);

		$this->assertApplication(result: $result);
		$this->assertSame('Phased out', $result['status']);
		$this->assertSame('', $result['installedVersion']);

	}//end testFileRowInbound()

	/**
	 * GLPI appliances, read with expand_dropdowns=true, map onto the application fields.
	 *
	 * @return void
	 */
	public function testGlpiApplianceInbound(): void {
		// GLPI 11 apirest.php GET /Appliance/:id?expand_dropdowns=true shape.
		$appliance = ['id' => 12, 'name' => 'Raadsinformatie', 'manufacturers_id' => 'Notubiz', 'states_id' => 'In gebruik', 'comment' => 'Raad'];
		$result = $this->map(slug: 'itsm-glpi-appliance-inbound', input: $appliance);

		$this->assertSame('12', $result['recordId']);
		$this->assertSame('Notubiz', $result['supplierName']);
		$this->assertSame('In production', $result['status']);
		$this->assertContains($result['status'], self::USAGE_STATUS);

	}//end testGlpiApplianceInbound()

	/**
	 * Run one seeded preset through the real mapping engine.
	 *
	 * @param string $slug The preset slug.
	 * @param array $input The mapping input.
	 *
	 * @return array The mapped result.
	 */
	private function map(string $slug, array $input): array {
		$preset = $this->objectsOf(schema: 'mapping')[$slug];

		return $this->mappingService->executeMapping(mapping: $preset, input: $input);

	}//end map()

	/**
	 * Assert an application result has exactly stackiq's keys and a valid status.
	 *
	 * @param array $result The mapped result.
	 *
	 * @return void
	 */
	private function assertApplication(array $result): void {
		$this->assertSame(self::APPLICATION_KEYS, array_keys($result));
		$this->assertContains($result['status'], self::USAGE_STATUS);
		$this->assertNotSame('', $result['recordId']);
		$this->assertNotSame('', $result['name']);

	}//end assertApplication()

	/**
	 * Assert a contract result's values fit stackiq's catalogContract.
	 *
	 * @param array $result The mapped result.
	 *
	 * @return void
	 */
	private function assertContract(array $result): void {
		$this->assertContains($result['contractType'], self::CONTRACT_TYPE);
		$this->assertContains($result['costPeriod'], self::COST_PERIOD);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['startDate']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['endDate']);
		$this->assertIsFloat($result['cost']);
		$this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $result['currency']);
		$this->assertNotSame('', $result['recordId']);
		if (array_key_exists('licenceMetric', $result) === true) {
			$this->assertContains($result['licenceMetric'], self::LICENCE_METRIC);
			$this->assertIsInt($result['licencesBought']);
		}

	}//end assertContract()

	/**
	 * The seeded objects of one schema, keyed by slug, in file order.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>> The objects.
	 */
	private function objectsOf(string $schema): array {
		$objects = [];
		foreach ($this->fragment['components']['objects'] as $object) {
			if ($object['@self']['schema'] === $schema) {
				$objects[$object['@self']['slug']] = $object;
			}
		}

		return $objects;

	}//end objectsOf()

	/**
	 * A recorded answer.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return array The decoded answer.
	 */
	private function fixture(string $name): array {
		return json_decode((string)file_get_contents(self::FIXTURES . $name), true, 512, JSON_THROW_ON_ERROR);

	}//end fixture()
}//end class
