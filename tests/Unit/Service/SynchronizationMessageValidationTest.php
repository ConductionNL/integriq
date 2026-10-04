<?php

/**
 * Integriq SynchronizationMessageValidationTest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\SynchronizationMessageGate;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A synchronization checks each source object before it is mapped and each
 * target body before it is sent (REQ-MSV-003). Drives the real synchronize()
 * loop with the real gate, the real checkers, the real dead-letter service and
 * the real run-log service over the seeded `example-person-json` and
 * `example-person-xsd` message schemas. Only the fetch, the contract step and
 * the HTTP client are stand-ins.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */
class SynchronizationMessageValidationTest extends TestCase {

	private const JSON_SCHEMA_UUID = 'msg-person-json';

	private const XSD_UUID = 'msg-person-xsd';

	/**
	 * Objects the run handed to the contract step (the mapping and write).
	 *
	 * @var list<array>
	 */
	private array $written = [];

	/**
	 * Dead-letter entries the run saved.
	 *
	 * @var list<array>
	 */
	private array $deadLetters = [];

	/**
	 * Run logs the run saved, in order.
	 *
	 * @var list<array>
	 */
	private array $logs = [];

	/**
	 * Bodies sent to the target.
	 *
	 * @var list<array>
	 */
	private array $sent = [];

	/**
	 * Message schema reads.
	 *
	 * @var int
	 */
	private int $schemaReads = 0;

	/**
	 * The seeded message schemas from the register fragment, keyed by uuid.
	 *
	 * @return array<string, array>
	 */
	private function seededMessageSchemas(): array {
		$path     = dirname(__DIR__, 3) . '/lib/Settings/register.d/mapping-message-schema-validation.json';
		$fragment = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		$seeds    = [];
		foreach ($fragment['components']['objects'] as $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$seeds[$self['slug']] = $object;
		}

		return [
			self::JSON_SCHEMA_UUID => $seeds['example-person-json'],
			self::XSD_UUID => $seeds['example-person-xsd'],
		];
	}//end seededMessageSchemas()

	/**
	 * Ten people, the fourth without a geslachtsnaam.
	 *
	 * @return list<array>
	 */
	private function tenPeople(): array {
		$people = [];
		for ($i = 1; $i <= 10; $i++) {
			$people[] = ['id' => 'p' . $i, 'bsn' => sprintf('%09d', 100000000 + $i), 'geslachtsnaam' => 'Jansen'];
		}

		unset($people[3]['geslachtsnaam']);

		return array_values($people);
	}//end tenPeople()

