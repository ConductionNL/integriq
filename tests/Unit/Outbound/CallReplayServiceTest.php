<?php

/**
 * Unit tests for the call record, its replay, the dry run, firing by hand,
 * the mapping version, the verdicts and the pre-check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use InvalidArgumentException;
use OCA\Integriq\Exception\CallDispatchException;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\Call\CallDispatcherInterface;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallReplayService;
use OCA\Integriq\Outbound\Call\MappingVersionService;
use OCA\Integriq\Outbound\Call\PreCheckService;
use OCA\Integriq\Outbound\Call\VerdictService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A dispatcher the test drives instead of a partner.
 */
class RecordingDispatcher implements CallDispatcherInterface {

	/**
	 * Every call it was asked to make.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $sent = [];

	/**
	 * Constructor.
	 *
	 * @param int $statusCode What it answers with.
	 * @param string|null $throw A dispatch failure message, or null to answer.
	 */
	public function __construct(
		private readonly int $statusCode = 200,
		private readonly ?string $throw = null,
	) {

	}//end __construct()

	/**
	 * Send one call.
	 *
	 * @param string $target The target.
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array{statusCode:int,body:mixed,headers:array<string,mixed>,durationMs:int,detail:string}
	 *         What came back.
	 *
	 * @throws CallDispatchException When the test asked for a dispatch failure.
	 */
	public function dispatch(string $target, array $request): array {
		if ($this->throw !== null) {
			throw new CallDispatchException($this->throw);
		}

		$this->sent[] = ['target' => $target, 'request' => $request];

		return [
			'statusCode' => $this->statusCode,
			'body' => ['ok' => ($this->statusCode < 300)],
			'headers' => [],
			'durationMs' => 12,
			'detail' => ($this->statusCode < 300 ? 'OK' : 'Service Unavailable'),
		];

	}//end dispatch()

}//end class

