<?php

/**
 * The engine paths a payroll handoff exchange runs on: embedding, a file target, the time stamp and the related write-back.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-deliver-a-file-to-an-sftp-or-ftps-server-req-hrx-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Synchronization\ExternSyncRun;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Support\LegacyObjectArgs;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The real SynchronizationService over a fake OpenRegister, transport and remote file client.
 */
final class SynchronizationPayrollExchangeTest extends TestCase {

	private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

	/**
	 * Objects OpenRegister holds, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every save: object, register, schema, uuid, silent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every file delivery: path and text.
	 *
	 * @var list<array{path: string, text: string}>
	 */
	private array $delivered = [];

	private ?\Throwable $deliveryFailure = null;

	/**
	 * Rows findAll() answers, per schema slug.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	protected function setUp(): void {
		$this->store = [
			'drop-uuid' => ['type' => 'sftp', 'location' => 'sftp.bureau.example.nl', 'name' => 'Payroll bureau SFTP drop'],
			'loket-uuid' => ['location' => 'https://api.loket.example.nl/v2', 'name' => 'Loket.nl payroll'],
			'handoff-1' => ['administrationId' => 'adm-1', 'period' => '2026-09', 'status' => 'klaargezet'],
		];
	}//end setUp()

	/**
	 * The engine over the fakes.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(($this->store[(string)$id] ?? []));
				return $entity;
			}
		);
		$or->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				$schema = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				$results = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					if (array_intersect_assoc($filters, $row) === $filters) {
						$entity = new ObjectEntity();
						$entity->setObject($row);
						$results[] = $entity;
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function (...$legacyArgs) {
				[$object, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent] = LegacyObjectArgs::positional(method: 'saveObject', args: $legacyArgs);
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function () {
				$log = new ObjectEntity();
				$log->setObject(['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => '{"id":"LKT-MUT-2026-0915"}']]);
				return $log;
			}
		);

		$client = $this->createMock(RemoteFileClient::class);
		$client->method('deliver')->willReturnCallback(
			function (array $source, string $path, $stream): void {
				if ($this->deliveryFailure !== null) {
					throw $this->deliveryFailure;
				}

				$this->delivered[] = ['path' => $path, 'text' => (string)stream_get_contents($stream), 'host' => (string)($source['location'] ?? '')];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === RemoteFileClient::class ? $client : null));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$sourceMapping = $this->createMock(ObjectService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($or);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$container,
			$or,
			$sourceMapping,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * A push of a handoff to an SFTP drop, as the seed declares it.
	 *
	 * @param string $targetType The target type.
	 *
	 * @return array<string, mixed>
	 */
	private function sftpPush(string $targetType = 'sftp'): array {
		return [
			'id' => 'push-sftp',
			'sourceType' => 'register/schema',
			'sourceId' => 'humaniq/PayrollHandoff',
			'targetType' => $targetType,
			'targetId' => 'drop-uuid',
			'targetConfig' => ['path' => 'incoming'],
			'writeBack' => [
				'onSuccess' => ['status' => 'verzonden', 'deliveryReference' => '{{ response.path }}', 'deliveredAt' => '{{ now }}'],
				'onFailure' => ['deliveryError' => '{{ error.message }}'],
			],
		];
	}//end sftpPush()

	/**
	 * The mapped handoff file.
	 *
	 * @return array<string, mixed>
	 */
	private function mappedFile(): array {
		return ['fileName' => '812345671L01-2026-09.csv', 'rows' => [['medewerker' => 'e1', 'veld' => 'hoursPerWeek', 'nieuw' => 36]]];
	}//end mappedFile()

	/**
	 * An `sftp` target delivers the file and writes verzonden, the path and the time back onto the handoff, silently.
	 *
	 * @return void
	 */
	public function testAnSftpTargetDeliversTheFileAndWritesTheOutcomeBack(): void {
		$file = $this->mappedFile();
		$contract = $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-sftp', 'originId' => 'handoff-1'],
			targetObject: $file,
			synchronization: $this->sftpPush()
		);

