<?php

/**
 * Unit tests for the error sink exporters and the export job (REQ-OTEL-006).
 *
 * The HTTP client is a mocked `IClientService`; the posted bodies are
 * compared with stored payload fixtures.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability\Errors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Errors;

use OCA\Integriq\BackgroundJob\ErrorSinkExportJob;
use OCA\Integriq\Observability\Errors\ErrorSinkListener;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCA\Integriq\Observability\Errors\OtlpLogExporter;
use OCA\Integriq\Observability\Errors\SentryEnvelopeExporter;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Log\BeforeMessageLoggedEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two wire formats, redaction before the queue, and the job's retries.
 */
class ErrorSinkExporterTest extends TestCase {

	/**
	 * The stored payload fixtures.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__ . '/../../../fixtures/observability/errorsink/';

	/**
	 * Stored app settings, as an in-memory IAppConfig.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The posts the client made: URL and options.
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $posted = [];

	/**
	 * The status the mocked service answers, or null to throw.
	 *
	 * @var int|null
	 */
	private ?int $answer = 202;

	/**
	 * Reset the settings, the posts and the answer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [
			'errorsink_enabled' => true,
			'errorsink_kind' => 'sentry',
			'errorsink_endpoint' => 'https://key@sentry.example.org/42',
		];
		$this->posted = [];
		$this->answer = 202;

	}//end setUp()

	/**
	 * The event the fixtures were built from.
	 *
	 * @return array<string, int|string>
	 */
	private function event(): array {
		return [
			'app' => 'openregister',
			'level' => 3,
			'time' => 1760000000,
			'exceptionClass' => 'OCA\\OpenRegister\\Exception\\ValidationException',
			'file' => '/var/www/html/apps/openregister/lib/Service/ObjectService.php',
			'line' => 412,
			'requestId' => 'req-1',
			'message' => 'Saving failed: The object does not match its schema',
		];

	}//end event()

	/**
	 * Error sink settings over $this->config.
	 *
	 * @return ErrorSinkSettings
	 */
	private function settings(): ErrorSinkSettings {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		foreach (['getValueBool', 'getValueString', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ErrorSinkSettings(appConfig: $appConfig, l: $l10n);

	}//end settings()

	/**
	 * An HTTP client service that records each post and answers $this->answer.
	 *
	 * @return IClientService
	 */
	private function clientService(): IClientService {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options): IResponse {
				$this->posted[] = [$url, $options];
				if ($this->answer === null) {
					throw new RuntimeException('Connection refused');
				}

				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($this->answer);
				return $response;
			}
		);
		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;

	}//end clientService()

	/**
	 * The export job over the recording client, a fixed clock and the given job list.
	 *
	 * @param IJobList $jobList The job list retries go to.
	 *
	 * @return ErrorSinkExportJob
	 */
	private function job(IJobList $jobList): ErrorSinkExportJob {
		$settings = $this->settings();
		$clientService = $this->clientService();
		$credentials = $this->createMock(originalClassName: BrokeredCallServiceInterface::class);
		$credentials->method('resolveCredentialRef')->willReturn('Bearer collector-secret');
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		return new ErrorSinkExportJob(
			time: $time,
			settings: $settings,
			sentry: new SentryEnvelopeExporter(clientService: $clientService, settings: $settings),
			otlpLogs: new OtlpLogExporter(clientService: $clientService, settings: $settings, credentials: $credentials),
			jobList: $jobList
		);

	}//end job()

	/**
	 * A DSN `https://key@sentry.example.org/42` gets one POST to the
	 * envelope endpoint with the auth header built from the key.
	 *
	 * @return void
	 */
	public function testASentryEnvelopeIsPostedToTheDsnEndpoint(): void {
		$this->job(jobList: $this->createMock(originalClassName: IJobList::class))->run(['event' => $this->event(), 'attempt' => 0]);

		$this->assertCount(expectedCount: 1, haystack: $this->posted);
		[$url, $options] = $this->posted[0];
		$this->assertSame(expected: 'https://sentry.example.org/api/42/envelope/', actual: $url);
		$this->assertSame(
			expected: 'Sentry sentry_version=7, sentry_client=integriq-errorsink/1.0, sentry_key=key',
			actual: $options['headers']['X-Sentry-Auth']
		);
		$this->assertSame(expected: 'application/x-sentry-envelope', actual: $options['headers']['Content-Type']);
		$this->assertStringEqualsFile(expectedFile: self::FIXTURES . 'sentry-envelope.txt', actualString: $options['body']);
		$this->assertArrayNotHasKey(key: 'nextcloud', array: $options, message: 'A public service is not reached as a local address.');

		$lines = explode("\n", $options['body']);
		$item = json_decode($lines[2], true);
		$this->assertSame(expected: 'OCA\\OpenRegister\\Exception\\ValidationException', actual: $item['exception']['values'][0]['type']);
		$this->assertSame(expected: 'openregister', actual: $item['tags']['app']);

	}//end testASentryEnvelopeIsPostedToTheDsnEndpoint()

