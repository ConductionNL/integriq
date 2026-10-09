<?php

/**
 * A ZGW write-back updates the resource it was pulled from, and a refusal keeps the edit and marks a conflict.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\TargetWriteRefusedException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The write path against the real seeded zgw-zaken-push synchronization, bound as the installer binds it.
 */
class SynchronizationWriteBackTest extends TestCase {

	private const STORE = 'https://open-zaak.example.nl/zaken/api/v1';

	private const ZAAK = self::STORE . '/zaken/d4d5d0d6-2f3c-4f0b-9b5c-1d3a1c6e7f80';

	/**
	 * The seeded push synchronization, bound to cases/case.
	 *
	 * @var array<string, mixed>
	 */
	private array $push = [];

	/**
	 * The calls the target received: method, endpoint.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $calls = [];

	/**
	 * Every silent save of a local object.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	private int $answer = 200;

	protected function setUp(): void {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/zgw-consumer-sets.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? null) === 'zgw-zaken-push') {
				unset($object['@self']);
				$this->push = $object;
			}
		}

		$this->push['id']       = 'push-uuid';
		$this->push['sourceId'] = 'cases/case';
	}//end setUp()

	private function service(?array $push=null): SynchronizationService {
		$push = ($push ?? $this->push);

		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id) use ($push) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(match ((string)$id) {
					'push-uuid' => $push,
					default => ['location' => self::STORE, 'name' => 'Zaken API'],
				});
				return $entity;
			}
		);
		$or->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false) {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint='', string $method='GET') {
				$this->calls[] = [$method, $endpoint];
				$log           = new ObjectEntity();
				$log->setObject(['statusCode' => $this->answer, 'response' => ['statusCode' => $this->answer, 'body' => '{"url":"' . self::ZAAK . '"}']]);
				return $log;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$sourceMapping = $this->createMock(ObjectService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($or);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$or,
			$sourceMapping,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The zaak as the bound schema holds it after a pull, edited locally.
	 *
	 * @return array<string, mixed>
	 */
	private function editedZaak(): array {
		return ['url' => self::ZAAK, 'identificatie' => 'ZAAK-2026-0001', 'omschrijving' => 'Edited locally'];
	}//end editedZaak()

	/**
	 * A pulled zaak is updated where it lives, not created a second time.
	 *
	 * @return void
	 */
	public function testAPulledZaakIsPatchedNotCreatedAgain(): void {
		$target   = $this->editedZaak();
		$contract = $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-1'],
			targetObject: $target
		);

