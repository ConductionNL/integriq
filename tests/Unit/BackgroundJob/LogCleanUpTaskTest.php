<?php

/**
 * Unit tests for LogCleanUpTask's body stripping.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\LogCleanUpTask;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Captured bodies age out and the record stays.
 */
class LogCleanUpTaskTest extends TestCase {

	/**
	 * The filters each findAll() was asked for.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * Every save, with its uuid.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saves = [];

	/**
	 * Every delete, by uuid.
	 *
	 * @var array<int,string>
	 */
	private array $deletes = [];

	/**
	 * Build the task over a store answering the given records to a body-expiry query.
	 *
	 * @param array<string,array<string,mixed>> $expiredBodies The call records past bodyExpiresAt, by uuid.
	 *
	 * @return LogCleanUpTask
	 */
	private function task(array $expiredBodies): LogCleanUpTask {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($expiredBodies) {
				$this->queries[] = $config['filters'];
				if (isset($config['filters']['bodyExpiresAt[lt]']) === false) {
					return ['results' => []];
				}

				$rows = [];
				foreach ($expiredBodies as $uuid => $record) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($record);
					$rows[] = $entity;
				}

				return ['results' => $rows];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function ($uuid) {
				$this->deletes[] = (string)$uuid;
				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		return new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			$objectService,
			new BodyCapturePolicy($appConfig)
		);
	}//end task()

	/**
	 * A record captured in a window whose bodies expired yesterday.
	 *
	 * @return array<string,mixed>
	 */
	private function capturedRecord(): array {
		return [
			'source' => '7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f',
			'direction' => 'outbound',
			'statusCode' => 500,
			'statusMessage' => 'Internal Server Error',
			'request' => ['url' => 'https://zaken.example.invalid/zaken', 'method' => 'POST', 'json' => ['bsn' => '999993653'], 'headers' => ['Accept' => 'application/json']],
			'response' => ['statusCode' => 500, 'responseTime' => 12.5, 'headers' => ['Content-Type' => ['text/plain']], 'body' => 'fout voor 999993653'],
			'replayRequest' => ['url' => 'https://zaken.example.invalid/zaken', 'method' => 'POST', 'json' => ['bsn' => '999993653']],
			'bodyCaptured' => true,
			'bodyExpiresAt' => '2026-10-08T12:00:00+00:00',
			'created' => '2026-10-01T12:00:00+00:00',
			'expires' => '2026-10-31T12:00:00+00:00',
		];
	}//end capturedRecord()

	/**
	 * Expired bodies are stripped, the record is kept with its status, timing and headers.
	 *
	 * @return void
	 */
	public function testExpiredBodiesAreStrippedAndTheRecordKept(): void {
		$this->task(['call-1' => $this->capturedRecord()])->run(null);

		$this->assertCount(1, $this->saves);
		$saved = $this->saves[0];
		$this->assertSame('call-1', $saved['uuid']);
		$this->assertSame('call_log', $saved['schema']);
		$record = $saved['object'];
		$this->assertArrayNotHasKey('json', $record['request']);
		$this->assertArrayNotHasKey('body', $record['response']);
		$this->assertArrayNotHasKey('replayRequest', $record);
		$this->assertArrayNotHasKey('bodyExpiresAt', $record);
		$this->assertFalse($record['bodyCaptured']);
		$this->assertArrayHasKey('bodyExpiredAt', $record);
		$this->assertSame(500, $record['statusCode']);
		$this->assertSame(12.5, $record['response']['responseTime']);
		$this->assertSame(['Accept' => 'application/json'], $record['request']['headers']);
		$this->assertStringNotContainsString('999993653', json_encode($record));
		$this->assertNotContains('call-1', $this->deletes, 'the record itself stays until its own expiry');
		$this->assertSame([], RegisterSchemaValidator::errors('call_log', $record), 'the register accepts the stripped record');
	}//end testExpiredBodiesAreStrippedAndTheRecordKept()

	/**
	 * A failure outside a window keeps its replay request until the error retention ends, then loses it.
	 *
	 * @return void
	 */
	public function testReplayRequestEndsWithTheErrorRetention(): void {
		$record = $this->capturedRecord();
		$record['bodyCaptured'] = false;
		unset($record['request']['json'], $record['response']['body']);

		$this->task(['call-2' => $record])->run(null);

		$this->assertCount(1, $this->saves);
		$this->assertArrayNotHasKey('replayRequest', $this->saves[0]['object']);
	}//end testReplayRequestEndsWithTheErrorRetention()

	/**
	 * The query asks only for call records past their body expiry, and run() still deletes expired logs.
	 *
	 * @return void
	 */
	public function testRunStripsBodiesAndStillDeletesExpiredLogs(): void {
		$this->task([])->run(null);

		$this->assertSame('call_log', $this->queries[0]['schema']);
		$this->assertArrayHasKey('bodyExpiresAt[lt]', $this->queries[0]);
		$schemas = array_map(static fn (array $filters): string => $filters['schema'], array_slice($this->queries, 1));
		$this->assertSame(['call_log', 'job_log', 'synchronization_contract_log', 'synchronization_log'], $schemas);
		$this->assertSame([], $this->saves);
	}//end testRunStripsBodiesAndStillDeletesExpiredLogs()

	/**
	 * The task is still registered as a background job.
	 *
	 * @return void
	 */
	public function testTheTaskIsRegisteredInInfoXml(): void {
		$xml = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($xml->{'background-jobs'}->job, false));

		$this->assertContains(LogCleanUpTask::class, $jobs);
	}//end testTheTaskIsRegisteredInInfoXml()
}//end class