	/**
	 * Build the service with the real gate, or without one.
	 *
	 * @param list<array> $objects  What the source fetch returns.
	 * @param bool        $withGate Whether the container answers with the gate.
	 *
	 * @return SynchronizationService
	 */
	private function service(array $objects, bool $withGate = true): SynchronizationService {
		$logger  = new NullLogger();
		$schemas = $this->seededMessageSchemas();

		$orObjects = ObjectServiceMockBuilder::make($this);
		$orObjects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) use ($schemas): ?ObjectEntity {
				if ($schema === 'message_schema') {
					$this->schemaReads++;
					if (isset($schemas[$id]) === false) {
						return null;
					}

					return ObjectServiceMockBuilder::objectEntity($this, $schemas[$id], (string)$id);
				}

				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'target-1', 'location' => 'https://doel.example.nl', 'isEnabled' => true], 'target-1');
				}

				return null;
			}
		);
		$orObjects->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$orObjects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ...$rest): ObjectEntity {
				if ($schema === 'sync_item_dead_letter') {
					$this->deadLetters[] = (array)$object;
				}

				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, 'saved-uuid');
			}
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);
		$callService->method('call')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->sent[] = ($args['config']['json'] ?? ($args[3]['json'] ?? []));
				$callLog = new ObjectEntity();
				$callLog->setUuid('call-log-' . count($this->sent));
				$callLog->setObject(['response' => ['statusCode' => 201, 'body' => '{"id":"t-' . count($this->sent) . '"}']]);
				return $callLog;
			}
		);

		$json = new JsonSchemaChecker();
		$gate = new SynchronizationMessageGate(
			new EndpointMessageGate(
				new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json)),
				$orObjects,
				$logger
			),
			$logger
		);

		$container  = $this->createMock(ContainerInterface::class);
		$deadLetter = new SyncItemDeadLetterService($orObjects, $container, $logger);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				SyncItemDeadLetterService::class => $deadLetter,
				SynchronizationMessageGate::class => ($withGate === true ? $gate : null),
				default => null,
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$logOrService->method('saveObject')->willReturnCallback(
			function ($object, ...$rest): ObjectEntity {
				$this->logs[] = (array)$object;
				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, 'log-uuid');
			}
		);
		$logService = new SynchronizationLogService($logOrService, $this->createMock(IUserSession::class), $this->createMock(ISession::class));

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->createMock(MappingService::class),
					$container,
					$orObjects,
					$this->createMock(ObjectService::class),
					$logger,
					$logService,
					$appConfig,
					$this->createMock(\OCA\Integriq\Service\SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['getAllObjectsFromSource', 'synchronizeContract'])
			->getMock();

		$service->method('getAllObjectsFromSource')->willReturn($objects);

		// The contract step: what the real one does at its end, for an `api`
		// target, is updateTarget() with the mapped object. The mapping here is
		// the identity, so the target body is the source object.
		$service->method('synchronizeContract')->willReturnCallback(
			function (array $synchronizationContract, $flowToken, ?array $synchronization = null, array &$object = []) use (&$service): array {
				$this->written[] = $object;
				$contract        = $synchronizationContract;
				if (($synchronization['targetType'] ?? null) === 'api') {
					$targetObject = $object;
					$contract     = $service->updateTarget(
						synchronizationContract: $synchronizationContract,
						targetObject: $targetObject,
						synchronization: $synchronization
					);
				}

				$contract['uuid'] = 'contract-' . count($this->written);
				return ['contract' => $contract, 'log' => null, 'resultAction' => 'create'];
			}
		);

		return $service;
	}//end service()

	/**
	 * A synchronization with the given source and target validation.
	 *
	 * @param array|null $source     The sourceConfig.validation block.
	 * @param array|null $target     The targetConfig.validation block.
	 * @param string     $targetType The target type.
	 *
	 * @return array
	 */
	private function synchronization(?array $source, ?array $target = null, string $targetType = 'register/schema'): array {
		$synchronization = [
			'id' => 'sync-people',
			'uuid' => 'sync-people',
			'name' => 'People',
			'sourceId' => 'source-1',
			'sourceType' => 'api',
			'targetType' => $targetType,
			'targetId' => 'target-1',
			'sourceConfig' => [],
			'targetConfig' => ['endpoint' => '/personen'],
		];
		if ($source !== null) {
			$synchronization['sourceConfig']['validation'] = $source;
		}

		if ($target !== null) {
			$synchronization['targetConfig']['validation'] = $target;
		}

		return $synchronization;
	}//end synchronization()

	/**
	 * The result block of the last run log the run saved.
	 *
	 * @return array
	 */
	private function lastLogResult(): array {
		$this->assertNotEmpty($this->logs, 'No run log was saved.');
		return (array)(end($this->logs)['result'] ?? []);
	}//end lastLogResult()

	/**
	 * Mode refuse: nine objects are written, the one without a geslachtsnaam is dead-lettered with the error.
	 *
	 * @return void
	 */
	public function testOneBadSourceObjectIsDeadLetteredAndTheOtherNineAreWritten(): void {
		$service = $this->service(objects: $this->tenPeople());

		$result = $service->synchronize(
			synchronization: $this->synchronization(source: ['mode' => 'refuse', 'messageSchema' => self::JSON_SCHEMA_UUID])
		);

		$this->assertCount(9, $this->written, 'Not nine objects reached the mapping.');
		$this->assertNotContains('p4', array_column($this->written, 'id'), 'The invalid object was mapped.');
		$this->assertSame(9, $result['result']['objects']['created']);
		$this->assertSame(1, $result['result']['objects']['invalid']);
		$this->assertCount(1, $this->deadLetters, 'The invalid object was not dead-lettered.');
		$this->assertSame('p4', $this->deadLetters[0]['payload']['id'] ?? null);
		$this->assertStringContainsString('geslachtsnaam', (string)$this->deadLetters[0]['error']);
		$this->assertStringContainsString(self::JSON_SCHEMA_UUID, (string)$this->deadLetters[0]['error']);
	}//end testOneBadSourceObjectIsDeadLetteredAndTheOtherNineAreWritten()

	/**
	 * Mode record: all ten are written, nothing is dead-lettered, and the run log names the finding.
	 *
	 * @return void
	 */
	public function testRecordModeWritesAllTenAndTheRunLogCarriesTheFinding(): void {
		$service = $this->service(objects: $this->tenPeople());

		$result = $service->synchronize(
			synchronization: $this->synchronization(source: ['mode' => 'record', 'messageSchema' => self::JSON_SCHEMA_UUID])
		);

		$this->assertCount(10, $this->written);
		$this->assertSame(10, $result['result']['objects']['created']);
		$this->assertSame([], $this->deadLetters);

		$findings = ($this->lastLogResult()['validation'] ?? []);
		$this->assertCount(1, $findings, 'The run log does not carry exactly one finding.');
		$this->assertSame('source', $findings[0]['side']);
		$this->assertSame('p4', $findings[0]['originId']);
		$this->assertSame(self::JSON_SCHEMA_UUID, $findings[0]['messageSchema']);
		$this->assertStringContainsString('geslachtsnaam', json_encode($findings[0]['errors']));
	}//end testRecordModeWritesAllTenAndTheRunLogCarriesTheFinding()

	/**
	 * Mode refuse on the target: the invalid body is not sent and the item is dead-lettered.
	 *
	 * @return void
	 */
	public function testAnInvalidTargetBodyIsNotSentAndIsDeadLettered(): void {
		$service = $this->service(objects: $this->tenPeople());

		$result = $service->synchronize(
			synchronization: $this->synchronization(
				source: null,
				target: ['mode' => 'refuse', 'messageSchema' => self::JSON_SCHEMA_UUID],
				targetType: 'api'
			)
		);

		$this->assertCount(9, $this->sent, 'Not nine bodies were sent.');
		$this->assertNotContains('p4', array_column($this->sent, 'id'), 'The invalid body was sent.');
		$this->assertSame(1, $result['result']['objects']['invalid']);
		$this->assertCount(1, $this->deadLetters);
		$this->assertStringContainsString('geslachtsnaam', (string)$this->deadLetters[0]['error']);
	}//end testAnInvalidTargetBodyIsNotSentAndIsDeadLettered()

	/**
	 * Mode refuse with an XSD target: the rendered JSON body does not match, so nothing is sent.
	 *
	 * @return void
	 */
	public function testAnXsdTargetThatTheBodyDoesNotMatchSendsNothing(): void {
		$service = $this->service(objects: array_slice($this->tenPeople(), 0, 2));

		$result = $service->synchronize(
			synchronization: $this->synchronization(
				source: null,
				target: ['mode' => 'refuse', 'messageSchema' => self::XSD_UUID],
				targetType: 'api'
			)
		);

		$this->assertSame([], $this->sent, 'A body was sent.');
		$this->assertSame(2, $result['result']['objects']['invalid']);
		$this->assertCount(2, $this->deadLetters);
	}//end testAnXsdTargetThatTheBodyDoesNotMatchSendsNothing()

	/**
	 * Mode record on the target: every body is sent, and the run log names the finding.
	 *
	 * @return void
	 */
	public function testRecordModeSendsEveryTargetBodyAndTheRunLogCarriesTheFinding(): void {
		$service = $this->service(objects: $this->tenPeople());

		$result = $service->synchronize(
			synchronization: $this->synchronization(
				source: null,
				target: ['mode' => 'record', 'messageSchema' => self::JSON_SCHEMA_UUID],
				targetType: 'api'
			)
		);

		$this->assertCount(10, $this->sent);
		$this->assertSame(10, $result['result']['objects']['created']);
		$this->assertSame([], $this->deadLetters);

		$findings = ($this->lastLogResult()['validation'] ?? []);
		$this->assertCount(1, $findings);
		$this->assertSame('target', $findings[0]['side']);
		$this->assertStringContainsString('geslachtsnaam', json_encode($findings[0]['errors']));
	}//end testRecordModeSendsEveryTargetBodyAndTheRunLogCarriesTheFinding()

	/**
	 * A declared validation is never skipped: without the gate, mode refuse dead-letters every object.
	 *
	 * @return void
	 */
	public function testADeclaredValidationWithoutTheGateIsRefusedNotSkipped(): void {
		$service = $this->service(objects: array_slice($this->tenPeople(), 0, 3), withGate: false);

		$result = $service->synchronize(
			synchronization: $this->synchronization(source: ['mode' => 'refuse', 'messageSchema' => self::JSON_SCHEMA_UUID])
		);

		$this->assertSame([], $this->written, 'An object was written unchecked.');
		$this->assertSame(3, $result['result']['objects']['invalid']);
		$this->assertCount(3, $this->deadLetters);
	}//end testADeclaredValidationWithoutTheGateIsRefusedNotSkipped()

	/**
	 * A synchronization without validation is unchanged: all ten written, no message schema read.
	 *
	 * @return void
	 */
	public function testASynchronizationWithoutValidationIsUnchanged(): void {
		$service = $this->service(objects: $this->tenPeople());

		$result = $service->synchronize(synchronization: $this->synchronization(source: null, target: null, targetType: 'api'));

		$this->assertCount(10, $this->written);
		$this->assertCount(10, $this->sent);
		$this->assertSame(10, $result['result']['objects']['created']);
		$this->assertSame(0, $this->schemaReads);
		$this->assertArrayNotHasKey('validation', $this->lastLogResult());
	}//end testASynchronizationWithoutValidationIsUnchanged()
}//end class
