<?php

/**
 * A push with `targetConfig.zgwDocument` delivers through ZgwDocumentDelivery.
 *
 * Driven through the real SynchronizationService::updateTarget() with a real
 * ZgwDocumentDelivery over a recording transport, so the wiring is asserted
 * from the caller: the source object's file goes up in the parts the
 * Documenten API names, the case relation is made from the object's
 * `zaakUrl`, the document url becomes the contract's target id and is
 * written back, and a contract that already holds a document sends nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\CaseSystem\ZgwDocumentDelivery;
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
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class SynchronizationZgwDocumentPushTest extends TestCase {

	private const DRC = 'https://open-zaak.example.nl/documenten/api/v1';

	private const DOCUMENT = self::DRC . '/enkelvoudiginformatieobjecten/5d1e9a40-2c7b-4f1e-8a3d-6b2c1d0e9f87';

	private const ZAAK = 'https://open-zaak.example.nl/zaken/api/v1/zaken/0f3b8c2e-9a41-4d6e-b5c7-1e2d3f4a5b6c';

	private const DELIVERY = [
		'titel' => 'Bevestiging indiensttreding',
		'zaakUrl' => self::ZAAK,
		'status' => 'ready_for_writeback',
	];

	private array $sent = [];

	private array $saves = [];

	private array $httpCalls = [];

	private int $createStatus = 201;

	/**
	 * The push synchronization.
	 *
	 * @return array
	 */
	private static function push(): array {
		return [
			'id' => 'push-uuid',
			'sourceType' => 'register/schema',
			'sourceId' => 'filinq/caseSystemDelivery',
			'targetType' => 'api',
			'targetId' => 'drc-uuid',
			'targetConfig' => ['endpoint' => '/enkelvoudiginformatieobjecten', 'zgwDocument' => ['zakenSource' => 'zrc-uuid']],
			'writeBack' => [
				'onSuccess' => ['status' => 'written_back', 'resultExternalId' => '{{ response.url }}'],
				'onFailure' => ['status' => 'writeback_failed', 'writeBackError' => '{{ error.message }}'],
			],
		];
	}//end push()

	/**
	 * The service over recording fakes.
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
					'push-uuid' => self::push(),
					'delivery-1' => self::DELIVERY,
					default => ['location' => self::DRC, 'name' => 'Documenten API'],
				});
				return $entity;
			}
		);
		$or->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false) {
				$this->saves[] = ['object' => $object, 'uuid' => $uuid, 'silent' => $silent];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$calls->method('call')->willReturnCallback(
			function ($source, string $endpoint='', string $method='GET') {
				$this->httpCalls[] = [$method, $endpoint];
				throw new \RuntimeException('A ZGW document push must not make the generic JSON call.');
			}
		);

		$transport = $this->createMock(CallServiceCaseSystemTransport::class);
		$transport->method('send')->willReturnCallback(
			function (string $source, string $method, string $address, array $options = []): array {
				$this->sent[] = ['source' => $source, 'method' => $method, 'address' => $address, 'options' => $options];
				$data = match (true) {
					$method === 'POST' && $address === '/enkelvoudiginformatieobjecten' => [
						'url' => self::DOCUMENT,
						'lock' => 'lock-1',
						'bestandsdelen' => [
							['url' => self::DRC . '/bestandsdelen/b1', 'volgnummer' => 1, 'omvang' => 3],
							['url' => self::DRC . '/bestandsdelen/b2', 'volgnummer' => 2, 'omvang' => 2],
						],
					],
					$address === '/zaakinformatieobjecten' => ['url' => 'https://open-zaak.example.nl/zaken/api/v1/zaakinformatieobjecten/7'],
					default => null,
				};
				$status = 200;
				if ($method === 'POST' && $address === '/enkelvoudiginformatieobjecten') {
					$status = $this->createStatus;
					if ($status >= 300) {
						$data = ['detail' => 'informatieobjecttype is niet gepubliceerd'];
					}
				}

				return ['status' => $status, 'data' => $data, 'raw' => (string)json_encode($data)];
			}
		);

		$file = new class {
			public function getContent(): string {
				return 'PDF!!';
			}

			public function getName(): string {
				return 'bevestiging.pdf';
			}

			public function getMimeType(): string {
				return 'application/pdf';
			}
		};
		$fileService = new class($file) {
			public function __construct(private object $file) {
			}

			public function getFiles(mixed $object): array {
				return [$this->file];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => match ($id) {
				ZgwDocumentDelivery::class => new ZgwDocumentDelivery(transport: $transport),
				'OCA\OpenRegister\Service\FileService' => $fileService,
				'OCA\OpenRegister\Service\ObjectService' => $or,
				// The constructor's own lookups get what an unconfigured container mock gives.
				default => null,
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$sourceMapping = $this->createMock(ObjectService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($or);

		return new SynchronizationService(
			$calls,
			$this->createMock(MappingService::class),
			$container,
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
	 * @param string|null $targetId The document url the contract already holds.
	 *
	 * @return array The contract.
	 */
	private function pushOnce(?string $targetId=null): array {
		$mapped = self::DELIVERY;
		return $this->service()->updateTarget(
			synchronizationContract: ['synchronizationId' => 'push-uuid', 'originId' => 'delivery-1', 'targetId' => $targetId],
			targetObject: $mapped
		);
	}//end pushOnce()

	/**
	 * An HR letter lands in the case file: created, two parts, unlocked, related, written back.
	 *
	 * @return void
	 */
	public function testALetterLandsInTheCaseFileAndTheDeliveryIsWrittenBack(): void {
		$contract = $this->pushOnce();

		$this->assertSame(self::DOCUMENT, $contract['targetId']);
		$this->assertSame([], $this->httpCalls);
		$this->assertSame(
			['POST /enkelvoudiginformatieobjecten', 'PUT ' . self::DRC . '/bestandsdelen/b1', 'PUT ' . self::DRC . '/bestandsdelen/b2', 'POST ' . self::DOCUMENT . '/unlock', 'POST /zaakinformatieobjecten'],
			array_map(static fn (array $request): string => $request['method'] . ' ' . $request['address'], $this->sent)
		);
		$this->assertSame('drc-uuid', $this->sent[0]['source']);
		$create = $this->sent[0]['options']['json'];
		$this->assertSame('bevestiging.pdf', $create['bestandsnaam']);
		$this->assertSame('application/pdf', $create['formaat']);
		$this->assertSame(5, $create['bestandsomvang']);
		$this->assertSame('PDF', $this->sent[1]['options']['multipart'][0]['contents']);
		$this->assertSame('!!', $this->sent[2]['options']['multipart'][0]['contents']);
		$this->assertSame(['informatieobject' => self::DOCUMENT, 'zaak' => self::ZAAK, 'titel' => 'Bevestiging indiensttreding'], $this->sent[4]['options']['json']);
		$this->assertSame('zrc-uuid', $this->sent[4]['source']);

		$this->assertCount(1, $this->saves);
		$this->assertTrue($this->saves[0]['silent']);
		$this->assertSame('written_back', $this->saves[0]['object']['status']);
		$this->assertSame(self::DOCUMENT, $this->saves[0]['object']['resultExternalId']);
	}//end testALetterLandsInTheCaseFileAndTheDeliveryIsWrittenBack()

	/**
	 * A delivery that already has its document never updates or replaces it.
	 *
	 * @return void
	 */
	public function testADeliveryWithADocumentSendsNothing(): void {
		$contract = $this->pushOnce(targetId: self::DOCUMENT);

		$this->assertSame(self::DOCUMENT, $contract['targetId']);
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->httpCalls);
		$this->assertSame([], $this->saves);
	}//end testADeliveryWithADocumentSendsNothing()

	/**
	 * A refused create writes the case system's message back once and rethrows.
	 *
	 * @return void
	 */
	public function testARefusedCreateIsWrittenBackAsFailed(): void {
		$this->createStatus = 400;

		try {
			$this->pushOnce();
			$this->fail('A refused create must fail the push.');
		} catch (CaseSystemRefusal $refusal) {
			$this->assertSame(400, $refusal->getStatus());
		}

		$this->assertCount(1, $this->saves);
		$this->assertSame('writeback_failed', $this->saves[0]['object']['status']);
		$this->assertSame('Documenten API answered 400: informatieobjecttype is niet gepubliceerd', $this->saves[0]['object']['writeBackError']);
	}//end testARefusedCreateIsWrittenBackAsFailed()
}//end class
