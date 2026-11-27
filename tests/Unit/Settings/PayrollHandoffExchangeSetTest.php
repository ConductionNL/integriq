<?php

/**
 * The payroll handoff pushes and the payslip pickup, run through the real engine parts they use.
 *
 * September 2026 of Adviesbureau Kade B.V. (the REQ-HRX-001 scenarios): the
 * handoff as humaniq stores it, without its mutations, is embedded the way the
 * pushes declare, mapped with the real MappingService for each bureau and
 * compared with that bureau's example; the SFTP push is written through the
 * real file target. The bureau's return file with two payslips is read by the
 * real pickup reader, mapped with the real lookup, and the handoff is moved
 * by the real related write-back.
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
 * @spec openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\PickedFileRecords;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\LookupAllowlist;
use OCA\Integriq\Service\MappingLookup;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Service\Synchronization\RelatedObjectEmbedder;
use OCA\Integriq\Service\Synchronization\RelatedWriteBack;
use OCA\Integriq\Service\Synchronization\RemoteFileTarget;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Covers lib/Settings/register.d/payroll-handoff-exchange.json.
 */
final class PayrollHandoffExchangeSetTest extends TestCase {

	private const HANDOFF_ID = '3b8f6a2e-1c4d-4f5a-9e7b-2d6c8a1f0e93';

	/**
	 * Per push: the bureau fragment, the source it targets and the bureau's example.
	 *
	 * @var array<string, array{fragment: string, source: string, example: string}>
	 */
	private const PUSHES = [
		'payroll-handoff-push-loket' => ['fragment' => 'loket-payroll-source.json', 'source' => 'loket-payroll', 'example' => 'loket-mutations.json'],
		'payroll-handoff-push-nmbrs' => ['fragment' => 'nmbrs-payroll-source.json', 'source' => 'nmbrs-payroll', 'example' => 'nmbrs-changes.json'],
		'payroll-handoff-push-sftp'  => ['fragment' => 'payroll-sftp-drop-source.json', 'source' => 'payroll-sftp-drop', 'example' => 'sftp-drop-file.json'],
	];

	/**
	 * A fragment's objects, by schema and slug.
	 *
	 * @param string $fragment The fragment file under lib/Settings/register.d.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function seeds(string $fragment = 'payroll-handoff-exchange.json'): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $fragment;
		$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$seeds = [];
		foreach ($decoded['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['schema']][$self['slug']] = $object;
		}

		return $seeds;
	}//end seeds()

	/**
	 * A fixture under tests/fixtures/hr-data-exchange/payroll.
	 *
	 * @param string $file The fixture file.
	 *
	 * @return string
	 */
	private function fixtureText(string $file): string {
		return (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/hr-data-exchange/payroll/' . $file);
	}//end fixtureText()

	/**
	 * A JSON fixture, decoded.
	 *
	 * @param string $file The fixture file.
	 *
	 * @return array<mixed>
	 */
	private function fixture(string $file): array {
		return json_decode($this->fixtureText(file: $file), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	/**
	 * A fake OpenRegister whose findAll() filters the given rows per schema slug.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return ORObjectService
	 */
	private function register(array $rows): ORObjectService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($rows): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$results = [];
				foreach (($rows[$schema] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) === $filters) {
						$entity = new ObjectEntity();
						$entity->setObject($row);
						$results[] = $entity;
					}
				}

				return ['results' => array_slice($results, 0, $config['limit'])];
			}
		);