	/**
	 * Kind `otlp-logs` gets one POST of a `resourceLogs` body to `{endpoint}/v1/logs`,
	 * with the collector login resolved from its credential reference.
	 *
	 * @return void
	 */
	public function testAnOtlpLogRecordIsPostedToV1Logs(): void {
		$this->config = [
			'errorsink_enabled' => true,
			'errorsink_kind' => 'otlp-logs',
			'errorsink_endpoint' => 'http://otel.internal:4318',
			'errorsink_allow_local' => true,
			'errorsink_credential_name' => 'otel-login',
		];

		$this->job(jobList: $this->createMock(originalClassName: IJobList::class))->run(['event' => $this->event(), 'attempt' => 0]);

		$this->assertCount(expectedCount: 1, haystack: $this->posted);
		[$url, $options] = $this->posted[0];
		$this->assertSame(expected: 'http://otel.internal:4318/v1/logs', actual: $url);
		$this->assertSame(expected: 'Bearer collector-secret', actual: $options['headers']['Authorization']);
		$this->assertSame(expected: ['allow_local_address' => true], actual: $options['nextcloud']);
		$this->assertJsonStringEqualsJsonFile(expectedFile: self::FIXTURES . 'otlp-logs.json', actualJson: $options['body']);

	}//end testAnOtlpLogRecordIsPostedToV1Logs()

	/**
	 * A BSN in a logged message is gone from the queued event, and so from
	 * the posted payload.
	 *
	 * @return void
	 */
	public function testTheRedactorRunsBeforeTheEventIsQueued(): void {
		$queued = [];
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, $argument) use (&$queued): void {
				$queued[] = $argument;
			}
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$listener = new ErrorSinkListener(
			settings: $this->settings(),
			redactor: new BodyRedactor(),
			jobList: $jobList,
			time: $time,
			request: $this->createMock(originalClassName: IRequest::class)
		);

		$listener->handle(
			new BeforeMessageLoggedEvent(
				'openregister',
				3,
				[
					'Exception' => 'RuntimeException',
					'Message' => 'No person found for BSN 999993653 (token=abcd1234efgh)',
					'File' => '/var/www/html/apps/openregister/lib/Service/BrpService.php',
					'Line' => 88,
					'CustomMessage' => '--',
				]
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $queued);
		$this->assertStringNotContainsString(needle: '999993653', haystack: (string)json_encode($queued[0]));
		$this->assertStringNotContainsString(needle: 'abcd1234efgh', haystack: (string)json_encode($queued[0]));
		$this->assertSame(expected: 'No person found for BSN [redacted] (token: [redacted]', actual: $queued[0]['event']['message']);

		$this->job(jobList: $this->createMock(originalClassName: IJobList::class))->run($queued[0]);

		$this->assertCount(expectedCount: 1, haystack: $this->posted);
		$this->assertStringNotContainsString(needle: '999993653', haystack: $this->posted[0][1]['body']);
		$this->assertStringContainsString(needle: '[redacted]', haystack: $this->posted[0][1]['body']);

	}//end testTheRedactorRunsBeforeTheEventIsQueued()