		$this->assertSame([['PATCH', '/zaken/d4d5d0d6-2f3c-4f0b-9b5c-1d3a1c6e7f80']], $this->calls);
		$this->assertSame(self::ZAAK, $contract['targetId']);
	}//end testAPulledZaakIsPatchedNotCreatedAgain()

	/**
	 * The store answering 400 is a refusal naming the property that records it.
	 *
	 * @return void
	 */
	public function testARefusedWriteRaisesAConflict(): void {
		$this->answer = 400;
		$target       = $this->editedZaak();

		try {
			$this->service()->updateTarget(
				synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-1'],
				targetObject: $target
			);
			$this->fail('A 400 must not read as a successful write-back.');
		} catch (TargetWriteRefusedException $e) {
			$this->assertSame(400, $e->getStatusCode());
			$this->assertSame('syncStatus', $e->getStatusProperty());
		}
	}//end testARefusedWriteRaisesAConflict()

	/**
	 * An outage is not a conflict.
	 *
	 * @return void
	 */
	public function testAServerErrorIsNotAConflict(): void {
		$this->answer = 503;
		$target       = $this->editedZaak();

		$this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-1'],
			targetObject: $target
		);

		$this->assertCount(1, $this->calls);
	}//end testAServerErrorIsNotAConflict()

	/**
	 * A synchronization that records no conflicts keeps today's behaviour on a 400.
	 *
	 * @return void
	 */
	public function testASynchronizationWithoutTheMarkerIsUnchanged(): void {
		$this->answer = 400;
		$push         = $this->push;
		unset($push['targetConfig']['conflictStatusProperty']);
		$target = $this->editedZaak();

		$this->service($push)->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-1'],
			targetObject: $target
		);

		$this->assertCount(1, $this->calls);
	}//end testASynchronizationWithoutTheMarkerIsUnchanged()

	/**
	 * A remote id on another host is refused before any call: the write carries the store's credentials.
	 *
	 * @return void
	 */
	public function testARemoteIdOffTheStoreIsRefusedBeforeAnyCall(): void {
		$target        = $this->editedZaak();
		$target['url'] = 'https://attacker.example/zaken/api/v1/zaken/1';

		try {
			$this->service()->updateTarget(
				synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-1'],
				targetObject: $target
			);
			$this->fail('A url off the store must be refused.');
		} catch (\Exception $e) {
			$this->assertStringContainsString('not under the target source location', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
	}//end testARemoteIdOffTheStoreIsRefusedBeforeAnyCall()

	/**
	 * A zaak made locally (no url yet) is still created, and the store's url becomes its target id.
	 *
	 * @return void
	 */
	public function testALocalZaakIsStillCreated(): void {
		$target = ['identificatie' => 'ZAAK-2026-0002'];

		$contract = $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'local-2'],
			targetObject: $target
		);

		$this->assertSame([['POST', '/zaken']], $this->calls);
		$this->assertSame(self::ZAAK, $contract['targetId']);
	}//end testALocalZaakIsStillCreated()

	/**
	 * The local object a refused push came from.
	 *
	 * @param string|null $status Its current syncStatus.
	 *
	 * @return ObjectEntity
	 */
	private function localZaak(?string $status=null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('local-1');
		$object->setRegister('cases');
		$object->setSchema('case');
		$data = $this->editedZaak();
		if ($status !== null) {
			$data['syncStatus'] = $status;
		}

		$object->setObject($data);
		return $object;
	}//end localZaak()

	/**
	 * The engine with synchronize() answering as the push did; the object handler is real.
	 *
	 * @param \Throwable|null $refusal What the push raised, or null when the store accepted it.
	 *
	 * @return SynchronizationService
	 */
	private function handler(?\Throwable $refusal): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('findAll')->willReturnCallback(
			function (array $config=[]) {
				$push = new ObjectEntity();
				$push->setUuid('push-uuid');
				$push->setObject($this->push);
				return ['results' => [$push], 'total' => 1];
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false) {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$this->createMock(CallService::class),
					$this->createMock(MappingService::class),
					$this->createMock(ContainerInterface::class),
					$or,
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['synchronize'])
			->getMock();

		if ($refusal !== null) {
			$service->method('synchronize')->willThrowException($refusal);
		} else {
			$service->method('synchronize')->willReturn([]);
		}

		return $service;
	}//end handler()

	/**
	 * A refused write-back keeps the local edit and marks the object, silently so the push does not loop.
	 *
	 * @return void
	 */
	public function testARefusalKeepsTheEditAndMarksAConflict(): void {
		$this->handler(new TargetWriteRefusedException(statusCode: 400, statusProperty: 'syncStatus'))
			->handleObjectEventSynchronization($this->localZaak(), 'update');

		$this->assertCount(1, $this->saves);
		$save = $this->saves[0];
		$this->assertTrue($save['silent'], 'A normal save fires the push again, which is refused again.');
		$this->assertSame(['cases', 'case', 'local-1'], [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertSame($this->editedZaak() + ['syncStatus' => 'conflict'], $save['object'], 'The edit stays; only the marker is added.');
	}//end testARefusalKeepsTheEditAndMarksAConflict()

	/**
	 * An accepted push after a conflict clears the marker.
	 *
	 * @return void
	 */
	public function testAnAcceptedPushClearsTheConflict(): void {
		$this->handler(null)->handleObjectEventSynchronization($this->localZaak('conflict'), 'update');

		$this->assertCount(1, $this->saves);
		$this->assertSame('synced', $this->saves[0]['object']['syncStatus']);
		$this->assertTrue($this->saves[0]['silent']);
	}//end testAnAcceptedPushClearsTheConflict()

	/**
	 * An accepted push on an object with no conflict writes nothing extra.
	 *
	 * @return void
	 */
	public function testAnAcceptedPushWithoutAConflictWritesNothing(): void {
		$this->handler(null)->handleObjectEventSynchronization($this->localZaak(), 'update');

		$this->assertSame([], $this->saves);
	}//end testAnAcceptedPushWithoutAConflictWritesNothing()
}//end class
