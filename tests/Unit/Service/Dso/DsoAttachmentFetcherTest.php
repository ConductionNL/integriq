<?php

/**
 * Unit tests for DsoAttachmentFetcher.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-attachments-on-the-request/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the download, the retry, the size cap, the addFile() call shape and
 * the rerun behaviour of the DSO bijlage fetcher.
 *
 * The DsoClient is the real class over a Guzzle MockHandler, so the token
 * header, the GET and the streamed body are what production sends.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */
class DsoAttachmentFetcherTest extends TestCase {

	/**
	 * The stored request, as the fake object store holds it.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $stored = null;

	/**
	 * The addFile() call (1-based) on which the fake worker dies, or null.
	 *
	 * @var integer|null
	 */
	private ?int $dieOnAddFile = null;

	/**
	 * Every addFile() call, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $addFileCalls = [];

	/**
	 * Guzzle request history.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * The active DSO source's configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $sourceConfiguration = [
		'baseUrl' => 'https://dso-lv.example.nl/api/v1',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * Store a request with the given attachment entries.
	 *
	 * @param array<int, array<string, mixed>> $attachments The entries.
	 *
	 * @return void
	 */
	private function storeRequest(array $attachments): void {
		$entity = new ObjectEntity();
		$entity->setUuid('verzoek-1');
		$entity->setObject(['verzoekId' => 'dso-1', 'status' => 'mapped', 'attachments' => $attachments]);
		$this->stored = $entity;

	}//end storeRequest()

