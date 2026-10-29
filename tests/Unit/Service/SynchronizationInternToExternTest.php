<?php

/**
 * Golden characterization of SynchronizationService::synchronizeInternToExtern().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every branch of synchronizeInternToExtern(), recorded against a golden file:
 * each collaborator call with its arguments, the returned contract (or the
 * exception) and the object written back by reference. synchronizeContract()
 * is stubbed; its own behaviour has its own tests.
 *
 * Regenerate the golden file on the code it must describe with
 * INTERN_TO_EXTERN_GOLDEN=1.
 */
class SynchronizationInternToExternTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/synchronize-intern-to-extern.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * How the collaborators answer in the current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = [];

	/**
	 * An OpenRegister object entity with the given uuid and data.
	 *
	 * @param string|null $uuid The uuid.
	 * @param array $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The engine over recording collaborators, with synchronizeContract() stubbed.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$contracts = $this->createMock(SynchronizationContractService::class);
		$contracts->method('findAllObjects')->willReturnCallback(
			function (array $filters = []) {
				$this->log[] = ['contracts.findAllObjects', $filters];
				return array_map(fn (array $data) => $this->entity($data['uuid'], $data), ($this->answers['contracts'] ?? []));
			}
		);
		$contracts->method('createFromArray')->willReturnCallback(
			function (array $object) {
				$this->log[] = ['contracts.createFromArray', $object];
				return array_merge($object, ['uuid' => 'new-contract']);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === SynchronizationContractService::class ? $contracts : null)
		);

		$mapper = new class($this) {
			/**
			 * @param SynchronizationInternToExternTest $test The recording test.
			 */
			public function __construct(private SynchronizationInternToExternTest $test) {
			}

			/**
			 * Records a lookup.
			 *
			 * @param mixed $identifier The id.
			 *
			 * @return ObjectEntity
			 */
			public function find($identifier) {
				return $this->test->lookup('mapper.find', $identifier);
			}
		};

		$openRegisters = $this->createMock(ORObjectService::class);
		$openRegisters->method('find')->willReturnCallback(
			fn ($id, $_extend = []) => $this->lookup('openRegisters.find', [$id, $_extend])
		);
		$openRegisters->method('getMapper')->willReturn($mapper);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturn($openRegisters);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnCallback(
			function (array $config) {
				$this->log[] = ['call.applyConfigDot', $config];
				return $config;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message, array $context = []) {
				$this->log[] = ['logger.debug', $message, $context];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$calls,
					$this->createMock(MappingService::class),
					$container,
					$this->createMock(ORObjectService::class),
					$objectService,
					$logger,
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['synchronizeContract'])
			->getMock();
		$service->method('synchronizeContract')->willReturnCallback(
			function (array $contract, FlowToken &$flowToken, ?array $synchronization = null, array &$object = [], ?bool $isTest = false, ?bool $force = false, ?SynchronizationRunLog $log = null, ?string $mutationType = null, $trace = null) {
				$this->log[] = ['synchronizeContract', $contract, ($synchronization['id'] ?? null), $object, $isTest, $force, $log !== null, $mutationType, $trace !== null];
				$object['touchedByContract'] = true;
				$answer = ($this->answers['result'] ?? 'contract');
				if ($answer === 'exception') {
					return new Exception('contract failed');
				}

				if ($answer === 'no-contract') {
					return ['log' => []];
				}

				return ['log' => ['ok'], 'contract' => array_merge($contract, ['synced' => true]), 'resultAction' => 'update'];
			}
		);

		return $service;
	}//end service()

	/**
	 * Record one OpenRegister lookup and answer it.
	 *
	 * @param string $name The call.
	 * @param mixed $args The arguments.
	 *
	 * @return ObjectEntity
	 */
	public function lookup(string $name, mixed $args): ObjectEntity {
		$this->log[] = [$name, $args];
		if ($args === 'missing') {
			throw new DoesNotExistException('missing');
		}

		return $this->entity('found', ['found' => $args]);
	}//end lookup()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$found = [['uuid' => 'c-1', 'originId' => 'o-1']];

		return [
			'self-id-existing-contract' => ['object' => ['@self' => ['id' => 'o-1'], 'naam' => 'x'], 'contracts' => $found],
			'plain-id-new-contract' => ['object' => ['id' => 'o-2', 'naam' => 'y']],
			'no-id-new-contract' => ['object' => ['naam' => 'z']],
			'no-id-test' => ['object' => ['naam' => 'z'], 'isTest' => true],
			'existing-contract-test' => ['object' => ['id' => 'o-1'], 'contracts' => $found, 'isTest' => true, 'force' => true, 'mutation' => 'update'],
			'entity-with-uuid' => ['entity' => ['e-1', ['naam' => 'entity', 'id' => 'inner']]],
			'entity-without-uuid' => ['entity' => [null, ['naam' => 'entity']]],
			'sync-uuid-when-no-id' => ['object' => ['id' => 'o-3'], 'sync' => ['id' => null, 'uuid' => 'sync-uuid']],
			'conditions-hold' => ['object' => ['id' => 'o-4', 'status' => 'open'], 'sync' => ['conditions' => ['==' => [['var' => 'status'], 'open']]]],
			'conditions-fail' => ['object' => ['id' => 'o-4', 'status' => 'closed'], 'sync' => ['conditions' => ['==' => [['var' => 'status'], 'open']]]],
			'conditions-list' => ['object' => ['id' => 'o-4', 'status' => 'open'], 'sync' => ['conditions' => [['==' => [['var' => 'status'], 'open']], ['==' => [1, 2]]]]],
			'extend-before-conditions' => ['object' => ['id' => 'o-5', 'zaak' => 'https://x.invalid/zaken/z-9', 'mist' => 'missing'], 'sourceConfig' => ['extendInputBeforeConditions' => 'zaak,mist']],
			'extend-before-conditions-fetch' => ['object' => ['@self' => ['id' => 'o-6']], 'sourceConfig' => ['extendInputBeforeConditions' => ['zaak'], 'extendInputFetchObjectBeforeConditions' => 'yes']],
			'extend-before-conditions-fetch-off' => ['object' => ['id' => 'o-6', 'zaak' => 'z'], 'sourceConfig' => ['extendInputBeforeConditions' => ['zaak'], 'extendInputFetchObjectBeforeConditions' => 'no']],
			'extend-before-conditions-entity' => ['entity' => ['e-2', ['id' => 'o-7', 'zaak' => 'z']], 'sourceConfig' => ['extendInputBeforeConditions' => ['zaak']]],
			'extend-before-then-conditions-fail' => ['object' => ['id' => 'o-8', 'zaak' => 'z'], 'sourceConfig' => ['extendInputBeforeConditions' => ['zaak']], 'sync' => ['conditions' => ['!!' => [['var' => 'nothing']]]]],
			'target-extend' => ['object' => ['id' => 'o-9', 'zaak' => 'z'], 'targetConfig' => ['extend_input' => ['zaak']]],
			'target-extend-fetch-zero' => ['object' => ['id' => 'o-9'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => 0]],
			'target-extend-fetch-true' => ['object' => ['id' => 'o-9'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => true]],
			'target-extend-fetch-string-true' => ['object' => ['id' => 'o-9'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => 'true']],
			'target-extend-fetch-string-false' => ['object' => ['id' => 'o-9'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => 'false']],
			'target-extend-fetch-false' => ['object' => ['id' => 'o-9', 'zaak' => 'z'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => false]],
			'target-extend-fetch-two' => ['object' => ['id' => 'o-9', 'zaak' => 'z'], 'targetConfig' => ['extend_input' => ['zaak'], 'extend_input_fetch_object' => 2]],
			'target-extend-entity' => ['entity' => ['e-3', ['zaak' => 'z']], 'targetConfig' => ['extend_input' => 'zaak']],
			'contract-exception' => ['object' => ['id' => 'o-1'], 'contracts' => $found, 'result' => 'exception'],
			'contract-exception-test' => ['object' => ['id' => 'o-1'], 'isTest' => true, 'result' => 'exception'],
			'contract-without-contract-key' => ['object' => ['id' => 'o-1'], 'contracts' => $found, 'result' => 'no-contract'],
		];
	}//end cases()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->answers = $case;
		$synchronization = array_merge(
			[
				'id' => 'sync-1',
				'sourceConfig' => ($case['sourceConfig'] ?? []),
				'targetConfig' => ($case['targetConfig'] ?? []),
			],
			($case['sync'] ?? [])
		);
		$object = ($case['object'] ?? null);
		if (isset($case['entity']) === true) {
			$object = $this->entity($case['entity'][0], $case['entity'][1]);
		}

		$flowToken = new FlowToken();
		$service = $this->service();
		$this->log = [];
		$method = new ReflectionMethod(SynchronizationService::class, 'synchronizeInternToExtern');
		$args = [$synchronization, &$object, new SynchronizationRunLog(), &$flowToken, ($case['isTest'] ?? false), ($case['force'] ?? false), ($case['mutation'] ?? null), null];
		try {
			$outcome = ['return' => $method->invokeArgs($service, $args)];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => preg_replace('/, called in .*$/', '', $exception->getMessage())];
		}

		if ($object instanceof ObjectEntity) {
			$object = ['entity' => $object->getUuid(), 'object' => $object->getObject()];
		}

		return ['outcome' => $outcome, 'object' => $object, 'log' => $this->log];
	}//end runCase()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
		if (getenv('INTERN_TO_EXTERN_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
