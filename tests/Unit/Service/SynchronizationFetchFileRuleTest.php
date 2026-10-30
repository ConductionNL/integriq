<?php

/**
 * Golden characterization of the fetch_file rule path of SynchronizationService.
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
 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-objects-multiple-files-shall-be-fetched-concurrently
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every branch of processFetchFileRule() and fetchFilesForObject() down to the
 * HTTP call and the file save, recorded against a golden file: each source
 * lookup, call, queued job, FileService and logger call with its arguments,
 * the returned data (or the exception) and the success/failure tally.
 *
 * Regenerate the golden file on the code it must describe with
 * FETCH_FILE_RULE_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchItem
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchRequest
 * @uses   \OCA\Integriq\Service\Synchronization\FileFetchRun
 */
class SynchronizationFetchFileRuleTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/fetch-file-rule.json';

	private const UUID = 'c8b5d4e3-2222-4333-8444-a55566667777';

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
	 * Record one collaborator call.
	 *
	 * @param string $name The call.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return void
	 */
	public function record(string $name, array $args): void {
		$this->log[] = [$name, ...$args];
	}//end record()

	/**
	 * How the case answers one named collaborator call.
	 *
	 * @param string $name The call.
	 *
	 * @return string
	 */
	public function answer(string $name): string {
		$answers = ($this->case['answers'][$name] ?? 'ok');
		if (is_array($answers) === true) {
			$answer = (array_shift($this->case['answers'][$name]) ?? 'ok');
			return (string)$answer;
		}

		return (string)$answers;
	}//end answer()

	/**
	 * The names of the files the object already has.
	 *
	 * @return list<string>
	 */
	public function attached(): array {
		return ($this->case['attached'] ?? ['keep.pdf', 'old.pdf']);
	}//end attached()

	/**
	 * The call log a source call answers with.
	 *
	 * @param string $endpoint The called endpoint.
	 * @param mixed $sink The temp file path, when streamed.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(string $endpoint, mixed $sink): ObjectEntity {
		$body = ($this->case['body'] ?? 'BYTES:' . $endpoint);
		if (is_string($sink) === true) {
			file_put_contents($sink, $body);
		}

		$callLog = new ObjectEntity();
		$callLog->setObject(
			[
				'request' => ['url' => 'https://files.invalid/' . ltrim($endpoint, '/')],
				'response' => ['statusCode' => 200, 'headers' => ($this->case['headers'] ?? ['Content-Type' => 'application/pdf']), 'body' => $body],
			]
		);

		return $callLog;
	}//end callLog()

	/**
	 * A FileService stand-in that records its calls.
	 *
	 * @return object
	 */
	private function fileService(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param SynchronizationFetchFileRuleTest $test The recording test.
			 */
			public function __construct(private SynchronizationFetchFileRuleTest $test) {
			}

			/**
			 * Records a save.
			 *
			 * @param mixed $objectEntity The object.
			 * @param string $fileName The name.
			 * @param mixed $content The content.
			 * @param bool $share Share flag.
			 * @param array|null $tags Tags.
			 *
			 * @return mixed
			 */
			public function saveFile($objectEntity, $fileName, $content, $share = false, $tags = []) {
				if (is_resource($content) === true) {
					$content = stream_get_contents($content);
				}

				$this->test->record('file.saveFile', [$objectEntity->getUuid(), $fileName, $content, $share, $tags]);
				if ($this->test->answer('file.saveFile') === 'fail') {
					throw new Exception('save broke');
				}

				return 'file-node';
			}

			/**
			 * Records an add.
			 *
			 * @param mixed $objectEntity The object id.
			 * @param string $fileName The name.
			 * @param mixed $content The content.
			 * @param bool $share Share flag.
			 * @param array|null $tags Tags.
			 * @param mixed $_schema Schema.
			 * @param mixed $_register Register.
			 * @param mixed $registerId Register id.
			 *
			 * @return mixed
			 */
			public function addFile($objectEntity, $fileName, $content, $share = false, $tags = [], $_schema = null, $_register = null, $registerId = null) {
				if (is_resource($content) === true) {
					$content = stream_get_contents($content);
				}

				$this->test->record('file.addFile', [$objectEntity, $fileName, $content, $share, $tags, $_schema, $_register, $registerId]);
				return 'file-node';
			}

			/**
			 * Records a publish.
			 *
			 * @param mixed $object The object.
			 * @param string $file The file name.
			 *
			 * @return mixed
			 */
			public function publishFile($object, $file) {
				$this->test->record('file.publishFile', [$object->getUuid(), $file]);
				return 'file-node';
			}

			/**
			 * Lists the attached files.
			 *
			 * @param mixed $object The object.
			 *
			 * @return array
			 */
			public function getFiles($object) {
				$this->test->record('file.getFiles', [$object->getUuid()]);
				$files = [];
				foreach ($this->test->attached() as $name) {
					$files[] = new class($name) {
						/**
						 * @param string $name The file name.
						 */
						public function __construct(private string $name) {
						}

						/**
						 * The file name.
						 *
						 * @return string
						 */
						public function getName(): string {
							return $this->name;
						}
					};
				}

				return $files;
			}

			/**
			 * Records a delete.
			 *
			 * @param mixed $file The file.
			 * @param mixed $object The object.
			 *
			 * @return bool
			 */
			public function deleteFile($file, $object) {
				$this->test->record('file.deleteFile', [$file->getName(), $object->getUuid()]);
				return true;
			}
		};
	}//end fileService()

	/**
	 * An OpenRegister ObjectService stand-in for the file save and cleanup lookups.
	 *
	 * @return object
	 */
	private function objectLookups(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param SynchronizationFetchFileRuleTest $test The recording test.
			 */
			public function __construct(private SynchronizationFetchFileRuleTest $test) {
			}

			/**
			 * Records a lookup.
			 *
			 * @param mixed $id The id.
			 *
			 * @return ObjectEntity
			 */
			public function find($id) {
				$this->test->record('or.find', [$id]);
				if ($this->test->answer('or.find') === 'missing') {
					throw new DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				return $entity;
			}
		};
	}//end objectLookups()

	/**
	 * The container the engine resolves its optional collaborators from.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$files = $this->fileService();
		$objects = $this->objectLookups();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			fn (string $app): bool => (($this->case['openregister'] ?? true) === true)
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument): void {
				$this->record('jobs.add', [$job, $argument]);
				if ($this->answer('jobs.add') === 'fail') {
					throw new Exception('queue broke');
				}
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($files, $objects, $appManager, $jobList) {
				$this->log[] = ['container.get', $id];
				return match ($id) {
					IAppManager::class => $appManager,
					IJobList::class => $jobList,
					'OCA\OpenRegister\Service\FileService' => $files,
					default => $objects,
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * The arguments of a CallService call keyed by parameter name, defaults filled in.
	 *
	 * @param string $method The CallService method.
	 * @param array<int, mixed> $args The positional arguments the double received.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod(CallService::class, $method))->getParameters() as $index => $parameter) {
			$default = null;
			if ($parameter->isDefaultValueAvailable() === true) {
				$default = $parameter->getDefaultValue();
			}

			$named[$parameter->getName()] = (array_key_exists($index, $args) === true ? $args[$index] : $default);
		}

		return $named;
	}//end named()

	/**
	 * A CallService double: synchronous calls answer a call log, asynchronous ones a promise.
	 *
	 * @return CallService
	 */
	private function callService(): CallService {
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (...$args) {
				['source' => $source, 'endpoint' => $endpoint, 'method' => $method, 'config' => $config, 'sink' => $sink] = $this->named('call', $args);
				$this->record('call.call', [$source->getUuid(), $endpoint, $method, $config, $sink !== null]);
				if ($this->answer('call.call') === 'fail') {
					throw new Exception('call broke');
				}

				return $this->callLog(endpoint: $endpoint, sink: $sink);
			}
		);
		$calls->method('callAsync')->willReturnCallback(
			function (...$args) {
				$rest = $this->named('callAsync', $args);
				['source' => $source, 'endpoint' => $endpoint, 'method' => $method, 'config' => $config, 'sink' => $sink] = $rest;
				$this->record('call.callAsync', [$source->getUuid(), $endpoint, $method, $config, $sink !== null]);
				$answer = $this->answer('call.callAsync');
				if ($answer === 'throw') {
					throw new Exception('dispatch broke');
				}

				if ($answer === 'reject') {
					return new RejectedPromise(new Exception('fetch broke'));
				}

				if ($answer === 'reject-string') {
					return new RejectedPromise('plain reason');
				}

				$onHeaders = ($rest['onHeaders'] ?? null);
				if ($onHeaders !== null) {
					$size = ($this->case['contentLength'] ?? '0');
					$response = new class($size) {
						/**
						 * @param string $size The declared size.
						 */
						public function __construct(private string $size) {
						}

						/**
						 * The header value.
						 *
						 * @param string $name The header.
						 *
						 * @return string
						 */
						public function getHeaderLine(string $name): string {
							return $this->size;
						}
					};
					try {
						$onHeaders($response);
					} catch (\Throwable $exception) {
						return new RejectedPromise($exception);
					}
				}

				$callLog = $this->callLog(endpoint: $endpoint, sink: $sink);
				if (($this->case['lazy'] ?? false) === true) {
					// Stays pending until the pool waits on it, so the admission gate sees it in flight.
					$promise = new Promise(
						function () use (&$promise, $callLog): void {
							$promise->resolve($callLog);
						}
					);
					return $promise;
				}

				return new FulfilledPromise($callLog);
			}
		);

		return $calls;
	}//end callService()

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$sourceConfiguration = ($this->case['sourceConfiguration'] ?? []);
		$orObjects = $this->createMock(ORObjectService::class);
		$orObjects->method('find')->willReturnCallback(
			function ($id, ...$rest) use ($sourceConfiguration) {
				$this->record('source.find', [$id, ($rest['schema'] ?? null)]);
				if ($this->answer('source.find') === 'missing') {
					throw new DoesNotExistException('no source');
				}

				$source = new ObjectEntity();
				$source->setUuid('src-1');
				$source->setObject(['uuid' => 'src-1', 'location' => 'https://files.invalid', 'configuration' => $sourceConfiguration]);
				return $source;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'critical', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level): void {
					// A TypeError names the calling line; the line is not behaviour.
					$this->log[] = ['logger.' . $level, preg_replace('/, called in \\S+ on line \\d+/', '', (string)$message)];
				}
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$this->callService(),
			$this->createMock(MappingService::class),
			$this->container(),
			$orObjects,
			$this->createMock(ObjectService::class),
			$logger,
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: rule configuration, item data, object id, source configuration and answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$base = ['source' => 'src-1', 'filePath' => '', 'sourceConfiguration' => ['headers' => ['X' => '1']]];
		$file = fn (array $extra): array => array_merge($base, $extra);

		return [
			'openregister-disabled' => ['openregister' => false, 'config' => $file(['endpoint' => '/a'])],
			'no-fetch-file-config' => ['rule' => ['id' => 5, 'configuration' => []]],
			'endpoint-null-from-path' => ['config' => $file(['filePath' => 'missing'])],
			'source-missing' => ['config' => $file(['endpoint' => '/a']), 'answers' => ['source.find' => 'missing']],
			'async-mode-queues' => ['config' => $file(['endpoint' => '/a']), 'sourceConfiguration' => ['fileFetchMode' => 'async']],
			'async-mode-object-id-null' => ['config' => $file(['endpoint' => '/a']), 'objectId' => null, 'sourceConfiguration' => ['fileFetchMode' => 'async']],
			'async-queue-fails' => ['config' => $file(['endpoint' => '/a']), 'sourceConfiguration' => ['fileFetchMode' => 'async'], 'answers' => ['jobs.add' => 'fail']],
			'single-endpoint' => ['config' => $file(['endpoint' => 'https://files.invalid/docs/a.pdf'])],
			'single-from-file-path' => ['config' => $file(['filePath' => 'doc.url']), 'data' => ['doc' => ['url' => '/docs/b.pdf']]],
			'single-no-placeholder' => ['config' => $file(['filePath' => 'doc.url', 'setPlaceholder' => false]), 'data' => ['doc' => ['url' => '/docs/b.pdf']]],
			'single-call-fails' => ['config' => $file(['endpoint' => '/a']), 'answers' => ['call.call' => 'fail']],
			'single-save-fails' => ['config' => $file(['endpoint' => '/a']), 'answers' => ['file.saveFile' => 'fail']],
			'single-object-id-null' => ['config' => $file(['endpoint' => '/a']), 'objectId' => null],
			'single-object-id-path' => ['config' => $file(['endpoint' => '/a', 'objectIdPath' => 'id']), 'objectId' => null, 'data' => ['id' => self::UUID]],
			'single-origin-id' => ['config' => $file(['endpoint' => '/a', 'originIdPath' => 'origin', 'sourceConfiguration' => ['query' => ['id' => '{{ originId }}']]]), 'data' => ['origin' => 'o-77']],
			'single-post-body' => ['config' => $file(['endpoint' => '/a', 'method' => 'POST', 'sourceConfiguration' => ['body' => ['q' => 1]]])],
			'single-json-envelope' => ['config' => $file(['endpoint' => '/a', 'contentPath' => 'data', 'filenamePath' => 'name']), 'body' => json_encode(['data' => base64_encode('PDF'), 'name' => 'env.pdf'])],
			'single-write-false' => ['config' => $file(['endpoint' => '/a', 'write' => false])],
			'single-non-string-endpoint' => ['config' => $file(['filePath' => 'n']), 'data' => ['n' => 42]],
			'assoc-file-object' => ['config' => $file(['filePath' => 'doc']), 'data' => ['doc' => ['endpoint' => '/c.pdf', 'filename' => 'c.pdf', 'label' => 'L1', 'published' => 'true', 'registerId' => 3]]],
			'assoc-context-object-id' => ['config' => $file(['filePath' => 'doc', 'objectIdPath' => 'owner']), 'data' => ['doc' => ['endpoint' => '/c.pdf', 'owner' => 'd9c6e5f4-3333-4444-8555-b66677778888']]],
			'assoc-no-endpoint' => ['config' => $file(['filePath' => 'doc']), 'data' => ['doc' => ['filename' => 'x.pdf']]],
			'assoc-sub-object-path' => ['config' => $file(['filePath' => 'doc', 'subObjectFilepath' => 'inner.url']), 'data' => ['doc' => ['inner' => ['url' => '/deep.pdf']]]],
			'assoc-published-int' => ['config' => $file(['filePath' => 'doc']), 'data' => ['doc' => ['endpoint' => '/c.pdf', 'published' => 1]]],
			'indexed-endpoints' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/keep.pdf', '/new.pdf']]],
			'indexed-query-endpoint' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/get?id=1', '/']]],
			'indexed-reject-and-throw' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf', '/c.pdf', '/d.pdf']], 'answers' => ['call.callAsync' => ['reject', 'throw', 'reject-string', 'ok']]],
			'indexed-save-fails' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf']], 'answers' => ['file.saveFile' => 'fail']],
			'indexed-object-id-null' => ['config' => $file(['filePath' => 'files']), 'objectId' => null, 'data' => ['files' => ['/a.pdf']]],
			'indexed-limits' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf', '/c.pdf']], 'sourceConfiguration' => ['maxConcurrentFetches' => 1, 'maxInFlightFetchBytes' => 10], 'contentLength' => '20'],
			'indexed-limits-clamped' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf']], 'sourceConfiguration' => ['maxConcurrentFetches' => 999, 'maxInFlightFetchBytes' => -1, 'maxFileSize' => -5]],
			'indexed-too-large' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf']], 'sourceConfiguration' => ['maxFileSize' => 5], 'contentLength' => '20'],
			'multi-file-objects' => ['config' => $file(['filePath' => 'files', 'objectIdPath' => 'owner']), 'data' => ['files' => [['endpoint' => '/x.pdf', 'filename' => 'x.pdf', 'owner' => 'e1d7f6a5-4444-4555-8666-c77788889999', 'tags' => ['t']], ['filename' => 'skip.pdf'], ['endpoint' => '/y.pdf', 'published' => 'false', 'registerId' => 'r2']]]],
			'multi-labels-as-tags' => ['config' => $file(['filePath' => 'files', 'useLabelsAsTags' => true]), 'data' => ['files' => [['endpoint' => '/x.pdf', 'label' => 'L1'], ['endpoint' => '/y.pdf', 'tags' => ['a', 'b']]]]],
			'indexed-renamed-by-header' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/get?id=1']], 'headers' => ['Content-Disposition' => 'attachment; filename="renamed.pdf"'], 'attached' => ['renamed.pdf', 'old.pdf']],
			'indexed-lazy-count-cap' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf', '/c.pdf']], 'lazy' => true, 'sourceConfiguration' => ['maxConcurrentFetches' => 1]],
			'indexed-lazy-byte-budget' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf', '/c.pdf']], 'lazy' => true, 'sourceConfiguration' => ['maxConcurrentFetches' => 5, 'maxInFlightFetchBytes' => 10], 'contentLength' => '20'],
			'indexed-lazy-under-budget' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => ['/a.pdf', '/b.pdf']], 'lazy' => true, 'sourceConfiguration' => ['maxConcurrentFetches' => 5, 'maxInFlightFetchBytes' => 100], 'contentLength' => '20'],
			'assoc-object-missing-adds' => ['config' => $file(['filePath' => 'doc', 'register' => 'r', 'schema' => 's']), 'data' => ['doc' => ['endpoint' => '/c.pdf', 'filename' => 'c.pdf', 'registerId' => 3]], 'answers' => ['or.find' => ['missing']]],
			'multi-object-missing' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => [['endpoint' => '/x.pdf'], ['endpoint' => '/y.pdf']]], 'answers' => ['or.find' => ['ok', 'ok', 'missing']]],
			'empty-with-object' => ['config' => $file(['filePath' => 'files']), 'data' => ['files' => []]],
			'empty-without-object' => ['config' => $file(['filePath' => 'files']), 'objectId' => null, 'data' => ['files' => []]],
			'job-entry-single' => ['entry' => 'job', 'config' => $file([]), 'endpoint' => '/job.pdf', 'ruleId' => 9],
			'job-entry-indexed' => ['entry' => 'job', 'config' => $file([]), 'endpoint' => ['/j1.pdf', '/j2.pdf']],
			'job-entry-source-missing' => ['entry' => 'job', 'config' => $file([]), 'endpoint' => '/j.pdf', 'answers' => ['source.find' => 'missing']],
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
		$this->log = [];
		$service = $this->service();

		$objectId = (array_key_exists('objectId', $case) === true ? $case['objectId'] : self::UUID);
		try {
			if (($case['entry'] ?? 'rule') === 'job') {
				$service->fetchFilesForObject(
					config: $case['config'],
					endpoint: $case['endpoint'],
					objectId: (string)$objectId,
					ruleId: ($case['ruleId'] ?? 0)
				);
				$outcome = ['return' => null];
			} else {
				$rule = ($case['rule'] ?? ['id' => 5, 'configuration' => ['fetch_file' => $case['config']]]);
				$method = new ReflectionMethod(SynchronizationService::class, 'processFetchFileRule');
				$outcome = ['return' => $method->invoke($service, $rule, ($case['data'] ?? ['id' => 'item']), $objectId)];
			}
		} catch (\TypeError $error) {
			$outcome = ['throws' => \TypeError::class];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		$tally = (new ReflectionMethod(SynchronizationService::class, 'takeFileFetchTally'))->invoke($service);

		return ['outcome' => $outcome, 'tally' => $tally, 'log' => $this->log];
	}//end runCase()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach (array_keys($this->cases()) as $name) {
			$actual[$name] = $this->runCase($this->cases()[$name]);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
		if (getenv('FETCH_FILE_RULE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