	/**
	 * A refused post is retried three times, five, ten and twenty minutes
	 * out; the fourth failure drops the event without a retry.
	 *
	 * @return void
	 */
	public function testARefusedPostStopsAfterFourTriesWithoutLogging(): void {
		$this->answer = 503;
		$retries = [];
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) use (&$retries): void {
				$this->assertSame(expected: ErrorSinkExportJob::class, actual: $job);
				$retries[] = [$runAfter, $argument];
			}
		);
		$job = $this->job(jobList: $jobList);

		$argument = ['event' => $this->event(), 'attempt' => 0];
		for ($run = 0; $run < 4; $run++) {
			$before = count($retries);
			$job->run($argument);
			if (count($retries) > $before) {
				$argument = $retries[array_key_last($retries)][1];
				$argument['notBefore'] = 0;
			}
		}

		$this->assertCount(expectedCount: 4, haystack: $this->posted, message: 'Four tries in all.');
		$this->assertSame(
			expected: [1760000000 + 300, 1760000000 + 600, 1760000000 + 1200],
			actual: array_column($retries, 0)
		);
		$this->assertSame(expected: [1, 2, 3], actual: array_map(static fn (array $r): int => $r[1]['attempt'], $retries));

	}//end testARefusedPostStopsAfterFourTriesWithoutLogging()

	/**
	 * An unreachable service is a failed send, and a retry that cannot be
	 * queued is dropped without an error reaching cron.
	 *
	 * @return void
	 */
	public function testAnUnreachableServiceIsRetriedAndAFailingRetryIsSwallowed(): void {
		$this->answer = null;
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->willThrowException(new RuntimeException('database gone'));

		$this->job(jobList: $jobList)->run(['event' => $this->event(), 'attempt' => 0]);

		$this->assertCount(expectedCount: 1, haystack: $this->posted);

	}//end testAnUnreachableServiceIsRetriedAndAFailingRetryIsSwallowed()

	/**
	 * A switched-off sink, an empty argument and a retry that is not yet due send nothing.
	 *
	 * @return void
	 */
	public function testTheJobSendsNothingWhenOffEmptyOrNotDue(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())->method('scheduleAfter')->with(
			ErrorSinkExportJob::class,
			1760000999,
			['event' => $this->event(), 'attempt' => 1, 'notBefore' => 1760000999]
		);
		$job = $this->job(jobList: $jobList);

		$job->run('not an array');
		$job->run(['event' => 'not an array']);
		$job->run(['event' => $this->event(), 'attempt' => 1, 'notBefore' => 1760000999]);
		$this->config['errorsink_enabled'] = false;
		$job->run(['event' => $this->event(), 'attempt' => 0]);

		$this->assertSame(expected: [], actual: $this->posted);

	}//end testTheJobSendsNothingWhenOffEmptyOrNotDue()

	/**
	 * A DSN without a project id cannot be sent to, and an OTLP sink without
	 * an endpoint refuses to send.
	 *
	 * @return void
	 */
	public function testAnUnusableAddressIsAFailedSend(): void {
		$settings = $this->settings();
		$sentry = new SentryEnvelopeExporter(clientService: $this->clientService(), settings: $settings);
		$this->assertSame(
			expected: ['url' => 'https://sentry.example.org:9000/sub/api/7/envelope/', 'key' => 'abc'],
			actual: $sentry->parseDsn(dsn: 'https://abc@sentry.example.org:9000/sub/7')
		);

		try {
			$sentry->parseDsn(dsn: 'https://sentry.example.org/project');
			$this->fail(message: 'A DSN without a key or project id is refused.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'The Sentry DSN is not usable.', actual: $e->getMessage());
		}

		$this->config['errorsink_endpoint'] = '';
		$credentials = $this->createMock(originalClassName: BrokeredCallServiceInterface::class);
		$otlp = new OtlpLogExporter(clientService: $this->clientService(), settings: $this->settings(), credentials: $credentials);
		$this->expectException(exception: RuntimeException::class);
		$otlp->export(event: $this->event());

	}//end testAnUnusableAddressIsAFailedSend()

	/**
	 * A plain message without an exception becomes a Sentry message event,
	 * and the levels map to Sentry and OTLP severities.
	 *
	 * @return void
	 */
	public function testAPlainMessageAndTheLevelsAreMapped(): void {
		$settings = $this->settings();
		$event = [
			'app' => 'integriq',
			'level' => 4,
			'time' => 1,
			'exceptionClass' => '',
			'file' => '',
			'line' => 0,
			'requestId' => '',
			'message' => 'Out of memory',
		];

		$lines = explode("\n", (new SentryEnvelopeExporter(clientService: $this->clientService(), settings: $settings))->body(event: $event));
		$sentry = json_decode($lines[2], true);
		$this->assertSame(expected: 'fatal', actual: $sentry['level']);
		$this->assertSame(expected: ['formatted' => 'Out of memory'], actual: $sentry['message']);
		$this->assertArrayNotHasKey(key: 'exception', array: $sentry);
		$this->assertSame(expected: ['app' => 'integriq'], actual: $sentry['tags']);
		$this->assertSame(expected: strlen($lines[2]), actual: json_decode($lines[1], true)['length']);

		$credentials = $this->createMock(originalClassName: BrokeredCallServiceInterface::class);
		$exporter = new OtlpLogExporter(clientService: $this->clientService(), settings: $settings, credentials: $credentials);
		$otlp = json_decode($exporter->body(event: $event), true);
		$record = $otlp['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
		$this->assertSame(expected: 21, actual: $record['severityNumber']);
		$this->assertSame(expected: 'FATAL', actual: $record['severityText']);
		$this->assertSame(expected: [['key' => 'nextcloud.app', 'value' => ['stringValue' => 'integriq']]], actual: $record['attributes']);

	}//end testAPlainMessageAndTheLevelsAreMapped()
}//end class
