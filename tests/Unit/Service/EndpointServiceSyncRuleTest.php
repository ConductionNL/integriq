<?php

/**
 * Golden characterization of EndpointService::processSyncRule().
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
 * @spec openspec/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every branch of processSyncRule(), recorded against a golden file: each
 * synchronization, OpenRegister and logger call with its arguments, and the
 * data the rule returns.
 *
 * Regenerate the golden file on the code it must describe with
 * SYNC_RULE_GOLDEN=1.
 */
class EndpointServiceSyncRuleTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/endpoint/process-sync-rule.json';

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
	 * @return EndpointService
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function ($message) {
				$this->log[] = ['logger.debug', $message];
			}
		);

		$sync = $this->createMock(SynchronizationService::class);
		$sync->method('getSynchronization')->willReturnCallback(
			function (?int $id = null, ?array $filters = []) {
				$this->log[] = ['sync.getSynchronization', $id, $filters];
				$entity = ObjectServiceMockBuilder::objectEntity($this, ['sourceConfig' => ($this->case['sourceConfig'] ?? [])], 'sync-uuid');
				$entity->setName('Sync name');
				return $entity;
			}
		);
		$sync->method('synchronize')->willReturnCallback(
			function ($synchronization, ?bool $isTest = false, ?bool $force = false, &$object = null, ?string $mutationType = null, ?string $source = null, ?array $data = null, ?FlowToken &$flowToken = null, ?bool $forceDeletion = false, ?string $approvalRequestId = null, ?ExecutionTraceContext $trace = null) {
				$described = $object;
				if ($object instanceof ObjectEntity) {
					$described = ['entity' => $object->getUuid(), 'object' => $object->getObject()];
				}

				$this->log[] = ['sync.synchronize', $synchronization->getUuid(), $isTest, $force, $described, $mutationType, $data, $flowToken !== null, $trace !== null];
				return ['result' => ['contracts' => ['c-1']], 'returned' => 'log'];
			}
		);

		$openRegisters = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->addMethods(['clearCurrents'])
			->getMock();
		$openRegisters->method('clearCurrents')->willReturnCallback(
			function () {
				$this->log[] = ['openRegisters.clearCurrents'];
			}
		);
		$openRegisters->method('find')->willReturnCallback(
			function ($id) {
				$this->log[] = ['openRegisters.find', $id];
				return ObjectServiceMockBuilder::objectEntity($this, ['id' => $id, 'title' => 'fetched'], 'fetched-' . $id);
			}
		);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturn($openRegisters);

		$orObjectService = ObjectServiceMockBuilder::make($this);

		return new EndpointService(
			$objectService,
			$this->createMock(CallService::class),
			$logger,
			$this->createMock(IURLGenerator::class),
			$this->createMock(MappingService::class),
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$sync,
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->createMock(ConsumerScopeService::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(FileService::class),
		);
	}//end service()

	/**
	 * The cases: rule configuration, request data and dry-run flag.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$body = ['body' => ['naam' => 'x', 'zaak' => ['id' => 'https://x.invalid/zaken/z-1']], 'headers' => ['h' => 1]];

		return [
			'no-synchronization' => ['config' => ['isTest' => true], 'data' => $body],
			'reference-id' => ['config' => ['synchronization' => 'sync-ref'], 'data' => $body],
			'numeric-id' => ['config' => ['synchronization' => '42'], 'data' => $body],
			'nested-reference' => ['config' => ['synchronization' => ['synchronization' => 'nested-ref']], 'data' => $body],
			'dry-run-wins' => ['config' => ['synchronization' => 's', 'isTest' => false], 'data' => ['body' => ['isTest' => false]], 'dryRun' => true],
			'body-is-test-and-force' => ['config' => ['synchronization' => 's', 'isTest' => true, 'force' => false], 'data' => ['body' => ['isTest' => 'body-test', 'force' => 'body-force']]],
			'config-is-test-and-force' => ['config' => ['synchronization' => 's', 'isTest' => 'cfg-test', 'force' => 'cfg-force'], 'data' => ['body' => ['naam' => 'x']]],
			'string-body' => ['config' => ['synchronization' => 's', 'isTest' => 'cfg-test'], 'data' => ['body' => 'raw text']],
			'no-body' => ['config' => ['synchronization' => 's'], 'data' => ['headers' => []]],
			'object-id-path-url' => ['config' => ['synchronization' => ['synchronization' => 's', 'objectIdPath' => 'zaak.id', 'preDelay' => 0, 'postDelay' => 0]], 'data' => $body],
			'object-id-path-plain' => ['config' => ['synchronization' => ['synchronization' => 's', 'objectIdPath' => 'naam', 'preDelay' => '1', 'postDelay' => '1']], 'data' => $body],
			'delete-type' => ['config' => ['synchronization' => 's'], 'sourceConfig' => ['synchronizationType' => 'delete'], 'data' => $body],
			'other-type' => ['config' => ['synchronization' => 's'], 'sourceConfig' => ['synchronizationType' => 'create'], 'data' => $body],
			'retain-response' => ['config' => ['synchronization' => ['synchronization' => 's', 'retainResponse' => 1], 'synchronizationConfig' => ['mergeResultToKey' => '#']], 'data' => $body],
			'retain-response-false' => ['config' => ['synchronization' => ['synchronization' => 's', 'retainResponse' => 0]], 'data' => $body],
			'retain-response-only' => ['config' => ['synchronization' => ['synchronization' => 's', 'retainResponse' => 'yes']], 'data' => $body],
			'merge-root-list' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['mergeResultToKey' => '#']], 'data' => ['body' => ['a', 'b']]],
			'merge-root' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['mergeResultToKey' => '#']], 'data' => $body],
			'merge-key' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['mergeResultToKey' => 'result']], 'data' => $body],
			'overwrite-yes' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['overwriteObjectWithResult' => 'yes']], 'data' => $body],
			'overwrite-no' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['overwriteObjectWithResult' => 'no']], 'data' => $body],
			'overwrite-garbage' => ['config' => ['synchronization' => 's', 'synchronizationConfig' => ['overwriteObjectWithResult' => 'maybe']], 'data' => $body],
			'overwrite-retained' => ['config' => ['synchronization' => ['synchronization' => 's', 'retainResponse' => true], 'synchronizationConfig' => ['overwriteObjectWithResult' => true]], 'data' => $body],
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
		$this->case = $case;
		$service = $this->service();
		$this->log = [];

		$rule = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => $case['config']], 'rule-1');
		$method = new ReflectionMethod(EndpointService::class, 'processSyncRule');
		try {
			$outcome = ['return' => $method->invoke($service, $rule, $case['data'], new FlowToken(), new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'e-1'), ($case['dryRun'] ?? false))];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => preg_replace(['/, called in .*$/', '/MockObject_\\w+/'], ['', 'Mock'], $exception->getMessage())];
		}

		return ['outcome' => $outcome, 'log' => $this->log];
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
		if (getenv('SYNC_RULE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