		$this->assertSame('incoming/812345671L01-2026-09.csv', $contract['targetId']);
		$this->assertSame('incoming/812345671L01-2026-09.csv', $this->delivered[0]['path']);
		$this->assertSame('sftp.bureau.example.nl', $this->delivered[0]['host'], 'The target source is the one the synchronization names.');
		$this->assertSame("medewerker,veld,nieuw\ne1,hoursPerWeek,36\n", $this->delivered[0]['text']);

		$this->assertCount(1, $this->saves);
		$save = $this->saves[0];
		$this->assertSame(['humaniq', 'PayrollHandoff', 'handoff-1'], [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertTrue($save['silent']);
		$this->assertSame('verzonden', $save['object']['status']);
		$this->assertSame('incoming/812345671L01-2026-09.csv', $save['object']['deliveryReference']);
		$this->assertMatchesRegularExpression(self::ISO, $save['object']['deliveredAt']);
		$this->assertSame('adm-1', $save['object']['administrationId'], 'The handoff keeps its own fields.');
	}//end testAnSftpTargetDeliversTheFileAndWritesTheOutcomeBack()

	/**
	 * An `ftps` target takes the same path.
	 *
	 * @return void
	 */
	public function testAnFtpsTargetTakesTheSamePath(): void {
		$file = $this->mappedFile();
		$this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-sftp', 'originId' => 'handoff-1'],
			targetObject: $file,
			synchronization: $this->sftpPush(targetType: 'ftps')
		);

		$this->assertCount(1, $this->delivered);
	}//end testAnFtpsTargetTakesTheSamePath()

	/**
	 * A refused delivery writes the failure once and fails the push; the status is not touched.
	 *
	 * @return void
	 */
	public function testARefusedDeliveryWritesTheFailureAndFailsThePush(): void {
		$this->deliveryFailure = new RemoteFileException('The SFTP server refused the write.');
		$file = $this->mappedFile();

		try {
			$this->service()->updateTarget(
				synchronizationContract: ['synchronizationId' => 'push-sftp', 'originId' => 'handoff-1'],
				targetObject: $file,
				synchronization: $this->sftpPush()
			);
			$this->fail('The refusal must reach the caller.');
		} catch (RemoteFileException $e) {
			$this->assertSame('The SFTP server refused the write.', $e->getMessage());
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('The SFTP server refused the write.', $this->saves[0]['object']['deliveryError']);
		$this->assertSame('klaargezet', $this->saves[0]['object']['status']);
	}//end testARefusedDeliveryWritesTheFailureAndFailsThePush()

	/**
	 * A delete removes nothing on the partner's server.
	 *
	 * @return void
	 */
	public function testADeleteRemovesNothingOnTheServer(): void {
		$file = $this->mappedFile();
		$contract = $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-sftp', 'originId' => 'handoff-1', 'targetId' => 'incoming/a.csv'],
			targetObject: $file,
			action: 'delete',
			synchronization: $this->sftpPush()
		);

		$this->assertSame('incoming/a.csv', $contract['targetId']);
		$this->assertSame([], $this->delivered);
		$this->assertSame([], $this->saves);
	}//end testADeleteRemovesNothingOnTheServer()

	/**
	 * An API push's write-back can stamp the time with `{{ now }}`.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-write-back-can-stamp-the-time-req-hrx-006
	 *
	 * @return void
	 */
	public function testAnApiPushStampsTheDeliveryTime(): void {
		$push = [
			'id' => 'push-loket',
			'sourceType' => 'register/schema',
			'sourceId' => 'humaniq/PayrollHandoff',
			'targetType' => 'api',
			'targetId' => 'loket-uuid',
			'targetConfig' => ['endpoint' => '/payrollmutations', 'targetIdPosition' => 'id'],
			'writeBack' => ['onSuccess' => ['status' => 'verzonden', 'deliveryReference' => '{{ response.id }}', 'deliveredAt' => '{{ now }}']],
		];
		$mapped = ['handoffId' => 'handoff-1', 'mutations' => []];

		$this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-loket', 'originId' => 'handoff-1'],
			targetObject: $mapped,
			synchronization: $push
		);

