<?php

/**
 * A push writes its outcome back onto the object that started it, silently and once per attempt.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-push-writes-its-outcome-back-onto-the-object-that-started-it-req-csd-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The real write path (updateTarget, writeObjectToTarget) over a mocked transport and OpenRegister.
 *
 * CallService::call() runs the source's whole retry budget before it returns
 * its call log, so the answer these tests feed it is the final answer of the
 * attempt: the write-back sees each attempt once.
 */
class SynchronizationOutcomeWriteBackTest extends TestCase {

	private const DOCUMENT = 'https://open-zaak.example.nl/documenten/api/v1/enkelvoudiginformatieobjecten/7c1e0d5a-3b2f-4e6a-9d8c-2a1b3c4d5e6f';

	/**
	 * The filinq delivery that started the push, as OpenRegister holds it.
	 *
	 * @var array<string, mixed>
	 */
	private const DELIVERY = [
		'titel' => 'Besluit 2026-14',
		'informatieobjecttype' => 'https://open-zaak.example.nl/catalogi/api/v1/informatieobjecttypen/1',
		'processingStatus' => 'ready_for_writeback',
	];

	/**
	 * The push synchronization.
	 *
	 * @var array<string, mixed>
	 */
	private array $push = [];

	/**
	 * Every save of a local object.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The calls the target received: method, endpoint.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $calls = [];

	private int $answer = 201;

	private string $answerBody = '{"url":"' . self::DOCUMENT . '"}';

	private ?\Throwable $transportFailure = null;

	protected function setUp(): void {
		$this->push = [
			'id' => 'push-uuid',
			'name' => 'filinq deliveries to the case system',
			'sourceType' => 'register/schema',
			'sourceId' => 'filinq/caseSystemDelivery',
			'targetType' => 'api',
			'targetId' => 'documenten-uuid',
			'targetConfig' => ['endpoint' => '/enkelvoudiginformatieobjecten', 'idPosition' => 'url'],
			'writeBack' => [
				'onSuccess' => ['processingStatus' => 'written_back', 'resultExternalId' => '{{ response.url }}'],
				'onFailure' => ['processingStatus' => 'writeback_failed', 'writeBackError' => '{{ error.message }}'],
			],
		];
	}//end setUp()

	/**
	 * The engine over a transport that answers $this->answer.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$or = $this->createMock(ORObjectService::class);
		$or->method('find')->willReturnCallback(
			function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(match ((string)$id) {
					'push-uuid' => $this->push,
					'delivery-1' => self::DELIVERY,
					default => ['location' => 'https://open-zaak.example.nl/documenten/api/v1', 'name' => 'Documenten API'],
				});
				return $entity;
			}
		);
		$or->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false) {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint='', string $method='GET') {
				$this->calls[] = [$method, $endpoint];
				if ($this->transportFailure !== null) {
					throw $this->transportFailure;
				}

				$log = new ObjectEntity();
				$log->setObject(['statusCode' => $this->answer, 'response' => ['statusCode' => $this->answer, 'body' => $this->answerBody]]);
				return $log;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$sourceMapping = $this->createMock(ObjectService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($or);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$this->createMock(ContainerInterface::class),
			$or,
			$sourceMapping,
			$this->createMock(LoggerInterface::class),
			$this->createMock(SynchronizationLogService::class),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * Push the delivery once.
	 *
	 * @param string|null $targetId The remote id the contract already holds.
	 *
	 * @return array The contract.
	 */
	private function push(?string $targetId=null): array {
		$mapped = ['titel' => 'Besluit 2026-14'];
		return $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'delivery-1', 'targetId' => $targetId],
			targetObject: $mapped
		);
	}//end push()

	/**
	 * A delivery the case system accepts carries written_back and the document's url, saved silently.
	 *
	 * @return void
	 */
	public function testAnAcceptedDeliveryIsMarkedWrittenBackWithTheDocumentUrl(): void {
		$contract = $this->push();

		$this->assertSame(self::DOCUMENT, $contract['targetId']);
		$this->assertCount(1, $this->calls);
		$this->assertCount(1, $this->saves);
		$save = $this->saves[0];
		$this->assertSame(['filinq', 'caseSystemDelivery', 'delivery-1'], [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertTrue($save['silent'], 'A normal save fires the object event, which would push the delivery again.');
		$this->assertSame(
			['processingStatus' => 'written_back', 'resultExternalId' => self::DOCUMENT] + self::DELIVERY,
			$save['object'],
			'The stored delivery keeps its own fields; only the outcome is added.'
		);
	}//end testAnAcceptedDeliveryIsMarkedWrittenBackWithTheDocumentUrl()

	/**
	 * A refusal writes the failure fields once, with the case system's own message, and still fails the push.
	 *
	 * @return void
	 */
	public function testARefusedDeliveryIsMarkedFailedOnceWithTheCaseSystemsMessage(): void {
		$this->answer     = 400;
		$this->answerBody = '{"title":"Invalid input.","detail":"Het informatieobjecttype is niet gepubliceerd."}';

		try {
			$this->push();
			$this->fail('A refused create must still fail the push.');
		} catch (\Exception $e) {
			$this->assertStringContainsString('Could not determine an id', $e->getMessage());
		}

		$this->assertCount(1, $this->saves, 'Written once per finished attempt.');
		$this->assertTrue($this->saves[0]['silent']);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['processingStatus']);
		$this->assertSame('Het informatieobjecttype is niet gepubliceerd.', $this->saves[0]['object']['writeBackError']);
		$this->assertArrayNotHasKey('resultExternalId', $this->saves[0]['object']);
	}//end testARefusedDeliveryIsMarkedFailedOnceWithTheCaseSystemsMessage()

	/**
	 * An answer without a message names its status.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAMessageNamesTheStatus(): void {
		$this->answer     = 503;
		$this->answerBody = '';

		try {
			$this->push();
		} catch (\Exception) {
			// The push fails as before; the write-back is what is checked.
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('The target answered HTTP 503.', $this->saves[0]['object']['writeBackError']);
	}//end testAFailureWithoutAMessageNamesTheStatus()

	/**
	 * A transport failure after the retries is written as a failure and rethrown.
	 *
	 * @return void
	 */
	public function testATransportFailureIsWrittenAndRethrown(): void {
		$this->transportFailure = new \RuntimeException('cURL error 28: Connection timed out');

		try {
			$this->push();
			$this->fail('The transport failure must reach the caller.');
		} catch (\RuntimeException $e) {
			$this->assertSame('cURL error 28: Connection timed out', $e->getMessage());
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['processingStatus']);
		$this->assertSame('cURL error 28: Connection timed out', $this->saves[0]['object']['writeBackError']);
	}//end testATransportFailureIsWrittenAndRethrown()

	/**
	 * An update of an existing document writes success with the target id.
	 *
	 * @return void
	 */
	public function testAnUpdateWritesSuccessWithTheTargetId(): void {
		$this->answer = 200;
		$this->push['writeBack']['onSuccess']['resultExternalId'] = '{{ targetId }}';

		$this->push(targetId: 'eio-42');

		$this->assertSame('PUT', $this->calls[0][0]);
		$this->assertCount(1, $this->saves);
		$this->assertSame('eio-42', $this->saves[0]['object']['resultExternalId']);
		$this->assertSame('written_back', $this->saves[0]['object']['processingStatus']);
	}//end testAnUpdateWritesSuccessWithTheTargetId()

	/**
	 * A push without write-back writes nothing onto its source, as before.
	 *
	 * @return void
	 */
	public function testAPushWithoutWriteBackWritesNothing(): void {
		unset($this->push['writeBack']);

		$this->push();

		$this->assertCount(1, $this->calls);
		$this->assertSame([], $this->saves);
	}//end testAPushWithoutWriteBackWritesNothing()

	/**
	 * A source that is not an OpenRegister object has nothing to write back onto.
	 *
	 * @return void
	 */
	public function testAnApiSourceGetsNoWriteBack(): void {
		$this->push['sourceType'] = 'api';
		$this->push['sourceId']   = 'some-source';

		$this->push();

		$this->assertSame([], $this->saves);
	}//end testAnApiSourceGetsNoWriteBack()

	/**
	 * Both registers declare writeBack on the synchronization, so a stored value survives a save.
	 *
	 * @return void
	 */
	public function testBothRegistersDeclareWriteBack(): void {
		foreach (['integriq_register.json', 'integriq_mock_register.json'] as $file) {
			$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/' . $file), true, flags: JSON_THROW_ON_ERROR);
			$schema   = $register['components']['schemas']['synchronization'];
			$this->assertSame('object', ($schema['properties']['writeBack']['type'] ?? null), $file);
			$this->assertSame('object', ($schema['properties']['writeBack']['properties']['onSuccess']['type'] ?? null), $file);
			$this->assertSame('object', ($schema['properties']['writeBack']['properties']['onFailure']['type'] ?? null), $file);
			$this->assertSame('1.2.0', $schema['version'], $file);
		}
	}//end testBothRegistersDeclareWriteBack()
}//end class
