<?php

/**
 * Field ownership enforced by the apply-mapping step.
 *
 * Runs the REAL MappingService and the seeded TOPdesk licence preset: only
 * the OpenRegister lookup that resolves the mapping by slug is a double.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Flow\ApplyMappingNode;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Ownership mode of `openconnector.apply-mapping`.
 */
class ApplyMappingNodeOwnershipTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/service-desk-connectors.json';

	/**
	 * @var OrObjectService&MockObject
	 */
	private $orObjectService;

	private ApplyMappingNode $node;

	/**
	 * The licence record as TOPdesk sends it.
	 *
	 * @var array<string, string>
	 */
	private array $deskRecord = [
		'id' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000101',
		'name' => 'LIC-2026-001',
		'application' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000001',
		'supplier' => 'Dimpact B.V.',
		'contractNumber' => 'LIC-2026-001',
		'startDate' => '2026-01-01T00:00:00.000',
		'endDate' => '2028-12-31T00:00:00.000',
		'cost' => '48000.00',
		'costPeriod' => 'Jaarlijks',
		'licenceMetric' => 'Per named user',
		'licencesBought' => '999',
	];

	/**
	 * Build the node over the real mapping engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = $this->createMock(OrObjectService::class);
		$mappingService = new MappingService(
			new \Twig\Loader\ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->orObjectService,
			$this->createMock(SynchronizationContractService::class),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				return ($parameters === [] || is_array($parameters) === false) ? $text : vsprintf($text, $parameters);
			}
		);

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('admin');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($owner);

		$this->node = new ApplyMappingNode(
			mappingService: $mappingService,
			flowOwner: new FlowOwner(userManager: $userManager, userSession: $this->createMock(IUserSession::class), l10n: $l10n),
			l10n: $l10n,
			urlGenerator: $this->createMock(IURLGenerator::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A create keeps every field, so stackiq is seeded with the desk's terms.
	 *
	 * @return void
	 */
	public function testCreateKeepsEveryField(): void {
		$this->givenMapping(definition: $this->preset(slug: 'itsm-topdesk-licence-inbound'));

		$out = $this->runInbound(json: ['source' => $this->deskRecord, 'target' => ['uuid' => '']]);

		$record = $out[0]['json']['record'];
		$this->assertSame('LIC-2026-001', $record['contractNumber']);
		$this->assertSame(999, $record['licencesBought']);
		$this->assertSame('Dimpact B.V.', $record['supplierName']);

	}//end testCreateKeepsEveryField()

	/**
	 * On a conflict the desk wins its own fields and a stackiq field survives.
	 *
	 * The desk changed the supplier name (desk-owned) and the seat count
	 * (stackiq-owned). The update carries the new supplier and leaves the
	 * seat count out, so a patching write keeps stackiq's value.
	 *
	 * @return void
	 */
	public function testUpdateKeepsOnlyTheDeskFields(): void {
		$this->givenMapping(definition: $this->preset(slug: 'itsm-topdesk-licence-inbound'));

		$out = $this->runInbound(json: ['source' => $this->deskRecord, 'target' => ['uuid' => 'c0ffee00-0000-4000-8000-000000000001']]);

		$this->assertSame(
			[
				'recordId' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000101',
				'recordUrl' => '',
				'applicationRecordId' => '0b6f4d2e-1a2b-4c3d-8e9f-000000000001',
				'supplierName' => 'Dimpact B.V.',
			],
			$out[0]['json']['record']
		);

	}//end testUpdateKeepsOnlyTheDeskFields()

	/**
	 * A mapping with an unowned field is refused in ownership mode, not run.
	 *
	 * @return void
	 */
	public function testUnownedFieldIsRefused(): void {
		$definition = $this->preset(slug: 'itsm-topdesk-licence-inbound');
		unset($definition['ownership']['cost']);
		$this->givenMapping(definition: $definition);

		$this->expectException(FlowNodeException::class);
		$this->expectExceptionMessage('does not say who owns cost');

		$this->runInbound(json: ['source' => $this->deskRecord, 'target' => ['uuid' => 'x']]);

	}//end testUnownedFieldIsRefused()

	/**
	 * Without ownership mode the step maps every field, as before.
	 *
	 * @return void
	 */
	public function testWithoutOwnershipNothingIsNarrowed(): void {
		$this->givenMapping(definition: $this->preset(slug: 'itsm-topdesk-licence-inbound'));

		$out = $this->node->execute(
			[['json' => ['source' => $this->deskRecord]]],
			['mapping' => 'itsm-topdesk-licence-inbound', 'input' => 'source', 'output' => 'record'],
			['triggeredBy' => 'admin', 'stepId' => 'map']
		);

		$this->assertSame(999, $out[0]['json']['record']['licencesBought']);

	}//end testWithoutOwnershipNothingIsNarrowed()

	/**
	 * Unusable ownership configurations are refused at save.
	 *
	 * @return void
	 */
	public function testOwnershipConfigIsValidated(): void {
		$bad = [
			['mapping' => 'm', 'ownership' => 'sideways', 'exists' => 'a'],
			['mapping' => 'm', 'ownership' => 'inbound'],
			['mapping' => 'm', 'exists' => 'a'],
		];
		foreach ($bad as $config) {
			try {
				$this->node->validateConfig($config);
				$this->fail('accepted ' . json_encode($config));
			} catch (UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->node->validateConfig(['mapping' => 'm', 'ownership' => 'outbound', 'exists' => 'usage.recordId']);

	}//end testOwnershipConfigIsValidated()

	/**
	 * Run the step in inbound ownership mode.
	 *
	 * @param array $json The item.
	 *
	 * @return array The output items.
	 */
	private function runInbound(array $json): array {
		return $this->node->execute(
			[['json' => $json]],
			[
				'mapping' => 'itsm-topdesk-licence-inbound',
				'input' => 'source',
				'output' => 'record',
				'ownership' => 'inbound',
				'exists' => 'target.uuid',
			],
			['triggeredBy' => 'admin', 'stepId' => 'map']
		);

	}//end runInbound()

	/**
	 * Make OpenRegister resolve the mapping slug to this definition.
	 *
	 * @param array $definition The mapping object.
	 *
	 * @return void
	 */
	private function givenMapping(array $definition): void {
		$entity = new ObjectEntity();
		$entity->setObject($definition);
		$this->orObjectService->method('find')->willReturn($entity);

	}//end givenMapping()

	/**
	 * A seeded preset from the fragment.
	 *
	 * @param string $slug The preset slug.
	 *
	 * @return array The mapping object.
	 */
	private function preset(string $slug): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if ($object['@self']['slug'] === $slug && $object['@self']['schema'] === 'mapping') {
				return $object;
			}
		}

		$this->fail('No preset ' . $slug);

	}//end preset()
}//end class
