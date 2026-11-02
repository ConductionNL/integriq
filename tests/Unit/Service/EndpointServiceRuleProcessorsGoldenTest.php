<?php

/**
 * Golden characterization of the EndpointService rule processors that read the pipeline run.
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
 * @spec openspec/specs/rule-pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRuleInterface;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointRuleDispatch;
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
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Every branch of the override, locking, avg_bsn_policy, selfurl_hal,
 * webhook_signature, audit_trail, download, approval, write_file,
 * fileparts_create and filepart_upload rule processors, driven through
 * dispatchEndpointRule() and recorded against a golden file: each
 * collaborator call with its arguments, and what the rule returns or throws.
 *
 * Regenerate the golden file on the code it must describe with
 * RULE_PROCESSORS_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 * @uses   \OCA\Integriq\Service\EndpointRuleDispatch
 */
class EndpointServiceRuleProcessorsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/endpoint/rule-processors.json';

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
	 * The trace of the current case, to tell it apart from any other.
	 *
	 * @var ExecutionTraceContext|null
	 */
	private ?ExecutionTraceContext $trace = null;

	/**
	 * Describe a value for the log: entities, flow tokens and the trace by identity, the rest as is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function d(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['entity' => $value->getUuid(), 'object' => $value->getObject()];
		}

		if ($value instanceof FlowToken) {
			return ['flowToken' => $value->getRequestOriginal()];
		}

		if ($value instanceof ExecutionTraceContext) {
			return ($value === $this->trace ? 'case-trace' : 'other-trace');
		}

		if (is_object($value) === true) {
			return ['object' => ($value instanceof \PHPUnit\Framework\MockObject\MockObject ? get_parent_class($value) : get_class($value))];
		}

		if (is_string($value) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1) {
			return 'a fresh uuid';
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->d($item), $value);
		}

		return $value;
	}//end d()

	/**
	 * A mock whose methods record their arguments: real methods by onlyMethods, missing ones by addMethods.
	 *
	 * @param string                 $class   The class to mock.
	 * @param array<string, Closure> $answers The answer of each method, given the arguments; this class name answers with the mock itself.
	 * @param string                 $label   The log label.
	 *
	 * @return object
	 */
	private function recorder(string $class, array $answers, string $label): object {
		$mock = null;
		if (interface_exists($class) === true) {
			$mock = $this->createMock($class);
		}

		if ($mock === null) {
			$existing = array_values(array_filter(array_keys($answers), fn ($name) => method_exists($class, $name)));
			$missing = array_values(array_diff(array_keys($answers), $existing));
			$builder = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($existing);
			if ($missing !== []) {
				$builder->addMethods($missing);
			}

			$mock = $builder->getMock();
		}

		foreach ($answers as $name => $answer) {
			$mock->method($name)->willReturnCallback(
				function (...$args) use ($name, $answer, $label, &$mock) {
					$this->log[] = [$label . '.' . $name, $this->d($args)];
					$result = $answer(...$args);
					return ($result === self::class ? $mock : $result);
				}
			);
		}

		return $mock;
	}//end recorder()

	/**
	 * A file double.
	 *
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/objects/' . $name);
		$file->method('getContent')->willReturn('content of ' . $name);
		$file->method('getMimeType')->willReturn('text/plain');
		return $file;
	}//end file()

	/**
	 * The object-entity mapper OpenRegister hands out.
	 *
	 * @return object
	 */
	private function entityMapper(): object {
		$test = $this;
		return new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param EndpointServiceRuleProcessorsGoldenTest $test The test that records the calls.
			 */
			public function __construct(private EndpointServiceRuleProcessorsGoldenTest $test) {
			}

			/**
			 * Record a find and answer with the stored object.
			 *
			 * @param mixed $identifier The id.
			 *
			 * @return ObjectEntity
			 */
			public function find($identifier) {
				$this->test->record(['mapper.find', $identifier]);
				return $this->test->storedObject();
			}

			/**
			 * Record an update.
			 *
			 * @param ObjectEntity $object The object.
			 *
			 * @return ObjectEntity
			 */
			public function update($object) {
				$this->test->record(['mapper.update', $object->getUuid(), $object->getObject()]);
				return $object;
			}
		};
	}//end entityMapper()

	/**
	 * Record a collaborator call.
	 *
	 * @param array<int, mixed> $entry The call.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()

	/**
	 * The object the case stores in OpenRegister.
	 *
	 * @return ObjectEntity
	 */
	public function storedObject(): ObjectEntity {
		$object = ObjectServiceMockBuilder::objectEntity($this, ($this->case['stored'] ?? ['title' => 'stored']), 'stored-1');
		$object->setRegister(3);
		$object->setSchema(7);
		$object->setFiles(($this->case['storedFiles'] ?? []));
		return $object;
	}//end storedObject()

	/**
	 * The OpenRegister object service double.
	 *
	 * @return ORObjectService
	 */
	private function openRegisters(): object {
		return $this->recorder(
			ORObjectService::class,
			[
				'clearCurrents' => fn () => null,
				'getMapper' => fn () => $this->entityMapper(),
				'saveObject' => function (...$args) {
					if (($this->case['saveFails'] ?? false) === true) {
						throw new ValidationException('invalid part');
					}

					$saved = ($args[0] instanceof ObjectEntity ? $args[0]->getObject() : $args[0]);
					$entity = ObjectServiceMockBuilder::objectEntity($this, ['saved' => $saved], 'saved-1');
					$entity->setSchema(9);
					return $entity;
				},
				'lockObject' => fn (string $identifier, ?string $process = null, ?int $duration = null) => ['locked' => $identifier, 'processIsUuid' => (strlen((string)$process) === 36), 'duration' => $duration],
				'unlockObject' => fn () => true,
				'getLogs' => fn (string $uuid, array $filters = []) => ($this->case['logs'] ?? [['log' => $uuid, 'filters' => $filters]]),
				'setRegister' => fn () => self::class,
				'setSchema' => fn () => self::class,
				'find' => fn () => $this->storedObject(),
				'handleValidationException' => fn (\Exception $exception) => new JSONResponse(['invalid' => $exception->getMessage()], 400),
			],
			'or'
		);
	}//end openRegisters()

	/**
	 * The engine over recording collaborators.
	 *
	 * @return EndpointService
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);

		$openRegisters = $this->openRegisters();
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturn($openRegisters);

		$urlGenerator = $this->recorder(
			IURLGenerator::class,
			[
				'getBaseUrl' => fn () => 'https://example.org/',
				'linkToRouteAbsolute' => fn (string $route, array $args = []) => 'https://example.org/' . $route . '/' . ($args['id'] ?? ''),
			],
			'url'
		);

		$mappingService = $this->recorder(
			MappingService::class,
			[
				'getMapping' => fn () => $this->createMock(OrMapping::class),
				'executeMapping' => fn ($mapping, array $input) => array_merge($input, ['mapped' => (($input['mapped'] ?? 0) + 1)]),
			],
			'mapping'
		);

		$storage = $this->recorder(
			StorageService::class,
			[
				'createUpload' => fn () => ($this->case['parts'] ?? [['id' => 'part-1', 'order' => 1]]),
				'writePart' => fn () => true,
			],
			'storage'
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/objects/stored-1');
		$fileService = $this->recorder(
			FileService::class,
			[
				'addFile' => function ($objectEntity, string $fileName = '', $content = null) {
					if ($content === 'fail') {
						throw new RuntimeException('write failed');
					}

					return $this->file((string)$fileName);
				},
				'getObjectFolder' => fn () => $folder,
				'getFiles' => fn () => array_map(fn ($name) => $this->file($name), ($this->case['orFiles'] ?? [])),
				'getFile' => fn ($object = null, $file = '') => $this->file((string)$file),
			],
			'files'
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($openRegisters, $fileService) {
				$this->log[] = ['container.get', $id];
				return ($id === 'OCA\OpenRegister\Service\FileService' ? $fileService : $openRegisters);
			}
		);

		$signature = $this->recorder(
			WebhookSignatureService::class,
			['verify' => fn () => ($this->case['verified'] ?? true)],
			'signature'
		);

		$avgBsn = $this->recorder(
			AvgBsnPolicyRuleInterface::class,
			['apply' => fn ($rule, array $data, string $timing = 'before') => ($data + ['avg' => $timing])],
			'avgBsn'
		);

		$approval = $this->recorder(
			ApprovalService::class,
			['suspend' => fn () => ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-1')],
			'approval'
		);

		$schemaMapper = $this->recorder(
			SchemaMapper::class,
			['find' => fn () => throw new RuntimeException('schema lookup')],
			'schemaMapper'
		);

		return new EndpointService(
			$objectService,
			$this->createMock(CallService::class),
			$logger,
			$urlGenerator,
			$mappingService,
			ObjectServiceMockBuilder::make($this),
			$this->createMock(IConfig::class),
			$storage,
			$this->createMock(OpenRegisterCredentialBridge::class),
			$container,
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			$signature,
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule(ObjectServiceMockBuilder::make($this), $logger),
			new ReferenceNumberRule(),
			$avgBsn,
			$approval,
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->createMock(ConsumerScopeService::class),
			$schemaMapper,
			$fileService,
		);
	}//end service()

	/**
	 * The cases: the rule, the run and the data handed to the rule.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$body = ['body' => ['naam' => 'x'], 'parameters' => [], 'path' => 'zaken/o-1'];
		$audit = ['endpoint' => ['endpointArray' => ['zaken', '{{id}}', 'audittrail', '{{audittrail-id}}']]];
		$parts = ['type' => 'fileparts_create', 'configuration' => ['fileparts_create' => ['sizeLocation' => 'size', 'schemaId' => '9', 'filenameLocation' => 'name']]];
		$upload = ['type' => 'filepart_upload', 'configuration' => ['filepart_upload' => []]];
		$write = ['type' => 'write_file', 'configuration' => ['write_file' => ['filePath' => 'body.file', 'fileNamePath' => 'body.name', 'tags' => ['bijlage']]]];

		return [
			'override' => ['rule' => ['type' => 'override'], 'data' => $body],
			'override-no-object-id' => ['rule' => ['type' => 'override'], 'data' => $body, 'objectId' => null],
			'locking-lock' => ['rule' => ['type' => 'locking', 'configuration' => ['locking' => ['action' => 'lock', 'duration' => 60]]], 'data' => $body],
			'locking-lock-default-duration' => ['rule' => ['type' => 'locking', 'configuration' => ['locking' => ['action' => 'lock']]], 'data' => $body],
			'locking-unlock' => ['rule' => ['type' => 'locking', 'configuration' => ['locking' => ['action' => 'unlock']]], 'data' => $body],
			'locking-unknown-action' => ['rule' => ['type' => 'locking', 'configuration' => ['locking' => ['action' => 'peek']]], 'data' => $body],
			'locking-no-object-id' => ['rule' => ['type' => 'locking', 'configuration' => ['locking' => ['action' => 'lock']]], 'data' => $body, 'objectId' => null],
			'avg-before' => ['rule' => ['type' => 'avg_bsn_policy'], 'data' => $body],
			'avg-after' => ['rule' => ['type' => 'avg_bsn_policy'], 'data' => $body, 'timing' => 'after'],
			'selfurl' => ['rule' => ['type' => 'selfurl_hal'], 'data' => ['body' => ['id' => 'o-1', 'zaak' => ['uuid' => 'z-1']]], 'endpoint' => ['endpoint' => '/zaken/']],
			'selfurl-no-array-body' => ['rule' => ['type' => 'selfurl_hal'], 'data' => ['body' => 'text'], 'endpoint' => ['endpoint' => '/zaken/']],
			'webhook-pass' => ['rule' => ['type' => 'webhook_signature', 'configuration' => ['webhook_signature' => ['scheme' => 'github', 'secret' => 's', 'header' => 'X-Sig', 'toleranceSeconds' => '30']]], 'data' => $body],
			'webhook-root-config-fail' => ['rule' => ['type' => 'webhook_signature', 'configuration' => ['secret' => 's2']], 'data' => $body, 'verified' => false],
			'audit-one' => ['rule' => ['type' => 'audit_trail'], 'data' => ['path' => 'zaken/o-1/audittrail/a-1'], 'logs' => [['uuid' => 'a-1']]] + $audit,
			'audit-one-missing' => ['rule' => ['type' => 'audit_trail'], 'data' => ['path' => 'zaken/o-1/audittrail/a-2'], 'logs' => []] + $audit,
			'audit-all' => ['rule' => ['type' => 'audit_trail'], 'data' => ['path' => 'zaken/o-1']] + $audit,
			'audit-no-object-id' => ['rule' => ['type' => 'audit_trail'], 'data' => ['path' => 'zaken/o-1'], 'objectId' => null] + $audit,
			'download-filename-parameter' => ['rule' => ['type' => 'download'], 'data' => ['parameters' => ['filename' => 'a.txt']]],
			'download-filename-position' => ['rule' => ['type' => 'download', 'configuration' => ['filenamePosition' => 'title']], 'data' => ['parameters' => ['filename' => 'a.txt', 'version' => 2]], 'stored' => ['title' => 'b.txt']],
			'download-object-file' => ['rule' => ['type' => 'download'], 'data' => ['parameters' => ['versie' => 1]], 'storedFiles' => [['title' => 'c.txt']]],
			'download-service-file' => ['rule' => ['type' => 'download'], 'data' => ['parameters' => []], 'orFiles' => ['d.txt']],
			'download-undetermined' => ['rule' => ['type' => 'download'], 'data' => ['parameters' => []], 'orFiles' => ['d.txt', 'e.txt']],
			'download-no-object-id' => ['rule' => ['type' => 'download'], 'data' => ['parameters' => []], 'objectId' => null],
			'approval-before' => ['rule' => ['type' => 'approval'], 'data' => $body],
			'approval-before-untraced' => ['rule' => ['type' => 'approval'], 'data' => $body, 'traced' => false],
			'approval-after' => ['rule' => ['type' => 'approval'], 'data' => $body, 'timing' => 'after'],
			'approval-no-flow-token' => ['rule' => ['type' => 'approval'], 'data' => $body, 'flowToken' => false],
			'write-file-no-config' => ['rule' => ['type' => 'write_file'], 'data' => $body],
			'write-file-nothing' => ['rule' => $write, 'data' => $body],
			'write-file-single' => ['rule' => $write, 'data' => ['body' => ['file' => base64_encode('hello'), 'name' => 'dir/h.txt']]],
			'write-file-single-from-flow-token' => ['rule' => $write, 'data' => ['body' => []], 'parameters' => ['file' => base64_encode('hi'), 'name' => 'f.txt']],
			'write-file-single-fails' => ['rule' => $write, 'data' => ['body' => ['file' => base64_encode('fail'), 'name' => 'h.txt']]],
			'write-file-list' => ['rule' => $write, 'data' => ['body' => ['file' => [['content' => base64_encode('one'), 'label' => 'bijlage', 'filename' => 'x/one.txt'], ['content' => base64_encode('two'), 'label' => 'other', 'filename' => 'two.txt'], ['content' => base64_encode('fail'), 'filename' => 'f.txt']]]]],
			'write-file-list-unnamed' => ['rule' => $write, 'data' => ['body' => ['file' => [['content' => base64_encode('one')]]]]],
			'write-file-no-object-id' => ['rule' => $write, 'data' => $body, 'objectId' => null],
			'write-file-no-flow-token' => ['rule' => $write, 'data' => $body, 'flowToken' => false],
			'fileparts-no-object-id' => ['rule' => $parts, 'data' => ['size' => 10, 'name' => 'big.bin'], 'objectId' => null],
			'fileparts-no-config' => ['rule' => ['type' => 'fileparts_create'], 'data' => ['size' => 10, 'name' => 'big.bin']],
			'fileparts-saved' => ['rule' => $parts, 'data' => ['size' => 10, 'name' => 'big.bin'], 'endpoint' => ['targetId' => '3/7']],
			'fileparts-mapped-invalid' => ['rule' => ['configuration' => ['fileparts_create' => ($parts['configuration']['fileparts_create'] + ['mappingId' => 'm-1', 'filePartLocation' => 'delen'])]] + $parts, 'data' => ['size' => 10, 'name' => 'big.bin'], 'endpoint' => ['targetId' => '3/7'], 'saveFails' => true],
			'filepart-upload-no-config' => ['rule' => ['type' => 'filepart_upload'], 'data' => ['order' => 1, 'id' => 'p-1', 'data' => 'abc']],
			'filepart-upload' => ['rule' => $upload, 'data' => ['order' => 1, 'id' => 'p-1', 'data' => 'abc']],
			'filepart-upload-mapped' => ['rule' => ['type' => 'filepart_upload', 'configuration' => ['filepart_upload' => ['mappingId' => 'm-in', 'mappingOutId' => 'm-out']]], 'data' => ['order' => 2, 'id' => 'p-2', 'data' => 'def']],
			'filepart-upload-no-object-id' => ['rule' => $upload, 'data' => ['order' => 1, 'id' => 'p-1', 'data' => 'abc'], 'objectId' => null],
		];
	}//end cases()

	/**
	 * Describe what a rule answered.
	 *
	 * @param mixed $result The rule result.
	 *
	 * @return mixed
	 */
	private function describe(mixed $result): mixed {
		if ($result instanceof DataDownloadResponse) {
			$headers = new ReflectionProperty(Response::class, 'headers');
			return ['class' => get_class($result), 'status' => $result->getStatus(), 'content' => $result->render(), 'headers' => $headers->getValue($result)];
		}

		if ($result instanceof JSONResponse) {
			return ['class' => get_class($result), 'status' => $result->getStatus(), 'data' => $result->getData()];
		}

		return $this->d($result);
	}//end describe()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->case = $case;
		$this->trace = new ExecutionTraceContext('endpoint');
		$service = $this->service();
		$this->log = [];

		$request = $this->recorder(IRequest::class, ['getHeader' => fn (string $name) => 'sig-of-' . $name], 'request');

		$context = new EndpointRuleDispatch();
		$context->endpoint = ObjectServiceMockBuilder::objectEntity($this, ($case['endpoint'] ?? ['name' => 'Zaken']), 'endpoint-1');
		$context->endpointData = $context->endpoint->getObject();
		$context->request = $request;
		$context->timing = ($case['timing'] ?? 'before');
		$context->objectId = (array_key_exists('objectId', $case) === true ? $case['objectId'] : 'o-1');
		if (($case['flowToken'] ?? true) === true) {
			$context->flowToken = new FlowToken(requestOriginal: ['method' => 'POST', 'parameters' => ($case['parameters'] ?? ['p' => 1]), 'path' => '/zaken']);
		}

		if (($case['traced'] ?? true) === true) {
			$context->trace = $this->trace;
		}

		$context->rule = ObjectServiceMockBuilder::objectEntity($this, $case['rule'], 'rule-1');

		$method = new ReflectionMethod(EndpointService::class, 'dispatchEndpointRule');
		try {
			$outcome = $this->describe($method->invoke($service, $context, $case['data']));
		} catch (\TypeError $error) {
			$outcome = ['throws' => \TypeError::class];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
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
		if (getenv('RULE_PROCESSORS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
