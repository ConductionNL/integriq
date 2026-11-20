<?php

/**
 * Golden master of DsoAttachmentFetcher::fetchOne().
 *
 * Records, per case, the returned entry, every download call (URL, size cap,
 * whether the source configuration was passed through), every addFile call,
 * the pauses between attempts, the warnings logged and whether the stream
 * was closed. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use Error;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\PayloadTooLargeException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Recorded fetchOne() behaviour per case.
 *
 * @covers \OCA\Integriq\Service\Dso\DsoAttachmentFetcher
 * @uses   \OCA\Integriq\Service\PayloadTooLargeException
 */
class DsoAttachmentFetcherFetchOneGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/golden/dso-attachment-fetch-one.json';

	private const CONFIG = ['baseUrl' => 'https://dso.example', 'authentication' => ['encryptedToken' => 'cipher']];

	/**
	 * The cases: entry, configuration, the download script and the addFile script.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$entry = ['name' => 'a.pdf', 'url' => 'https://dso.example/docs/a.pdf', 'status' => 'pending', 'attempts' => 0];
		return [
			'stored' => ['entry' => $entry, 'downloads' => ['ok']],
			'no-url' => ['entry' => ['name' => 'a.pdf'], 'downloads' => []],
			'http-url' => ['entry' => ['url' => 'http://dso.example/a'] + $entry, 'downloads' => []],
			'upper-https' => ['entry' => ['url' => 'HTTPS://dso.example/a'] + $entry, 'downloads' => ['ok']],
			'file-url' => ['entry' => ['url' => 'file:///etc/passwd'] + $entry, 'downloads' => []],
			'max-size' => ['entry' => $entry, 'config' => ['maxFileSize' => 10] + self::CONFIG, 'downloads' => ['ok']],
			'max-size-zero' => ['entry' => $entry, 'config' => ['maxFileSize' => 0] + self::CONFIG, 'downloads' => ['ok']],
			'max-size-negative' => ['entry' => $entry, 'config' => ['maxFileSize' => -5] + self::CONFIG, 'downloads' => ['ok']],
			'too-large' => ['entry' => $entry, 'downloads' => ['large']],
			'retry-then-ok' => ['entry' => $entry, 'downloads' => ['fail', 'fail', 'ok']],
			'all-fail' => ['entry' => $entry, 'downloads' => ['fail', 'fail', 'fail', 'ok']],
			'resumed-attempts' => ['entry' => ['attempts' => 2] + $entry, 'downloads' => ['fail', 'ok']],
			'exhausted' => ['entry' => ['attempts' => 3, 'error' => 'old'] + $entry, 'downloads' => ['ok']],
			'add-file-fails' => ['entry' => $entry, 'downloads' => ['ok', 'ok', 'ok'], 'addFile' => ['fail', 'fail', 'ok']],
			'error-propagates' => ['entry' => $entry, 'downloads' => ['error']],
			'old-error-cleared' => ['entry' => ['error' => 'old', 'status' => 'failed', 'attempts' => 1] + $entry, 'downloads' => ['ok']],
		];
	}//end cases()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$log       = ['downloads' => [], 'addFile' => [], 'pauses' => [], 'warnings' => [], 'closed' => []];
		$downloads = $case['downloads'];
		$adds      = ($case['addFile'] ?? []);
		$streams   = [];

		$client = $this->createMock(DsoClient::class);
		$client->method('download')->willReturnCallback(
			function (array $sourceConfiguration, string $url, int $maxBytes) use (&$log, &$downloads, &$streams) {
				$log['downloads'][] = [$url, $maxBytes, $sourceConfiguration === ($this->config ?? null)];
				$step = array_shift($downloads);
				if ($step === 'fail') {
					throw new RuntimeException('network down');
				}

				if ($step === 'large') {
					throw new PayloadTooLargeException(message: 'too big');
				}

				if ($step === 'error') {
					throw new Error('defect');
				}

				$stream = fopen('php://memory', 'w+b');
				fwrite($stream, 'PDF');
				rewind($stream);
				$streams[] = $stream;
				return $stream;
			}
		);

		$fileService = $this->createMock(FileService::class);
		$fileService->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use (&$log, &$adds) {
				$log['addFile'][] = [$target->object->getUuid(), $upload->fileName, stream_get_contents($upload->content), $upload->share, $upload->tags];
				if (array_shift($adds) === 'fail') {
					throw new RuntimeException('disk full');
				}

				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(77);
				return $file;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$log) {
				$log['warnings'][] = [$message, $context];
			}
		);

		$fetcher = new class(
			$this->createMock(ORObjectService::class),
			$this->createMock(DsoIngestService::class),
			$client,
			$this->createMock(ContainerInterface::class),
			$logger
		) extends DsoAttachmentFetcher {
			/**
			 * Seconds paused, in order.
			 *
			 * @var array<int, int>
			 */
			public array $paused = [];

			/**
			 * Record the pause instead of sleeping.
			 *
			 * @param int $seconds Seconds.
			 *
			 * @return void
			 */
			protected function pause(int $seconds): void {
				$this->paused[] = $seconds;
			}//end pause()
		};

		$request = new ObjectEntity();
		$request->setUuid('verzoek-1');
		$this->config = ($case['config'] ?? self::CONFIG);

		try {
			$log['result'] = $fetcher->fetchOne($request, $case['entry'], $this->config, $fileService);
		} catch (Throwable $e) {
			$log['thrown'] = [get_class($e), $e->getMessage()];
		}

		$log['pauses'] = $fetcher->paused;
		$log['closed'] = array_map(static fn ($s) => is_resource($s) === false, $streams);
		return $log;
	}//end runCase()

	/**
	 * The configuration of the case being run.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $config = null;

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testFetchOneMatchesTheRecording(): void {
		$found = [];
		foreach ($this->cases() as $name => $case) {
			$found[$name] = $this->runCase(case: $case);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode((string)json_encode($found[$name]), true), $name);
		}
	}//end testFetchOneMatchesTheRecording()
}//end class