		$this->assertCount(1, $this->saves);
		$this->assertSame('LKT-MUT-2026-0915', $this->saves[0]['object']['deliveryReference']);
		$this->assertMatchesRegularExpression(self::ISO, $this->saves[0]['object']['deliveredAt']);
	}//end testAnApiPushStampsTheDeliveryTime()

	/**
	 * A push's embedRelated adds the mutations and the administration to the object before it is mapped.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-carry-the-objects-that-point-at-the-pushed-object-req-hrx-003
	 *
	 * @return void
	 */
	public function testThePushEmbedsTheDeclaredRelations(): void {
		$this->rows = [
			'PayrollHandoffMutation' => [['handoffId' => 'handoff-1', 'kind' => 'salary'], ['handoffId' => 'handoff-2', 'kind' => 'hours']],
			'hrAdministration' => [['administrationId' => 'adm-1', 'loonheffingennummer' => '812345671L01']],
		];
		$push = [
			'sourceConfig' => [
				'embedRelated' => [
					'mutations' => ['schema' => 'humaniq/PayrollHandoffMutation', 'filters' => ['handoffId' => 'id']],
					'administration' => ['schema' => 'humaniq/hrAdministration', 'filters' => ['administrationId' => 'administrationId'], 'single' => true],
				],
			],
		];

		$embedded = (new ReflectionMethod(SynchronizationService::class, 'embedRelatedObjects'))->invoke(
			$this->service(),
			$push,
			['id' => 'handoff-1'] + $this->store['handoff-1']
		);

		$this->assertSame(['salary'], array_column($embedded['mutations'], 'kind'));
		$this->assertSame('812345671L01', $embedded['administration']['loonheffingennummer']);
	}//end testThePushEmbedsTheDeclaredRelations()

	/**
	 * After a pull run, the handoff its payslips name is moved, and the run records how many were moved.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-pull-can-move-the-object-its-written-objects-point-at-req-hrx-004
	 *
	 * @return void
	 */
	public function testAPullRunMovesTheRelatedObjectOnce(): void {
		$this->store['handoff-1']['status'] = 'verzonden';
		$this->store['slip-1'] = ['payrollHandoffId' => 'handoff-1'];
		$this->store['slip-2'] = ['payrollHandoffId' => 'handoff-1'];

		$run = new ExternSyncRun();
		$run->synchronization = [
			'id' => 'pull-sftp',
			'targetType' => 'register/schema',
			'targetId' => 'humaniq/Payslip',
			'targetConfig' => [
				'relatedWriteBack' => [
					'schema' => 'humaniq/PayrollHandoff',
					'field' => 'payrollHandoffId',
					'when' => ['status' => 'verzonden'],
					'set' => ['status' => 'ontvangen', 'receivedAt' => '{{ now }}'],
				],
			],
		];
		$run->targetIds = ['slip-1', 'slip-2'];

		$apply = new ReflectionMethod(SynchronizationService::class, 'applyRelatedWriteBack');
		$apply->invoke($this->service(), $run);

		$this->assertSame(['moved' => 1], $run->result['relatedWriteBack']);
		$this->assertCount(1, $this->saves);
		$this->assertSame('ontvangen', $this->saves[0]['object']['status']);
		$this->assertFalse((bool)$this->saves[0]['silent']);

		$this->saves = [];
		$test = clone $run;
		$test->isTest = true;
		$test->result = [];
		$apply->invoke($this->service(), $test);
		$this->assertSame([], $this->saves, 'A test run writes nothing, so it moves nothing.');
		$this->assertArrayNotHasKey('relatedWriteBack', $test->result);
	}//end testAPullRunMovesTheRelatedObjectOnce()
}//end class