	/**
	 * A pending entry.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function pending(string $name): array {
		return ['name' => $name, 'url' => 'https://dso-lv.example.nl/docs/' . $name, 'status' => 'pending', 'attempts' => 0];

	}//end pending()

	/**
	 * Build the fetcher over a queue of Guzzle responses.
	 *
	 * @param array<int, mixed> $responses The queued responses or exceptions.
	 * @param MtlsConfigResolver|null $resolver An mTLS resolver, or null for the real one.
	 * @param MtlsTransportService|null $transport An mTLS transport, or null for the real one.
	 * @param boolean $withFileService False when OpenRegister cannot provide FileService.
	 *
	 * @return DsoAttachmentFetcher The fetcher, with a recording, non-sleeping pause().
	 */
	private function buildFetcher(
		array $responses,
		?MtlsConfigResolver $resolver = null,
		?MtlsTransportService $transport = null,
		bool $withFileService = true
	): DsoAttachmentFetcher {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$stack = HandlerStack::create(new MockHandler($responses));
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		$client = new DsoClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l10n,
			$logger,
			($resolver ?? new MtlsConfigResolver($crypto)),
			($transport ?? new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger))
		);

		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('find')->willReturnCallback(fn () => $this->stored);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$uuid);
				$entity->setObject((array)$object);
				$this->stored = $entity;

				return $entity;
			}
		);

		$source = new ObjectEntity();
		$source->setUuid('source-dso');
		$source->setObject(['type' => 'dso', 'configuration' => $this->sourceConfiguration]);
		$ingest = $this->getMockBuilder(DsoIngestService::class)->disableOriginalConstructor()->getMock();
		// The account the job runs as is not an admin, and the source is
		// admin-only: the fetcher must ask for an engine read (D5).
		$ingest->method('resolveActiveSource')->willReturnCallback(
			function (bool $engineRead = false) use ($source): ObjectEntity {
				$this->assertTrue($engineRead, 'the bijlage path reads the DSO source as an engine read');
				return $source;
			}
		);

		$fileService = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->getMock();
		$fileService->method('addFile')->willReturnCallback(
			function ($objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = []) {
				if ($this->dieOnAddFile !== null && count($this->addFileCalls) + 1 === $this->dieOnAddFile) {
					throw new \Error('worker killed');
				}

				$this->addFileCalls[] = [
					'objectEntity' => $objectEntity,
					'fileName' => $fileName,
					'isResource' => is_resource($content),
					'bytes' => (is_resource($content) === true ? stream_get_contents($content) : $content),
					'share' => $share,
					'tags' => $tags,
				];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(100 + count($this->addFileCalls));

				return $file;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($withFileService === true) {
			$container->method('get')->willReturn($fileService);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		}

		return new class($objectService, $ingest, $client, $container, $logger) extends DsoAttachmentFetcher {

			/**
			 * The backoffs asked for, in seconds.
			 *
			 * @var array<int, int>
			 */
			public array $pauses = [];

			/**
			 * Record the backoff instead of sleeping.
			 *
			 * @param integer $seconds The backoff.
			 *
			 * @return void
			 */
			protected function pause(int $seconds): void {
				$this->pauses[] = $seconds;
			}//end pause()
		};

	}//end buildFetcher()

	/**
	 * Each bijlage is fetched with GET and the source's token, streamed into
	 * addFile() on the request object, tagged dso-bijlage, and its entry ends
	 * `stored` with the file id. The register accepts what is written.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
	 *
	 * @return void
	 */
	public function testStoresEachBijlageAsATaggedFileOnTheRequest(): void {
		$this->storeRequest([$this->pending('tekening.dwg'), $this->pending('rapport.pdf'), $this->pending('berekening.pdf')]);
		$fetcher = $this->buildFetcher([new Response(200, [], 'DWG'), new Response(200, [], 'PDF1'), new Response(200, [], 'PDF2')]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertCount(3, $this->addFileCalls);
		foreach ($this->addFileCalls as $call) {
			$this->assertSame('verzoek-1', $call['objectEntity']->getUuid());
			$this->assertTrue($call['isResource'], 'addFile() must receive a stream, not a string.');
			$this->assertFalse($call['share']);
			$this->assertSame(['dso-bijlage'], $call['tags']);
		}

		$this->assertSame(['tekening.dwg', 'rapport.pdf', 'berekening.pdf'], array_column($this->addFileCalls, 'fileName'));
		$this->assertSame(['DWG', 'PDF1', 'PDF2'], array_column($this->addFileCalls, 'bytes'));

		foreach ($this->history as $transaction) {
			$this->assertInstanceOf(Request::class, $transaction['request']);
			$this->assertSame('GET', $transaction['request']->getMethod());
			$this->assertSame('Bearer raw-token-value', $transaction['request']->getHeaderLine('Authorization'));
		}

		$this->assertSame(['stored', 'stored', 'stored'], array_column($attachments, 'status'));
		$this->assertSame([101, 102, 103], array_column($attachments, 'fileId'));
		$this->assertSame($attachments, $this->stored->getObject()['attachments']);
		$this->assertFalse($this->stored->getObject()['attachmentMissing']);
		$this->assertSame(
			[],
			RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->stored->getObject())
		);

	}//end testStoresEachBijlageAsATaggedFileOnTheRequest()

	/**
	 * A source in mTLS mode downloads through the mTLS transport, without a token.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
	 *
	 * @return void
	 */
	public function testMtlsSourceDownloadsThroughTheMtlsTransport(): void {
		$this->sourceConfiguration = ['authentication' => ['mode' => 'mtls', 'mtls' => []]];
		$this->storeRequest([$this->pending('tekening.pdf')]);

		$resolver = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('isMtlsConfigured')->willReturn(true);
		$resolver->method('resolve')->willReturn(new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY'));
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->expects($this->once())
			->method('request')
			->with(
				$this->anything(),
				'GET',
				'https://dso-lv.example.nl/docs/tekening.pdf',
				$this->callback(static fn (array $options): bool => (isset($options['headers']['Authorization']) === false)),
				$this->isInstanceOf(MtlsCertificateBundle::class)
			)
			->willReturn(new Response(200, [], 'PDF'));

		$attachments = $this->buildFetcher([], $resolver, $transport)->fetchPending('verzoek-1');

		$this->assertSame('stored', $attachments[0]['status']);
		$this->assertSame('PDF', $this->addFileCalls[0]['bytes']);

	}//end testMtlsSourceDownloadsThroughTheMtlsTransport()

	/**
	 * A download that keeps failing is tried three times with exponential
	 * backoff, then ends `failed` with the last error.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 *
	 * @return void
	 */
	public function testPersistentFailureIsRetriedThreeTimesThenFailed(): void {
		$this->storeRequest([$this->pending('tekening.pdf')]);
		$request = new Request('GET', 'https://dso-lv.example.nl/docs/tekening.pdf');
		$fetcher = $this->buildFetcher([
			new ConnectException('Connection timed out', $request),
			new Response(503),
			new Response(503),
			new Response(200, [], 'never reached'),
		]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertCount(3, $this->history);
		$this->assertSame([1, 2], $fetcher->pauses);
		$this->assertSame([], $this->addFileCalls);
		$this->assertSame('failed', $attachments[0]['status']);
		$this->assertSame(3, $attachments[0]['attempts']);
		$this->assertStringContainsString('HTTP 503', $attachments[0]['error']);
		$this->assertArrayNotHasKey('fileId', $attachments[0]);
		$this->assertTrue($this->stored->getObject()['attachmentMissing'], 'A failed bijlage flags the request.');
		$this->assertSame(
			[],
			RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->stored->getObject())
		);

	}//end testPersistentFailureIsRetriedThreeTimesThenFailed()

	/**
	 * A failure followed by a success ends `stored`, with the error cleared.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 *
	 * @return void
	 */
	public function testRetrySucceedsAfterATransientFailure(): void {
		$this->storeRequest([$this->pending('tekening.pdf')]);
		$fetcher = $this->buildFetcher([new Response(502), new Response(200, [], 'PDF')]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame('stored', $attachments[0]['status']);
		$this->assertSame(2, $attachments[0]['attempts']);
		$this->assertArrayNotHasKey('error', $attachments[0]);
		$this->assertSame([1], $fetcher->pauses);

	}//end testRetrySucceedsAfterATransientFailure()

	/**
	 * A bijlage above the source's maxFileSize is not stored and not retried,
	 * whether the size is declared up front or only seen while streaming.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
	 *
	 * @return void
	 */
	public function testOversizedBijlageIsTooLargeAndNotStored(): void {
		$this->sourceConfiguration['maxFileSize'] = 10;
		$this->storeRequest([$this->pending('declared.pdf'), $this->pending('streamed.pdf'), $this->pending('fits.pdf')]);
		$fetcher = $this->buildFetcher([
			new Response(200, ['Content-Length' => '11'], str_repeat('x', 11)),
			new Response(200, [], str_repeat('y', 9000)),
			new Response(200, [], 'tenbytes!!'),
		]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame(['too-large', 'too-large', 'stored'], array_column($attachments, 'status'));
		$this->assertSame([1, 1, 1], array_column($attachments, 'attempts'));
		$this->assertCount(3, $this->history, 'A too-large bijlage must not be retried.');
		$this->assertSame(['fits.pdf'], array_column($this->addFileCalls, 'fileName'));
		$this->assertStringContainsString('maximum of 10 bytes', $attachments[0]['error']);
		$this->assertTrue($this->stored->getObject()['attachmentMissing'], 'A too-large bijlage flags the request.');

	}//end testOversizedBijlageIsTooLargeAndNotStored()

	/**
	 * The default cap is 100 MB when the source sets none.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
	 *
	 * @return void
	 */
	public function testDefaultCapIsOneHundredMegabytes(): void {
		$this->storeRequest([$this->pending('huge.pdf')]);
		$fetcher = $this->buildFetcher([new Response(200, ['Content-Length' => (string)(104857600 + 1)], '')]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame('too-large', $attachments[0]['status']);
		$this->assertSame(104857600, DsoAttachmentFetcher::DEFAULT_MAX_FILE_SIZE);

	}//end testDefaultCapIsOneHundredMegabytes()

	/**
	 * A URL that is missing or not https is refused without a request, and
	 * without attempts left.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-nothing-is-written-outside-nextcloud-files
	 *
	 * @return void
	 */
	public function testMissingOrNonHttpsUrlIsRefusedWithoutARequest(): void {
		$this->storeRequest([
			['name' => 'a.pdf', 'url' => '', 'status' => 'pending', 'attempts' => 0],
			['name' => 'b.pdf', 'url' => 'file:///etc/passwd', 'status' => 'pending', 'attempts' => 0],
			['name' => 'c.pdf', 'url' => 'http://dso-lv.example.nl/docs/c.pdf', 'status' => 'pending', 'attempts' => 0],
		]);
		$fetcher = $this->buildFetcher([]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame([], $this->history);
		$this->assertSame(['failed', 'failed', 'failed'], array_column($attachments, 'status'));
		$this->assertSame([3, 3, 3], array_column($attachments, 'attempts'));

	}//end testMissingOrNonHttpsUrlIsRefusedWithoutARequest()

	/**
	 * Without OpenRegister's FileService nothing is downloaded and every entry stays pending.
	 *
	 * @return void
	 */
	public function testWithoutFileServiceEveryEntryStaysPending(): void {
		$this->storeRequest([$this->pending('tekening.pdf')]);
		$fetcher = $this->buildFetcher([new Response(200, [], 'PDF')], null, null, false);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame([], $this->history);
		$this->assertSame('pending', $attachments[0]['status']);
		$this->assertSame('pending', $this->stored->getObject()['attachments'][0]['status']);

	}//end testWithoutFileServiceEveryEntryStaysPending()

	/**
	 * A worker that dies after storing 2 of 5 bijlagen leaves those 2 saved as
	 * `stored`; the rerun downloads only the other 3 and stores no duplicate.
	 *
	 * The death is an Error thrown by the third addFile(): the fetcher retries
	 * exceptions, not Errors, so it ends the run the way a killed worker does.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 *
	 * @return void
	 */
	public function testRerunFinishesWhatACrashLeft(): void {
		$names = ['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf', 'e.pdf'];
		$this->storeRequest(array_map(fn (string $name): array => $this->pending($name), $names));

		$this->dieOnAddFile = 3;
		$crashing = $this->buildFetcher([new Response(200, [], 'A'), new Response(200, [], 'B'), new Response(200, [], 'C')]);
		try {
			$crashing->fetchPending('verzoek-1');
			$this->fail('The fake worker should have died on the third bijlage.');
		} catch (\Error $error) {
			$this->assertSame('worker killed', $error->getMessage());
		}

		$afterCrash = $this->stored->getObject()['attachments'];
		$this->assertSame(['stored', 'stored', 'pending', 'pending', 'pending'], array_column($afterCrash, 'status'));
		$this->assertSame(['a.pdf', 'b.pdf'], array_column($this->addFileCalls, 'fileName'));

		$this->dieOnAddFile = null;
		$this->addFileCalls = [];
		$rerun = $this->buildFetcher([new Response(200, [], 'C'), new Response(200, [], 'D'), new Response(200, [], 'E')]);
		$attachments = $rerun->fetchPending('verzoek-1');

		$this->assertSame(['c.pdf', 'd.pdf', 'e.pdf'], array_column($this->addFileCalls, 'fileName'));
		$this->assertCount(3, $this->history);
		$this->assertSame(array_fill(0, 5, 'stored'), array_column($attachments, 'status'));
		$this->assertFalse($this->stored->getObject()['attachmentMissing']);
		$this->assertSame($afterCrash[0], $attachments[0]);
		$this->assertSame($afterCrash[1], $attachments[1]);

	}//end testRerunFinishesWhatACrashLeft()

	/**
	 * The flag clears once a rerun stores the bijlage that had failed.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-bijlage-download-retried-and-flagged-on-failure
	 *
	 * @return void
	 */
	public function testFlagClearsWhenAFailedBijlageIsStoredLater(): void {
		$this->storeRequest([
			['name' => 'a.pdf', 'url' => 'https://dso-lv.example.nl/docs/a.pdf', 'status' => 'failed', 'attempts' => 1, 'error' => 'No active DSO source'],
		]);
		$fetcher = $this->buildFetcher([new Response(200, [], 'A')]);

		$attachments = $fetcher->fetchPending('verzoek-1');

		$this->assertSame('stored', $attachments[0]['status']);
		$this->assertSame(2, $attachments[0]['attempts']);
		$this->assertFalse($this->stored->getObject()['attachmentMissing']);

	}//end testFlagClearsWhenAFailedBijlageIsStoredLater()
}//end class
