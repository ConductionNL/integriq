<?php

/**
 * Golden characterization of SynchronizationService::saveFetchedFile().
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
 * @spec openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
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
 * Every branch of saveFetchedFile(), recorded against a golden file: each
 * FileService, ObjectService and logger call with its arguments, the returned
 * string (or the exception) and the filename written back by reference.
 *
 * Regenerate the golden file on the code it must describe with
 * SAVE_FETCHED_GOLDEN=1.
 */
class SynchronizationSaveFetchedFileTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/save-fetched-file.json';

	private const UUID = 'b7a4c3d2-1111-4222-8333-944455556666';

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
	private array $behaviour = [];

	/**
	 * Temp files made for streamed cases.
	 *
	 * @var list<string>
	 */
	private array $tempFiles = [];

	/**
	 * Remove the temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A content argument as the log records it: a stream is read from where it stands.
	 *
	 * @param mixed $content The content handed to FileService.
	 *
	 * @return array<string, mixed>|string|null
	 */
	private function describeContent(mixed $content): array|string|null {
		if (is_resource($content) === true) {
			$position = ftell($content);
			return ['stream' => (string)stream_get_contents($content), 'at' => $position];
		}

		if (is_array($content) === true) {
			return ['array' => json_encode($content)];
		}

		return $content;
	}//end describeContent()

	/**
	 * A FileService stand-in that records its calls.
	 *
	 * @return object
	 */
	private function fileService(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param SynchronizationSaveFetchedFileTest $test The recording test.
			 */
			public function __construct(private SynchronizationSaveFetchedFileTest $test) {
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
				return $this->test->record('file.saveFile', [$objectEntity->getUuid(), $fileName, $content, $share, $tags]);
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
				return $this->test->record('file.addFile', [$objectEntity, $fileName, $content, $share, $tags, $_schema, $_register, $registerId]);
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
				return $this->test->record('file.publishFile', [$object->getUuid(), $file]);
			}
		};
	}//end fileService()

	/**
	 * Record one call and answer it as the case says.
	 *
	 * @param string $name The call.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	public function record(string $name, array $args): mixed {
		foreach ($args as $index => $arg) {
			if ($index === 2 && str_starts_with($name, 'file.') === true) {
				$args[$index] = $this->describeContent($arg);
			}
		}

		$this->log[] = [$name, ...$args];

		$answer = ($this->behaviour[$name] ?? 'ok');
		if (is_array($answer) === true) {
			$answer = (array_shift($this->behaviour[$name]) ?? 'ok');
		}

		if ($answer === 'missing') {
			throw new DoesNotExistException('not found');
		}

		if ($answer === 'fail') {
			throw new Exception($name . ' broke');
		}

		if ($answer === 'null') {
			return null;
		}

		if ($name === 'or.find') {
			$entity = new ObjectEntity();
			$entity->setUuid((string)$args[0]);
			return $entity;
		}

		return 'file-node';
	}//end record()

	/**
	 * The engine over recording collaborators.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$test = $this;
		$objects = new class($test) {
			/**
			 * @param SynchronizationSaveFetchedFileTest $test The recording test.
			 */
			public function __construct(private SynchronizationSaveFetchedFileTest $test) {
			}

			/**
			 * Records a lookup.
			 *
			 * @param mixed $id The id.
			 *
			 * @return mixed
			 */
			public function find($id) {
				return $this->test->record('or.find', [$id]);
			}
		};
		$files = $this->fileService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objects, $files) {
				$this->log[] = ['container.get', $id];
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $files;
				}

				return $objects;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message) {
				$this->log[] = ['logger.warning', $message];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		return new SynchronizationService(
			$this->createMock(CallService::class),
			$this->createMock(MappingService::class),
			$container,
			$this->createMock(ORObjectService::class),
			$this->createMock(ObjectService::class),
			$logger,
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * The cases: prepared descriptor, response, arguments and collaborator answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$json = json_encode(['doc' => ['data' => base64_encode('PDF-BYTES'), 'name' => 'from-body.pdf']]);
		$disposition = ['Content-Disposition' => ['attachment; filename="header.pdf"']];

		return [
			'plain-body-given-filename' => ['body' => 'plain text body', 'filename' => 'a.txt'],
			'json-content-and-filename-paths' => ['body' => $json, 'config' => ['contentPath' => 'doc.data', 'filenamePath' => 'doc.name'], 'filename' => 'given.txt'],
			'json-empty-paths-ignored' => ['body' => '"quoted"', 'config' => ['contentPath' => '', 'filenamePath' => ''], 'filename' => 'q.txt'],
			'json-with-disposition-stays-string' => ['body' => '{"a":1}', 'headers' => $disposition],
			'lowercase-disposition' => ['body' => '{"a":1}', 'headers' => ['content-disposition' => 'attachment; filename=low.bin']],
			'base64-body' => ['body' => base64_encode('BINARY'), 'filename' => 'b.bin'],
			'file-extension' => ['body' => 'x y', 'filename' => 'stem', 'config' => ['fileExtension' => '.odt']],
			'file-extension-empty' => ['body' => 'x y', 'filename' => 'stem', 'config' => ['fileExtension' => '']],
			'file-extension-null-filename' => ['body' => 'x y', 'config' => ['fileExtension' => '.odt']],
			'write-false-body' => ['body' => base64_encode('RAW'), 'config' => ['write' => false]],
			'write-false-plain' => ['body' => 'not base64!', 'config' => ['write' => false]],
			'write-true-explicit' => ['body' => 'not base64!', 'filename' => 'w.txt', 'config' => ['write' => true]],
			'write-false-sink' => ['sink' => 'STREAMED BYTES', 'config' => ['write' => false]],
			'filename-from-url-and-type' => ['body' => 'a b', 'request' => 'https://x.invalid/files/report', 'headers' => ['Content-Type' => 'application/pdf; charset=binary']],
			'filename-from-url-with-ext' => ['body' => 'a b', 'request' => 'https://x.invalid/files/report.docx', 'headers' => ['Content-Type' => 'application/pdf']],
			'filename-no-url-no-type' => ['body' => 'a b'],
			'invalid-object-id' => ['body' => 'a b', 'filename' => 'f.txt', 'objectId' => 'not-a-uuid'],
			'empty-object-id' => ['body' => 'a b', 'filename' => 'f.txt', 'objectId' => ''],
			'uppercase-uuid' => ['body' => 'a b', 'filename' => 'f.txt', 'objectId' => strtoupper(self::UUID)],
			'no-response' => ['response' => null, 'filename' => 'f.txt'],
			'no-response-sink' => ['response' => null, 'sink' => 'S', 'filename' => 'f.txt'],
			'sink-unreadable' => ['sinkMissing' => true, 'filename' => 'f.txt'],
			'tags-autoshare' => ['body' => 'a b', 'filename' => 'f.txt', 'tags' => ['t1'], 'config' => ['autoShare' => true]],
			'tags-autoshare-false' => ['body' => 'a b', 'filename' => 'f.txt', 'tags' => ['t1'], 'config' => ['autoShare' => false]],
			'no-tags-autoshare' => ['body' => 'a b', 'filename' => 'f.txt', 'tags' => [], 'config' => ['autoShare' => true]],
			'null-tags' => ['body' => 'a b', 'filename' => 'f.txt', 'tags' => null],
			'published-true' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true'],
			'published-one' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => '1'],
			'published-false' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'false'],
			'published-date' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => '2026-01-02'],
			'published-atom' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => '2026-01-02T03:04:05+00:00'],
			'published-garbage' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'soon'],
			'published-null-file' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'answers' => ['file.saveFile' => 'null']],
			'publish-fails' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'answers' => ['file.publishFile' => 'fail']],
			'save-fails' => ['body' => 'a b', 'filename' => 'f.txt', 'answers' => ['file.saveFile' => 'fail']],
			'find-fails' => ['body' => 'a b', 'filename' => 'f.txt', 'answers' => ['or.find' => 'fail']],
			'object-missing-add' => ['body' => base64_encode('ADD'), 'filename' => 'f.txt', 'registerId' => 7, 'config' => ['register' => 'r1', 'schema' => 's1', 'autoShare' => true], 'tags' => ['x'], 'answers' => ['or.find' => ['missing']]],
			'object-missing-add-defaults' => ['body' => 'a b', 'filename' => 'f.txt', 'tags' => [], 'answers' => ['or.find' => ['missing']]],
			'object-missing-add-publish' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'registerId' => 'r-9', 'answers' => ['or.find' => ['missing']]],
			'object-missing-add-publish-missing' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'answers' => ['or.find' => ['missing', 'missing']]],
			'object-missing-add-null-file' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'answers' => ['or.find' => ['missing'], 'file.addFile' => 'null']],
			'save-says-missing-add-publish' => ['body' => 'a b', 'filename' => 'f.txt', 'published' => 'true', 'answers' => ['file.saveFile' => 'missing']],
			'object-missing-add-fails' => ['body' => 'a b', 'filename' => 'f.txt', 'answers' => ['or.find' => ['missing'], 'file.addFile' => 'fail']],
			'sink-save' => ['sink' => 'STREAM-SAVE', 'filename' => 's.bin', 'tags' => ['t'], 'config' => ['autoShare' => true]],
			'sink-add' => ['sink' => 'STREAM-ADD', 'filename' => 's.bin', 'answers' => ['or.find' => ['missing']]],
			'sink-filename-from-header' => ['sink' => 'S', 'headers' => $disposition, 'config' => ['contentPath' => 'x', 'filenamePath' => 'y', 'fileExtension' => '.z']],
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
		$this->log = [];
		$this->behaviour = ($case['answers'] ?? []);

		$useSink = (array_key_exists('sink', $case) === true || ($case['sinkMissing'] ?? false) === true);
		$sinkPath = null;
		if ($useSink === true) {
			$sinkPath = tempnam(sys_get_temp_dir(), 'sff');
			$this->tempFiles[] = $sinkPath;
			file_put_contents($sinkPath, (string)($case['sink'] ?? ''));
			if (($case['sinkMissing'] ?? false) === true) {
				unlink($sinkPath);
			}
		}

		$prepared = [
			'originalEndpoint' => '/files/42',
			'endpoint'         => 'https://x.invalid/files/42',
			'config'           => ($case['config'] ?? []),
			'useSink'          => $useSink,
			'sinkPath'         => $sinkPath,
		];

		$callLog = new ObjectEntity();
		$object = ['request' => ['url' => ($case['request'] ?? '')]];
		if (array_key_exists('response', $case) === false) {
			$object['response'] = ['statusCode' => 200, 'headers' => ($case['headers'] ?? []), 'body' => ($case['body'] ?? '')];
		}

		$callLog->setObject($object);

		$filename = ($case['filename'] ?? null);
		$args = [
			$prepared,
			$callLog,
			($case['objectId'] ?? self::UUID),
			(array_key_exists('tags', $case) === true ? $case['tags'] : ['default-tag']),
			&$filename,
			($case['published'] ?? null),
			($case['registerId'] ?? null),
		];

		$service = $this->service();
		$this->log = [];
		$method = new ReflectionMethod(SynchronizationService::class, 'saveFetchedFile');
		try {
			$outcome = ['return' => $method->invokeArgs($service, $args)];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return ['outcome' => $outcome, 'filename' => $filename, 'log' => $this->log];
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
		if (getenv('SAVE_FETCHED_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
