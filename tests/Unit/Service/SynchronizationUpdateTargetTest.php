<?php

/**
 * Golden characterization of SynchronizationService::updateTargetOpenRegister().
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

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Every branch of updateTargetOpenRegister(), recorded against a golden file:
 * the contract it returns, the target object it writes back by reference, the
 * buffered-write state and every OpenRegister call.
 *
 * Writes with `events: false` run through SystemWrite, whose outcome depends
 * on whether OpenRegister is installed, so they are left out.
 * A null target object is left out too: the one caller, updateTarget(),
 * hands in [] for null.
 *
 * Regenerate the golden file on the code it must describe with
 * UPDATE_TARGET_GOLDEN=1.
 */
class SynchronizationUpdateTargetTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/update-target-open-register.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnCallback(
			function (array $config) {
				$this->log[] = ['callService.applyConfigDot', $config];
				return $config;
			}
		);

		$methods = ['saveObject', 'deleteObject'];
		$builder = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor();
		if (method_exists(ORObjectService::class, 'renderEntity') === true) {
			$builder->onlyMethods(array_merge($methods, ['renderEntity']));
		} else {
			$builder->onlyMethods($methods)->addMethods(['renderEntity']);
		}

		$openRegisters = $builder->getMock();
		$openRegisters->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true) {
				$this->log[] = ['or.saveObject', $object, $register, $schema, $uuid, $silent, $_validation];
				if (($this->case['save'] ?? '') === 'throws') {
					throw new RuntimeException('save failed');
				}

				return ObjectServiceMockBuilder::objectEntity($this, (is_array($object) === true ? $object : []), ($uuid ?? 'new-uuid'));
			}
		);
		$openRegisters->method('renderEntity')->willReturnCallback(
			function ($entity, $extend = []) {
				$this->log[] = ['or.renderEntity', $entity->getUuid(), $extend];
				return ['rendered' => $entity->getUuid(), 'sub' => ['naam' => 'kind']];
			}
		);
		$openRegisters->method('deleteObject')->willReturnCallback(
			function (?string $uuid = null, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, mixed $currentUser = null, bool $permanent = false) {
				$this->log[] = ['or.deleteObject', $uuid, $permanent];
				if (($this->case['delete'] ?? '') === 'throws') {
					throw new RuntimeException('delete refused');
				}

				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$openRegisters,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: contract, synchronization, target object, action and buffering.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$sync = ['id' => 'sync-1', 'targetId' => '4/9'];
		$contract = ['id' => 11, 'originId' => 'o-1'];
		$known = ['id' => 11, 'originId' => 'o-1', 'targetId' => 't-1'];
		$object = ['naam' => 'x', 'zaak' => 'origin-z'];
		$replace = ['sourceConfig' => ['originIdsToReplace' => ['zaak' => true]]];
		$subObjects = ['sourceConfig' => ['subObjects' => ['sub' => []]]];

		return [
			'no-target-id' => ['contract' => $contract, 'sync' => ['id' => 'sync-1'], 'object' => $object],
			'target-id-without-slash' => ['contract' => $known, 'sync' => ['targetId' => '49'], 'object' => $object],
			'save-create' => ['contract' => $contract, 'sync' => $sync, 'object' => $object],
			'save-update' => ['contract' => $known, 'sync' => $sync, 'object' => $object],
			'save-object-id-adopted' => ['contract' => $contract, 'sync' => $sync, 'object' => ['id' => 'given', 'naam' => 'x']],
			'save-contract-id-wins' => ['contract' => $known, 'sync' => $sync, 'object' => ['id' => 'given', 'naam' => 'x']],
			'save-null-target-id' => ['contract' => ($contract + ['targetId' => null]), 'sync' => $sync, 'object' => $object],
			'save-logs-off' => ['contract' => $contract, 'sync' => ($sync + ['sourceConfig' => ['logs' => false, 'validation' => false]]), 'object' => $object],
			'save-logs-truthy' => ['contract' => $contract, 'sync' => ($sync + ['sourceConfig' => ['logs' => 0, 'validation' => 0, 'events' => true]]), 'object' => $object],
			'save-replace-origin-ids' => ['contract' => $contract, 'sync' => ($sync + $replace), 'object' => $object],
			'save-throws' => ['contract' => $contract, 'sync' => $sync, 'object' => $object, 'save' => 'throws'],
			'save-sub-objects' => ['contract' => $known, 'sync' => ($sync + $subObjects), 'object' => ['naam' => 'x', 'sub' => ['naam' => 'k']]],
			'save-sub-objects-list' => ['contract' => $contract, 'sync' => ($sync + $subObjects), 'object' => ['naam' => 'x']],
			'buffer-create' => ['contract' => $contract, 'sync' => $sync, 'object' => $object, 'buffer' => true],
			'buffer-update' => ['contract' => $known, 'sync' => ($sync + ['sourceConfig' => ['validation' => false, 'events' => false, 'logs' => false]]), 'object' => $object, 'buffer' => true],
			'buffer-empty-target-id' => ['contract' => ($contract + ['targetId' => '']), 'sync' => $sync, 'object' => $object, 'buffer' => true],
			'buffer-blocked-by-actions' => ['contract' => $contract, 'sync' => ($sync + ['actions' => ['a-1']]), 'object' => $object, 'buffer' => true],
			'buffer-empty-actions' => ['contract' => $contract, 'sync' => ($sync + ['actions' => []]), 'object' => $object, 'buffer' => true],
			'buffer-blocked-by-sub-objects' => ['contract' => $contract, 'sync' => ($sync + $subObjects), 'object' => ['naam' => 'x'], 'buffer' => true],
			'delete' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => 'delete'],
			'delete-without-target' => ['contract' => $contract, 'sync' => $sync, 'object' => $object, 'action' => 'delete'],
			'delete-empty-target' => ['contract' => ($contract + ['targetId' => '']), 'sync' => $sync, 'object' => $object, 'action' => 'delete'],
			'delete-numeric-target' => ['contract' => ['targetId' => 77], 'sync' => $sync, 'object' => $object, 'action' => 'delete'],
			'delete-throws' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => 'delete', 'delete' => 'throws'],
			'delete-without-register' => ['contract' => $known, 'sync' => ['id' => 'sync-1'], 'object' => $object, 'action' => 'delete'],
			'purge' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => 'purge'],
			'purge-without-target' => ['contract' => $contract, 'sync' => $sync, 'object' => $object, 'action' => 'purge'],
			'purge-throws' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => 'purge', 'delete' => 'throws'],
			'unknown-action' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => 'archive'],
			'null-action' => ['contract' => $known, 'sync' => $sync, 'object' => $object, 'action' => null],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$method = new ReflectionMethod(SynchronizationService::class, 'updateTargetOpenRegister');
		$buffered = new ReflectionProperty(SynchronizationService::class, 'lastTargetWriteBuffered');
		$buffer = new ReflectionProperty(SynchronizationService::class, 'targetWriteBuffer');
		$bufferWrites = new ReflectionProperty(SynchronizationService::class, 'bufferWrites');

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$service = $this->service();
			$bufferWrites->setValue($service, ($case['buffer'] ?? false));
			$buffered->setValue($service, true);
			$this->log = [];

			$targetObject = $case['object'];
			$arguments = [$case['contract'], $case['sync'], &$targetObject];
			if (array_key_exists('action', $case) === true) {
				$arguments[] = $case['action'];
			}

			try {
				$outcome = ['return' => $method->invokeArgs($service, $arguments)];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => preg_replace('/, called in .*$/', '', $exception->getMessage())];
			}

			$actual[$name] = [
				'outcome' => $outcome,
				'targetObject' => $targetObject,
				'buffered' => $buffered->getValue($service),
				'buffer' => $buffer->getValue($service),
				'log' => $this->log,
			];
		}//end foreach

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('UPDATE_TARGET_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