/**
 * Tests that a call is a record, a replay is an attempt, and a dry run sends
 * nothing.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallReplayServiceTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * Everything the double holds, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * How many objects the double has created.
	 *
	 * @var int
	 */
	private int $created = 0;

	/**
	 * The recorder under test.
	 *
	 * @var CallRecorder
	 */
	private CallRecorder $recorder;

	/**
	 * Set up a double that behaves like storage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->records = [];
		$this->created = 0;

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register = '', string $schema = '', ?string $uuid = null) {
				if ($uuid === null || $uuid === '') {
					$this->created++;
					$uuid = 'object-' . $this->created;
				}

				$this->records[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id) {
				return ObjectServiceMockBuilder::objectEntity($this, ($this->records[$id] ?? []), $id);
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(
			function (): array {
				$rows = [];
				foreach ($this->records as $uuid => $record) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $record, $uuid);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$this->recorder = new CallRecorder($this->objectService, new BodyRedactor());

	}//end setUp()

	/**
	 * A failed call is a record with its request, its response and its step.
	 *
	 * @return void
	 */
	public function testAFailedCallIsARecordWithBothHalves(): void {
		$uuid = $this->failedCall();
		$record = $this->records[$uuid];

		$this->assertSame('stuf-partner', $record['target']);
		$this->assertSame(500, $record['statusCode']);
		$this->assertStringContainsString('Fout', $record['statusMessage']);
		$this->assertSame('POST', $record['request']['method']);
		$this->assertCount(1, $record['attempts']);
		$this->assertSame('failed', $record['attempts'][0]['outcome']);

	}//end testAFailedCallIsARecordWithBothHalves()

	/**
	 * A credential never reaches the record.
	 *
	 * @return void
	 */
	public function testACredentialIsRedactedBeforeTheWrite(): void {
		$entity = $this->recorder->record(
			[
				'target' => 'partner',
				'request' => [
					'method' => 'POST',
					'headers' => ['Authorization' => 'Bearer abcdef1234567890'],
					'body' => 'apikey: sk-live-0123456789',
				],
				'statusCode' => 200,
			]
		);

		$record = $this->records[(string)$entity->getUuid()];

		$this->assertSame(BodyRedactor::PLACEHOLDER, $record['request']['headers']['Authorization']);
		$this->assertStringNotContainsString('sk-live-0123456789', $record['request']['body']);

	}//end testACredentialIsRedactedBeforeTheWrite()

	/**
	 * A replay appends an attempt and leaves the first one readable.
	 *
	 * @return void
	 */
	public function testAReplayAppendsAndNeverOverwrites(): void {
		$uuid = $this->failedCall();
		$dispatcher = new RecordingDispatcher(200);
		$service = $this->service($dispatcher);

		$result = $service->replay($uuid, 'beheerder');

		$this->assertTrue($result['succeeded']);
		$this->assertTrue($result['sent']);
		$this->assertCount(2, $this->records[$uuid]['attempts']);
		$this->assertSame('failed', $this->records[$uuid]['attempts'][0]['outcome']);
		$this->assertSame('succeeded', $this->records[$uuid]['attempts'][1]['outcome']);
		$this->assertSame('beheerder', $this->records[$uuid]['attempts'][1]['by']);
		$this->assertCount(1, $dispatcher->sent);

	}//end testAReplayAppendsAndNeverOverwrites()

	/**
	 * A dry run shows the request and writes nothing at all.
	 *
	 * @return void
	 */
	public function testADryRunChangesNothing(): void {
		$uuid = $this->failedCall();
		$before = $this->records;
		$dispatcher = new RecordingDispatcher(200);
		$service = $this->service($dispatcher);

		$result = $service->replay($uuid, 'beheerder', ['dryRun' => true]);

		$this->assertFalse($result['sent']);
		$this->assertSame('POST', $result['request']['method']);
		$this->assertSame([], $dispatcher->sent);
		$this->assertSame($before, $this->records, 'a dry run writes no record and no attempt');

	}//end testADryRunChangesNothing()

	/**
	 * A bulk replay reports each item, and skips none.
	 *
	 * @return void
	 */
	public function testABulkReplayReportsEachItem(): void {
		$first = $this->failedCall();
		$second = $this->failedCall();
		$service = $this->service(new RecordingDispatcher(503));

		$result = $service->replayAll([$first, $second], 'beheerder');

		$this->assertSame(0, $result['succeeded']);
		$this->assertSame(2, $result['failed']);
		$this->assertCount(2, $result['items']);
		$this->assertSame(503, $result['items'][0]['statusCode']);

	}//end testABulkReplayReportsEachItem()

	/**
	 * A call that exhausts its policy lands in the dead-letter list and stays
	 * replayable.
	 *
	 * @return void
	 */
	public function testAnExhaustedCallIsDeadLetteredNotLost(): void {
		$uuid = $this->failedCall(['maxAttempts' => 2]);
		$service = $this->service(new RecordingDispatcher(503));

		$service->replay($uuid, 'beheerder');

		$this->assertTrue($this->records[$uuid]['deadLettered']);

		$deadLetters = array_values(
			array_filter(
				$this->records,
				static fn (array $record): bool => (($record['phase'] ?? '') === 'outbound-call')
			)
		);
		$this->assertCount(1, $deadLetters);
		$this->assertSame($uuid, $deadLetters[0]['originId']);
		$this->assertSame('failed', $deadLetters[0]['status']);

	}//end testAnExhaustedCallIsDeadLetteredNotLost()

	/**
	 * A call still inside its policy is not dead-lettered yet.
	 *
	 * @return void
	 */
	public function testACallWithAttemptsLeftIsNotDeadLettered(): void {
		$uuid = $this->failedCall(['maxAttempts' => 6]);
		$service = $this->service(new RecordingDispatcher(503));

		$service->replay($uuid, 'beheerder');

		$this->assertFalse($this->records[$uuid]['deadLettered']);

	}//end testACallWithAttemptsLeftIsNotDeadLettered()

	/**
	 * A dispatch that never reached the partner is recorded as an attempt too,
	 * because "we could not call them" is an answer somebody needs.
	 *
	 * @return void
	 */
	public function testADispatchFailureIsStillAnAttempt(): void {
		$uuid = $this->failedCall();
		$service = $this->service(new RecordingDispatcher(200, 'no route to host'));

		$result = $service->replay($uuid, 'beheerder');

		$this->assertFalse($result['succeeded']);
		$this->assertFalse($result['sent']);
		$this->assertCount(2, $this->records[$uuid]['attempts']);
		$this->assertStringContainsString('no route to host', $this->records[$uuid]['attempts'][1]['detail']);

	}//end testADispatchFailureIsStillAnAttempt()

	/**
	 * A replay offers both mapping versions and records the one it used,
	 * switching neither silently.
	 *
	 * @return void
	 */
	public function testAReplayNamesTheMappingVersionItRanUnder(): void {
		$this->records['mapping-1'] = ['slug' => 'zaak-naar-stuf', 'version' => '4', 'rules' => ['now']];
		$this->records['snapshot-3'] = [
			'mapping' => 'zaak-naar-stuf',
			'version' => '3',
			'snapshot' => ['slug' => 'zaak-naar-stuf', 'version' => '3', 'rules' => ['then']],
		];

		$uuid = $this->failedCall([], ['mapping' => 'zaak-naar-stuf', 'mappingVersion' => '3']);
		$service = $this->service(new RecordingDispatcher(200));

		$preview = $service->preview($uuid);
		$this->assertSame('3', $preview['versions']['recorded']);
		$this->assertSame('4', $preview['versions']['current']);
		$this->assertTrue($preview['versions']['differ']);

		$keptOriginal = $service->replay($uuid, 'beheerder');
		$this->assertSame('3', $keptOriginal['mappingVersion'], 'naming no version keeps the recorded one');

		$chosen = $service->replay($uuid, 'beheerder', ['mappingVersion' => '4']);
		$this->assertSame('4', $chosen['mappingVersion']);
		$this->assertSame('4', end($this->records[$uuid]['attempts'])['mappingVersion']);

	}//end testAReplayNamesTheMappingVersionItRanUnder()

	/**
	 * A snapshot is taken once per version, not once per call.
	 *
	 * @return void
	 */
	public function testAMappingIsSnapshottedOncePerVersion(): void {
		$versions = new MappingVersionService($this->objectService);
		$mapping = ['slug' => 'zaak-naar-stuf', 'version' => '3', 'rules' => ['then']];

		$this->assertSame('3', $versions->snapshot($mapping));
		$first = count($this->records);
		$this->assertSame('3', $versions->snapshot($mapping));

		$this->assertCount($first, $this->records);

	}//end testAMappingIsSnapshottedOncePerVersion()

	/**
	 * Firing by hand sends the same shape and names the principal.
	 *
	 * @return void
	 */
	public function testFiringByHandRecordsThePrincipal(): void {
		$dispatcher = new RecordingDispatcher(202);
		$service = $this->service($dispatcher);

		$result = $service->fire(
			'notificaties-partner',
			['method' => 'POST', 'endpoint' => '/notificaties', 'body' => ['kanaal' => 'zaken']],
			'beheerder'
		);

		$this->assertTrue($result['succeeded']);
		$this->assertSame(CallRecorder::KIND_HAND_FIRED, $result['kind']);
		$record = $this->records[$result['call']];
		$this->assertSame('beheerder', $record['firedBy']);
		$this->assertSame(CallRecorder::KIND_HAND_FIRED, $record['kind']);
		$this->assertSame(
			['method' => 'POST', 'endpoint' => '/notificaties', 'body' => ['kanaal' => 'zaken']],
			$dispatcher->sent[0]['request'],
			'the receiver gets the same request a triggered call would have sent'
		);

	}//end testFiringByHandRecordsThePrincipal()

	/**
	 * A verdict is stored with its state, source and reason.
	 *
	 * @return void
	 */
	public function testAVerdictIsStoredAgainstItsObject(): void {
		$verdicts = new VerdictService($this->objectService);

		$verdicts->record('zaak/1', 'fail', 'ketenpartner', 'Ontbrekende bijlage');

		$found = $verdicts->forObject('zaak/1');
		$this->assertCount(1, $found);
		$this->assertSame('fail', $found[0]['state']);
		$this->assertSame('ketenpartner', $found[0]['source']);
		$this->assertSame('Ontbrekende bijlage', $found[0]['reason']);

	}//end testAVerdictIsStoredAgainstItsObject()

	/**
	 * A verdict state nothing recognises is refused rather than stored as
	 * something adjacent.
	 *
	 * @return void
	 */
	public function testAnUnknownVerdictStateIsRefused(): void {
		$verdicts = new VerdictService($this->objectService);

		$this->expectException(InvalidArgumentException::class);
		$verdicts->record('zaak/1', 'misschien', 'ketenpartner');

	}//end testAnUnknownVerdictStateIsRefused()

	/**
	 * A refusal travels with its reason.
	 *
	 * @return void
	 */
	public function testAPreCheckRefusalCarriesItsReason(): void {
		$service = new PreCheckService($this->createMock(IClientService::class), $this->recorder);

		$answer = $service->ask(
			['mock' => true, 'fixture' => ['decision' => 'refuse', 'reason' => 'Onvoldoende saldo']],
			['act' => 'besluit']
		);

		$this->assertSame(PreCheckService::REFUSE, $answer['decision']);
		$this->assertSame('Onvoldoende saldo', $answer['reason']);

	}//end testAPreCheckRefusalCarriesItsReason()

	/**
	 * A timeout is not permission.
	 *
	 * @return void
	 */
	public function testAPreCheckTimeoutIsNotPermission(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new RuntimeException('cURL error 28: operation timed out'));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new PreCheckService($clientService, $this->recorder);
		$answer = $service->ask(['url' => 'https://checker.example/ask', 'timeoutSeconds' => 5], []);

		$this->assertSame(PreCheckService::NO_ANSWER, $answer['decision']);
		$this->assertNotSame(PreCheckService::ALLOW, $answer['decision']);
		$this->assertStringContainsString('timed out', $answer['reason']);
		$this->assertNotSame([], $this->records, 'the attempt is recorded even when nobody answered');

	}//end testAPreCheckTimeoutIsNotPermission()

	/**
	 * An answer in a shape the pre-check does not recognise is no answer, not
	 * permission.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedAnswerIsNoAnswer(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"status":"maybe later"}');
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new PreCheckService($clientService, $this->recorder);
		$answer = $service->ask(['url' => 'https://checker.example/ask'], []);

		$this->assertSame(PreCheckService::NO_ANSWER, $answer['decision']);

	}//end testAnUnrecognisedAnswerIsNoAnswer()

	/**
	 * A replay service over the doubles.
	 *
	 * @param CallDispatcherInterface $dispatcher The dispatcher to use.
	 *
	 * @return CallReplayService The service.
	 */
	private function service(CallDispatcherInterface $dispatcher): CallReplayService {
		return new CallReplayService(
			$this->recorder,
			$dispatcher,
			new MappingVersionService($this->objectService),
			$this->objectService,
		);

	}//end service()

	/**
	 * A recorded call that failed.
	 *
	 * @param array<string,mixed> $policy The retry policy that governed it.
	 * @param array<string,mixed> $extra Anything else to record on it.
	 *
	 * @return string The call record uuid.
	 */
	private function failedCall(array $policy = [], array $extra = []): string {
		$entity = $this->recorder->record(
			array_merge(
				[
					'target' => 'stuf-partner',
					'traceId' => 'trace-1',
					'request' => ['method' => 'POST', 'endpoint' => '/stuf', 'body' => '<Lk01/>'],
					'response' => ['body' => '<Fo01/>'],
					'statusCode' => 500,
					'statusMessage' => 'Fout van de partner',
					'durationMs' => 340,
					'retryPolicy' => $policy,
				],
				$extra
			)
		);

		return (string)$entity->getUuid();

	}//end failedCall()

}//end class