		return $or;
	}//end register()

	/**
	 * The handoff as humaniq stores it, and its mutations and administration as separate objects.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>}
	 */
	private function storedHandoff(): array {
		$handoff = $this->fixture(file: 'handoff-2026-09.json');
		$mutations = array_map(static fn (array $m): array => $m + ['handoffId' => self::HANDOFF_ID], $handoff['mutations']);
		$administration = $handoff['administration'] + ['administrationId' => $handoff['administrationId']];
		unset($handoff['mutations'], $handoff['administration']);

		return [$handoff, ['PayrollHandoffMutation' => $mutations, 'hrAdministration' => [$administration]]];
	}//end storedHandoff()

	/**
	 * The real MappingService, with a lookup when one is given.
	 *
	 * @param MappingLookup|null $lookup The lookup.
	 *
	 * @return MappingService
	 */
	private function mappingService(?MappingLookup $lookup = null): MappingService {
		return new MappingService(
			loader: new ArrayLoader(templates: []),
			callService: $this->createMock(originalClassName: CallService::class),
			fileService: $this->createMock(originalClassName: FileService::class),
			objectService: $this->createMock(originalClassName: ObjectService::class),
			orObjectService: $this->createMock(originalClassName: ORObjectService::class),
			synchronizationContractService: $this->createMock(originalClassName: SynchronizationContractService::class),
			lookup: $lookup,
		);
	}//end mappingService()

	/**
	 * The pushes, as a data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function pushes(): array {
		return array_combine(array_keys(self::PUSHES), array_map(static fn (string $slug): array => [$slug], array_keys(self::PUSHES)));
	}//end pushes()

	/**
	 * Each push sends a klaargezet handoff only, and writes the verzenden transition back.
	 *
	 * @param string $slug The push slug.
	 *
	 * @return void
	 *
	 * @dataProvider pushes
	 */
	public function testEachPushSendsAReadyHandoffAndWritesVerzondenBack(string $slug): void {
		$push = $this->seeds()['synchronization'][$slug];
		$holds = new ReflectionMethod(SynchronizationService::class, 'conditionsHold');

		$this->assertTrue($holds->invoke(null, $push['conditions'], ['status' => 'klaargezet']));
		foreach (['concept', 'verzonden', 'ontvangen', 'afgesloten'] as $status) {
			$this->assertFalse($holds->invoke(null, $push['conditions'], ['status' => $status]), 'A ' . $status . ' handoff is not delivered again.');
		}

		$this->assertSame('register/schema', $push['sourceType']);
		$this->assertSame('', $push['sourceId'], 'Unbound: nothing runs until an administrator links it.');
		$this->assertSame(['status', 'deliveryReference', 'deliveredAt'], array_keys($push['writeBack']['onSuccess']));
		$this->assertSame('verzonden', $push['writeBack']['onSuccess']['status']);
		$this->assertSame('{{ now }}', $push['writeBack']['onSuccess']['deliveredAt']);
		$this->assertArrayNotHasKey('onFailure', $push['writeBack'], 'A refused delivery writes nothing to the status (design D1).');
	}//end testEachPushSendsAReadyHandoffAndWritesVerzondenBack()

	/**
	 * Each push targets its dormant bureau source and uses that bureau's mapping.
	 *
	 * @param string $slug The push slug.
	 *
	 * @return void
	 *
	 * @dataProvider pushes
	 */
	public function testEachPushTargetsItsDormantBureau(string $slug): void {
		$push = $this->seeds()['synchronization'][$slug];
		$bureau = $this->seeds(fragment: self::PUSHES[$slug]['fragment']);

		$this->assertSame(self::PUSHES[$slug]['source'], $push['targetId']);
		$this->assertFalse($bureau['source'][$push['targetId']]['isEnabled']);
		$this->assertArrayHasKey($push['sourceTargetMapping'], $bureau['mapping']);
	}//end testEachPushTargetsItsDormantBureau()

	/**
	 * The handoff as stored, embedded as the push declares, maps to exactly the bureau's example.
	 *
	 * @param string $slug The push slug.
	 *
	 * @return void
	 *
	 * @dataProvider pushes
	 */
	public function testTheEmbeddedHandoffMatchesTheBureausExample(string $slug): void {
		$push = $this->seeds()['synchronization'][$slug];
		[$handoff, $rows] = $this->storedHandoff();

		$embedded = (new RelatedObjectEmbedder(objectService: $this->register(rows: $rows)))->embed(
			declaration: $push['sourceConfig']['embedRelated'],
			object: $handoff
		);
		$mapping = $this->seeds(fragment: self::PUSHES[$slug]['fragment'])['mapping'][$push['sourceTargetMapping']];

		$this->assertSame(
			$this->fixture(file: self::PUSHES[$slug]['example']),
			$this->mappingService()->executeMapping(mapping: $mapping, input: $embedded)
		);
	}//end testTheEmbeddedHandoffMatchesTheBureausExample()

	/**
	 * The SFTP push writes one CSV file named after the loonheffingennummer and period into incoming.
	 *
	 * @return void
	 */
	public function testTheSftpPushWritesOneFileIntoIncoming(): void {
		$push = $this->seeds()['synchronization']['payroll-handoff-push-sftp'];
		$example = $this->fixture(file: 'sftp-drop-file.json');
		$delivered = [];
		$client = $this->createMock(RemoteFileClient::class);
		$client->method('deliver')->willReturnCallback(
			static function (array $source, string $path, $stream) use (&$delivered): void {
				$delivered[$path] = (string)stream_get_contents($stream);
			}
		);

		$result = (new RemoteFileTarget(client: $client))->deliver(source: [], targetConfig: $push['targetConfig'], targetObject: $example);

		$this->assertSame('sftp', $push['targetType']);
		$this->assertSame('incoming/812345671L01-2026-09.csv', $result['path']);
		$lines = explode("\n", trim($delivered['incoming/812345671L01-2026-09.csv']));
		$this->assertSame('loonheffingennummer,periode,medewerker,soort,ingangsdatum,veld,oud,nieuw', $lines[0]);
		$this->assertCount((count($example['rows']) + 1), $lines, 'A header and one line per changed field.');
	}//end testTheSftpPushWritesOneFileIntoIncoming()

	/**
	 * The return file with two payslips becomes two Payslips carrying the handoff id.
	 *
	 * @return void
	 */
	public function testTheReturnFileBecomesTwoPayslipsOfTheHandoff(): void {
		$pull = $this->seeds()['synchronization']['payroll-payslip-pull-sftp'];
		$records = (new PickedFileRecords())->read(name: 'return-2026-09.csv', content: $this->fixtureText(file: 'return-2026-09.csv'), config: $pull['sourceConfig']);
		$mapping = $this->seeds()['mapping'][$pull['sourceTargetMapping']];
		$service = $this->mappingService(lookup: $this->lookup(allowed: true));

		$payslips = array_map(static fn (array $record): array => $service->executeMapping(mapping: $mapping, input: $record), $records);

		$this->assertCount(2, $payslips);
		$this->assertSame(['LS-2026-09-0001', 'LS-2026-09-0002'], array_column($records, $pull['sourceConfig']['idPosition']), 'Keyed on the bureau\'s payslip id.');
		foreach ($payslips as $payslip) {
			$this->assertSame(self::HANDOFF_ID, $payslip['payrollHandoffId']);
			$this->assertSame('a7d2c4e1-5b6f-4a3c-8d9e-0f1a2b3c4d5e', $payslip['administrationId']);
			$this->assertSame('2026-09', $payslip['period']);
			$this->assertSame('payroll-sftp-drop', $payslip['externalSource']);
			foreach (['employeeId', 'period', 'grossPay', 'nettoPay'] as $required) {
				$this->assertArrayHasKey($required, $payslip, 'humaniq\'s Payslip requires ' . $required . '.');
			}
		}

		$this->assertSame('6f1c2b9e-3d4a-4e5f-9a8b-7c6d5e4f3a21', $payslips[0]['employeeId']);
		$this->assertSame(3950.0, $payslips[0]['grossPay'], 'A decimal comma is read as a decimal point.');
		$this->assertSame(2810.45, $payslips[0]['nettoPay']);
		$this->assertSame(156.0, $payslips[1]['hoursWorked']);
	}//end testTheReturnFileBecomesTwoPayslipsOfTheHandoff()

	/**
	 * Without the two schemas on the lookup allowlist the mapping refuses, rather than writing payslips without a handoff.
	 *
	 * @return void
	 */
	public function testTheReturnFileNeedsTheLookupAllowlist(): void {
		$pull = $this->seeds()['synchronization']['payroll-payslip-pull-sftp'];
		$records = (new PickedFileRecords())->read(name: 'return-2026-09.csv', content: $this->fixtureText(file: 'return-2026-09.csv'), config: $pull['sourceConfig']);

		$this->expectException(\Throwable::class);
		$this->expectExceptionMessage('lookup allowlist');
		$this->mappingService(lookup: $this->lookup(allowed: false))->executeMapping(mapping: $this->seeds()['mapping'][$pull['sourceTargetMapping']], input: $records[0]);
	}//end testTheReturnFileNeedsTheLookupAllowlist()

	/**
	 * After the pickup wrote both payslips, the verzonden handoff reads ontvangen with receivedAt.
	 *
	 * @return void
	 */
	public function testThePickupMovesTheHandoffToOntvangen(): void {
		$pull = $this->seeds()['synchronization']['payroll-payslip-pull-sftp'];
		$store = [
			'Payslip/slip-1' => ['payrollHandoffId' => self::HANDOFF_ID],
			'Payslip/slip-2' => ['payrollHandoffId' => self::HANDOFF_ID],
			'PayrollHandoff/' . self::HANDOFF_ID => ['period' => '2026-09', 'status' => 'verzonden'],
		];
		$saves = [];
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			static function ($id, ?ObjectFindOptions $options = null) use ($store): ?ObjectEntity {
				$data = ($store[$options->schema . '/' . $id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($data);
				return $entity;
			}
		);
		$or->method('saveObject')->willReturnCallback(
			static function ($object, ObjectSaveOptions $options) use (&$saves): ObjectEntity {
				$saves[] = ['schema' => $options->target->schema, 'uuid' => $options->uuid, 'object' => $object];
				return new ObjectEntity();
			}
		);

		$moved = (new RelatedWriteBack(objectService: $or, logger: $this->createMock(LoggerInterface::class)))->apply(
			synchronization: $pull,
			targetIds: ['slip-1', 'slip-2']
		);

		$this->assertSame('humaniq/Payslip', $pull['targetId']);
		$this->assertSame(1, $moved);
		$this->assertSame('PayrollHandoff', $saves[0]['schema']);
		$this->assertSame(self::HANDOFF_ID, $saves[0]['uuid']);
		$this->assertSame('ontvangen', $saves[0]['object']['status']);
		$this->assertNotEmpty($saves[0]['object']['receivedAt']);
	}//end testThePickupMovesTheHandoffToOntvangen()

	/**
	 * The real lookup over humaniq's administration and handoff.
	 *
	 * @param bool $allowed Whether both schemas are on the allowlist.
	 *
	 * @return MappingLookup
	 */
	private function lookup(bool $allowed): MappingLookup {
		$entries = [];
		if ($allowed === true) {
			$entry = ['addedBy' => 'admin', 'addedAt' => '2026-11-27T00:00:00+00:00'];
			$entries = ['humaniq/hrAdministration' => $entry, 'humaniq/PayrollHandoff' => $entry];
		}

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn((string)json_encode($entries));
		$allowlist = new LookupAllowlist(appConfig: $appConfig, logger: $this->createMock(originalClassName: LoggerInterface::class));

		[$handoff, $rows] = $this->storedHandoff();
		$or = $this->register(rows: $rows + ['PayrollHandoff' => [$handoff, ['id' => 'older', 'administrationId' => $handoff['administrationId'], 'period' => '2026-08']]]);
		$objects = $this->createMock(originalClassName: SourceMappingServiceInterface::class);
		$objects->method('getOpenRegisters')->willReturn($or);

		return new MappingLookup(allowlist: $allowlist, objectService: $objects, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end lookup()
}//end class
