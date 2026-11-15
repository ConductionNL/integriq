<?php

/**
 * Golden characterization of SynchronizationService::writeObjectToTarget().
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
 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Every create, update and delete path of writeObjectToTarget(), recorded
 * against a golden file: each collaborator call with its arguments, the
 * returned contract (or the exception), the target object written back by
 * reference, and the validation findings left pending.
 *
 * Regenerate the golden file on the code it must describe with
 * WRITE_TARGET_GOLDEN=1.
 */
class SynchronizationWriteObjectToTargetTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/write-object-to-target.json';

	private const LOCATION = 'https://api.example.invalid/v1';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The answer of the target: status, body, or a throwable.
	 *
	 * @var array{status: int, body: string, throw: ?\Throwable}
	 */
	private array $answer = ['status' => 200, 'body' => '{}', 'throw' => null];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null) {
				$this->log[] = ['or.find', (string)$id, $register, $schema];
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				if ($schema === 'source') {
					$entity->setObject(['uuid' => (string)$id, 'name' => 'Target', 'location' => self::LOCATION]);
					return $entity;
				}

				$entity->setObject(['naam' => 'origin ' . $id]);
				return $entity;
			}
		);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->log[] = ['or.saveObject', $object, $register, $schema, $uuid];
				return new ObjectEntity();
			}
		);

		$openRegisters = $this->createMock(ORObjectService::class);
		$openRegisters->method('setRegister')->willReturnCallback(
			function ($register) use ($openRegisters) {
				$this->log[] = ['openRegisters.setRegister', $register];
				return $openRegisters;
			}
		);
		$openRegisters->method('setSchema')->willReturnCallback(
			function ($schema) use ($openRegisters) {
				$this->log[] = ['openRegisters.setSchema', $schema];
				return $openRegisters;
			}
		);
		$openRegisters->method('find')->willReturnCallback(
			function ($id) {
				$this->log[] = ['openRegisters.find', (string)$id];
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(['titel' => 'stored ' . $id, 'zaak' => ['id' => 'z-9']]);
				return $entity;
			}
		);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturn($openRegisters);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnCallback(
			function (array $config) {
				$this->log[] = ['call.applyConfigDot', $config];
				return $config;
			}
		);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = [], bool $asynchronous = false, bool $createCertificates = true, bool $overruleAuth = false, bool $read = false, bool $runningSupportRequest = false, mixed $sink = null, ?ExecutionTraceContext $trace = null) {
				$this->log[] = ['call.call', $source->getUuid(), $endpoint, $method, $config, $read, $trace !== null];
				if ($this->answer['throw'] !== null) {
					throw $this->answer['throw'];
				}

				$callLog = new ObjectEntity();
				$callLog->setObject(['statusCode' => $this->answer['status'], 'response' => ['statusCode' => $this->answer['status'], 'body' => $this->answer['body']]]);
				return $callLog;
			}
		);

		$mapping = $this->createMock(MappingService::class);
		$mapping->method('getMapping')->willReturnCallback(
			function ($id) {
				$this->log[] = ['mapping.getMapping', $id];
				return (new Mapping())->hydrate(['name' => $id]);
			}
		);
		$mapping->method('executeMapping')->willReturnCallback(
			function ($map, $input) {
				$this->log[] = ['mapping.executeMapping', $map->getName(), $input];
				return ['mappedBy' => $map->getName(), 'from' => $input];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				$this->log[] = ['container.get', $id];
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $this->fileService();
				}

				return null;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$calls,
			$mapping,
			$container,
			$or,
			$objectService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * A FileService stand-in that answers one file by id.
	 *
	 * @return object
	 */
	private function fileService(): object {
		return new class {
			/**
			 * The file with the given id.
			 *
			 * @param int $id The file id.
			 *
			 * @return object
			 */
			public function getFileById(int $id): object {
				return new class($id) {
					/**
					 * @param int $id The file id.
					 */
					public function __construct(private int $id) {
					}

					/**
					 * @return string
					 */
					public function getContent(): string {
						return 'content-' . $this->id;
					}

					/**
					 * @return string
					 */
					public function getName(): string {
						return 'file-' . $this->id . '.pdf';
					}

					/**
					 * @return string
					 */
					public function getMimeType(): string {
						return 'application/pdf';
					}
				};
			}
		};
	}//end fileService()

	/**
	 * The cases: each overrides the base synchronization, contract and call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$writeBack = [
			'onSuccess' => ['status' => 'written', 'remote' => '{{ targetId }}'],
			'onFailure' => ['status' => 'failed', 'reason' => '{{ error.message }}'],
		];

		return [
			'delete-endpoint-spaced-method-mapping' => ['mutation' => 'delete', 'contract' => ['targetId' => 't-1'], 'config' => ['deleteEndpoint' => '/remove/{{ originId }}', 'deleteMethod' => 'POST', 'deleteMapping' => 'map-del'], 'answer' => [200, '{"deleted":true}']],
			'delete-endpoint-tight' => ['mutation' => 'delete', 'contract' => ['targetId' => 't-1'], 'config' => ['deleteEndpoint' => '/remove/{{originId}}'], 'answer' => [204, '']],
			'delete-default-location-stripped' => ['mutation' => 'delete', 'contract' => ['targetId' => 't-2'], 'endpoint' => self::LOCATION . '/items', 'answer' => [200, '{"gone":1}']],
			'delete-traced' => ['mutation' => 'delete', 'trace' => true, 'contract' => ['targetId' => 't-4'], 'answer' => [200, '{}']],
			'delete-api-source' => ['mutation' => 'delete', 'sync' => ['sourceType' => 'api', 'sourceId' => 'src-9'], 'contract' => ['targetId' => 't-3'], 'answer' => [200, '[]']],
			'create-idposition-given-object' => ['config' => ['idPosition' => 'data.id'], 'writeBack' => $writeBack, 'answer' => [201, '{"data":{"id":"new-1"}}']],
			'create-null-object-reads-origin' => ['targetObject' => null, 'config' => ['idposition' => 'data.ref'], 'answer' => [201, '{"data":{"ref":"new-2"}}']],
			'create-body-id-api-source' => ['sync' => ['sourceType' => 'api', 'sourceId' => 'src-9'], 'answer' => [201, '{"id":"new-3"}']],
			'create-no-id' => ['answer' => [201, '{"name":"x"}']],
			'create-id-in-request-body' => ['config' => ['idInRequestBody' => 'titel'], 'answer' => [201, '{"id":"new-4"}']],
			'create-failed-answer' => ['writeBack' => $writeBack, 'answer' => [500, '{"id":"err-1","message":"broken"}']],
			'create-conflict-refused' => ['config' => ['conflictStatusProperty' => 'remoteState'], 'answer' => [409, '{"id":"c-1"}']],
			'create-transport-failure' => ['writeBack' => $writeBack, 'throw' => new \RuntimeException('connection refused')],
			'create-validation-record' => ['config' => ['validation' => ['messageSchema' => 'ms-1', 'mode' => 'record']], 'answer' => [201, '{"id":"new-5"}']],
			'create-validation-refuse' => ['config' => ['validation' => ['messageSchema' => 'ms-1', 'mode' => 'refuse']], 'answer' => [201, '{"id":"new-6"}']],
			'create-file-upload' => ['config' => ['fileUpload' => ['fileId' => 7, 'fieldName' => 'doc', 'includeObject' => true]], 'answer' => [201, '{"id":"new-7"}']],
			'create-traced' => ['trace' => true, 'answer' => [201, '{"id":"new-8"}']],
			'pulled-object-updates' => ['config' => ['targetIdPosition' => 'zaak.id'], 'writeBack' => $writeBack, 'answer' => [200, '{"ok":true}']],
			'update-endpoint-spaced-method-mapping' => ['contract' => ['targetId' => 'u-1'], 'config' => ['updateEndpoint' => '/put/{{ originId }}', 'updateMethod' => 'PATCH', 'updateMapping' => 'map-up'], 'writeBack' => $writeBack, 'answer' => [200, '{"v":2}']],
			'update-endpoint-tight' => ['contract' => ['targetId' => 'u-2'], 'config' => ['updateEndpoint' => '/put/{{originId}}'], 'answer' => [200, '{"v":3}']],
			'update-url-under-location' => ['contract' => ['targetId' => self::LOCATION . '/zaken/77'], 'answer' => [200, '{"v":4}']],
			'update-url-elsewhere' => ['contract' => ['targetId' => 'https://elsewhere.example.invalid/zaken/77'], 'answer' => [200, '{}']],
			'update-default-non-json' => ['contract' => ['targetId' => 'u-3'], 'endpoint' => self::LOCATION . '/items', 'answer' => [200, 'plain text']],
			'update-failed-answer' => ['contract' => ['targetId' => 'u-4'], 'writeBack' => $writeBack, 'answer' => [502, '{"message":"gateway"}']],
			'update-conflict-refused' => ['contract' => ['targetId' => 'u-5'], 'config' => ['conflictStatusProperty' => 'remoteState'], 'answer' => [412, '{}']],
			'update-file-upload-and-validation' => ['contract' => ['targetId' => 'u-6'], 'config' => ['fileUpload' => ['fileId' => 8], 'validation' => ['messageSchema' => 'ms-2']], 'answer' => [200, '{"v":5}']],
			'update-transport-failure' => ['contract' => ['targetId' => 'u-7'], 'writeBack' => $writeBack, 'throw' => new \RuntimeException('timeout')],
			'zgw-document-already-delivered' => ['contract' => ['targetId' => 'doc-1'], 'config' => ['zgwDocument' => ['zakenSource' => 'z']], 'answer' => [200, '{}']],
			'stuf-document-already-delivered' => ['config' => ['targetIdPosition' => 'zaak.id', 'stufDocument' => ['documenttype' => 'd']], 'answer' => [200, '{}']],
		];
	}//end cases()

	/**
	 * Run one case and record what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->log    = [];
		$answer       = ($case['answer'] ?? [200, '{}']);
		$this->answer = ['status' => $answer[0], 'body' => $answer[1], 'throw' => ($case['throw'] ?? null)];

		$synchronization = array_merge(
			[
				'id' => 'sync-1',
				'sourceType' => 'register/schema',
				'sourceId' => 'reg/schema',
				'targetType' => 'api',
				'targetId' => 'target-uuid',
				'targetConfig' => ($case['config'] ?? []),
			],
			($case['sync'] ?? [])
		);
		if (isset($case['writeBack']) === true) {
			$synchronization['writeBack'] = $case['writeBack'];
		}

		$contract = array_merge(['synchronizationId' => 'sync-1', 'originId' => 'origin-1', 'targetId' => null], ($case['contract'] ?? []));
		$targetObject = ['titel' => 'Besluit', 'zaak' => ['id' => 'z-1']];
		if (array_key_exists('targetObject', $case) === true) {
			$targetObject = $case['targetObject'];
		}

		$trace = null;
		if (($case['trace'] ?? false) === true) {
			$trace = new ExecutionTraceContext('golden');
		}

		$service = $this->service();
		$method  = new ReflectionMethod(SynchronizationService::class, 'writeObjectToTarget');
		$result  = [];
		try {
			$args = [$synchronization, $contract, ($case['endpoint'] ?? '/items'), &$targetObject, ($case['mutation'] ?? null), $trace];
			$result['contract'] = $method->invokeArgs($service, $args);
		} catch (\Throwable $e) {
			$result['exception'] = [get_class($e), $e->getMessage()];
		}

		$pending = new ReflectionProperty(SynchronizationService::class, 'pendingValidationFindings');
		$result['targetObject'] = $targetObject;
		$result['pendingFindings'] = $pending->getValue($service);
		$result['log'] = $this->log;

		return json_decode((string)json_encode($result), true);
	}//end runCase()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryPathMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		if (getenv('WRITE_TARGET_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $actual[$name], $name);
		}
	}//end testEveryPathMatchesTheGoldenRecord()
}//end class
