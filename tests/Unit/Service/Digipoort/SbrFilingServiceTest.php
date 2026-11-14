<?php

/**
 * Unit tests for SbrFilingService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-2-the-filing-consumer-and-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Digipoort;

use OCA\Integriq\Exception\DigipoortException;
use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\Integriq\Service\Digipoort\LogDigipoortProvider;
use OCA\Integriq\Service\Digipoort\SbrDelivery;
use OCA\Integriq\Service\Digipoort\SbrFilingService;
use OCA\Integriq\Service\Digipoort\WusDigipoortProvider;
use OCA\Integriq\Service\UserFilePayloadReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The filing lifecycle over an in-memory store of real ObjectEntity rows.
 */
#[CoversClass(SbrFilingService::class)]
#[UsesClass(SbrDelivery::class)]
#[UsesClass(LogDigipoortProvider::class)]
#[UsesClass(DigipoortException::class)]
class SbrFilingServiceTest extends TestCase {
	/**
	 * The XBRL instance the request names.
	 */
	private const INSTANCE = '<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance"/>';

	/**
	 * Rows by numeric id, as OpenRegister stores them.
	 *
	 * @var array<int,ObjectEntity>
	 */
	private array $rows = [];

	/**
	 * The schema slug of each row.
	 *
	 * @var array<int,string>
	 */
	private array $schemas = [];

	/**
	 * The emitted status events' data, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $emitted = [];

	/**
	 * The WUS provider double.
	 *
	 * @var WusDigipoortProvider&MockObject
	 */
	private WusDigipoortProvider&MockObject $wus;

	/**
	 * The payload reader double.
	 *
	 * @var UserFilePayloadReader&MockObject
	 */
	private UserFilePayloadReader&MockObject $reader;

	/**
	 * The service under test.
	 *
	 * @var SbrFilingService
	 */
	private SbrFilingService $service;

	/**
	 * Build the service over the in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objectService = $this->createMock(ORObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->findAll(config: $config)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (mixed $object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity
				=> $this->store(data: $object, schema: (string)$schema, uuid: $uuid)
		);

		$events = $this->createMock(CloudEventEmitterInterface::class);
		$events->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject, array $data): array {
				$this->emitted[] = ['type' => $type, 'subject' => $subject] + $data;
				return [];
			}
		);

		$this->wus = $this->createMock(WusDigipoortProvider::class);
		$this->reader = $this->createMock(UserFilePayloadReader::class);
		$this->reader->method('read')->willReturn(['content' => self::INSTANCE, 'name' => 'ob-2026-q3.xbrl']);

		$this->service = new SbrFilingService(
			$objectService,
			new LogDigipoortProvider(),
			$this->wus,
			$this->reader,
			$events,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A request on the log source stores one filing with a delivery reference and reports `delivered`.
	 *
	 * @return void
	 */
	public function testARequestBecomesOneDeliveredFiling(): void {
		$this->source(configuration: ['provider' => 'log']);

		$filing = $this->service->handleRequested(eventData: $this->request());

		$data = $filing->getObject();
		$this->assertSame('delivered', $data['status']);
		$this->assertStringStartsWith(LogDigipoortProvider::REFERENCE_PREFIX, (string)$data['deliveryReference']);
		$this->assertCount(1, $data['attempts']);
		$this->assertSame('', $data['attempts'][0]['error']);
		$this->assertSame(['delivered'], array_column($data['statusHistory'], 'status'));
		$this->assertCount(1, $this->filings());
		$this->assertSame(
			[
				[
					'type' => SbrFilingService::EVENT_TYPE_STATUS,
					'subject' => '/apps/shillinq/vat-returns/2026-q3',
					'objectUri' => '/apps/shillinq/vat-returns/2026-q3',
					'deliveryReference' => $data['deliveryReference'],
					'status' => 'delivered',
					'errors' => [],
				],
			],
			$this->emitted
		);
	}//end testARequestBecomesOneDeliveredFiling()

	/**
	 * The same request again delivers nothing and stores nothing new.
	 *
	 * @return void
	 */
	public function testTheSameRequestTwiceDeliversOnce(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->expects($this->once())->method('deliver')->willReturn('KNM-0001');

		$first = $this->service->handleRequested(eventData: $this->request());
		$second = $this->service->handleRequested(eventData: $this->request());

		$this->assertSame($first->getUuid(), $second->getUuid());
		$this->assertCount(1, $this->filings());
		$this->assertCount(1, $this->emitted);
	}//end testTheSameRequestTwiceDeliversOnce()

	/**
	 * The WUS provider receives the mapped berichtsoort, a stable kenmerk, the identity and the instance.
	 *
	 * @return void
	 */
	public function testTheDeliveryCarriesTheBerichtsoortIdentityAndInstance(): void {
		$this->source(configuration: ['provider' => 'wus', 'environment' => 'preproduction']);
		$this->wus->expects($this->once())->method('deliver')->with(
			['provider' => 'wus', 'environment' => 'preproduction'],
			$this->callback(
				function (SbrDelivery $delivery): bool {
					$this->assertSame('Omzetbelasting', $delivery->berichtsoort);
					$this->assertSame(
						$this->service->aanleverkenmerk(objectUri: '/apps/shillinq/vat-returns/2026-q3', filingType: 'ob-aangifte'),
						$delivery->aanleverkenmerk
					);
					$this->assertSame('001234567B01', $delivery->identityNumber);
					$this->assertSame('Fi', $delivery->identityType);
					$this->assertSame(self::INSTANCE, $delivery->instance);
					$this->assertSame('ob-2026-q3.xbrl', $delivery->fileName);
					return true;
				}
			)
		)->willReturn('KNM-0001');

		$filing = $this->service->handleRequested(eventData: $this->request());

		$this->assertSame('KNM-0001', $filing->getObject()['deliveryReference']);
	}//end testTheDeliveryCarriesTheBerichtsoortIdentityAndInstance()

	/**
	 * A request without a required field stores nothing.
	 *
	 * @return void
	 */
	public function testARequestWithoutAnIdentityIsIgnored(): void {
		$this->source(configuration: ['provider' => 'log']);
		$request = $this->request();
		unset($request['identityNumber']);

		$this->assertNull($this->service->handleRequested(eventData: $request));
		$this->assertSame([], $this->filings());
		$this->assertSame([], $this->emitted);
	}//end testARequestWithoutAnIdentityIsIgnored()

	/**
	 * A filing type the source has no berichtsoort for fails at once, without retries.
	 *
	 * @return void
	 */
	public function testAnUnknownFilingTypeFailsAtOnce(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->expects($this->never())->method('deliver');

		$filing = $this->service->handleRequested(eventData: $this->request(['filingType' => 'ib-aangifte']));

		$data = $filing->getObject();
		$this->assertSame('failed', $data['status']);
		$this->assertCount(1, $data['attempts']);
		$this->assertStringContainsString('no berichtsoort for filing type ib-aangifte', $data['errors'][0]['text']);
		$this->assertSame('failed', $this->emitted[0]['status']);
	}//end testAnUnknownFilingTypeFailsAtOnce()

	/**
	 * A failing delivery stays queued, is retried by the sweep, and is dead-lettered as failed when the budget is spent.
	 *
	 * @return void
	 */
	public function testAFailingDeliveryIsRetriedThenFailed(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->expects($this->exactly(SbrFilingService::MAX_ATTEMPTS))->method('deliver')
			->willThrowException(new DigipoortException(message: 'Digipoort could not be reached: timeout'));

		$filing = $this->service->handleRequested(eventData: $this->request());
		$this->assertSame('queued', $filing->getObject()['status']);
		$this->assertSame([], $this->emitted);

		$this->assertSame(1, $this->service->sweep()['retried']);
		$this->assertSame('queued', $this->filings()[0]->getObject()['status']);

		$this->assertSame(1, $this->service->sweep()['retried']);
		$data = $this->filings()[0]->getObject();
		$this->assertSame('failed', $data['status']);
		$this->assertCount(SbrFilingService::MAX_ATTEMPTS, $data['attempts']);
		$this->assertSame('', $data['deliveryReference']);
		$this->assertSame(['failed'], array_column($this->emitted, 'status'));

		// Dead-lettered: a further sweep and a repeated request deliver nothing.
		$this->assertSame(0, $this->service->sweep()['retried']);
		$this->service->handleRequested(eventData: $this->request());
		$this->assertCount(1, $this->emitted);
	}//end testAFailingDeliveryIsRetriedThenFailed()

	/**
	 * Without an enabled source nothing is delivered and the attempt is recorded.
	 *
	 * @return void
	 */
	public function testWithoutAnEnabledSourceTheFilingStaysQueued(): void {
		$this->source(configuration: ['provider' => 'log'], enabled: false);

		$data = $this->service->handleRequested(eventData: $this->request())->getObject();

		$this->assertSame('queued', $data['status']);
		$this->assertStringContainsString('No active Digipoort source', (string)$data['attempts'][0]['error']);
	}//end testWithoutAnEnabledSourceTheFilingStaysQueued()

	/**
	 * An unreadable instance is a failed attempt, not a delivery.
	 *
	 * @return void
	 */
	public function testAnUnreadableInstanceIsNotDelivered(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->reader = $this->createMock(UserFilePayloadReader::class);
		$this->reader->method('read')->willThrowException(new RuntimeException('payload not readable: /admin/files/x.xbrl'));
		$this->rebuildWithReader();
		$this->wus->expects($this->never())->method('deliver');

		$data = $this->service->handleRequested(eventData: $this->request())->getObject();

		$this->assertSame('queued', $data['status']);
		$this->assertSame('payload not readable: /admin/files/x.xbrl', $data['attempts'][0]['error']);
	}//end testAnUnreadableInstanceIsNotDelivered()

	/**
	 * A rejection is reported once with the recipient's codes, and the filing is no longer polled.
	 *
	 * @return void
	 */
	public function testARejectionIsReportedWithItsCodesAndEndsPolling(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->method('deliver')->willReturn('KNM-0001');
		$this->wus->expects($this->once())->method('statuses')->with($this->anything(), 'KNM-0001')->willReturn(
			[
				$this->channelStatus(status: 'delivered', code: '100'),
				$this->channelStatus(status: 'rejected', code: '510', errors: [['code' => 'OB0001', 'text' => 'Het tijdvak is al aangegeven.']]),
			]
		);
		$this->service->handleRequested(eventData: $this->request());

		$first = $this->service->sweep();
		$second = $this->service->sweep();

		$this->assertSame(['retried' => 0, 'polled' => 1, 'changed' => 1], $first);
		$this->assertSame(['retried' => 0, 'polled' => 0, 'changed' => 0], $second);
		$data = $this->filings()[0]->getObject();
		$this->assertSame('rejected', $data['status']);
		$this->assertSame(['delivered', 'rejected'], array_column($data['statusHistory'], 'status'));
		$this->assertSame('510', $data['statusHistory'][1]['code']);
		$this->assertSame(['delivered', 'rejected'], array_column($this->emitted, 'status'));
		$this->assertSame([['code' => 'OB0001', 'text' => 'Het tijdvak is al aangegeven.']], $this->emitted[1]['errors']);
		$this->assertSame('KNM-0001', $this->emitted[1]['deliveryReference']);
	}//end testARejectionIsReportedWithItsCodesAndEndsPolling()

	/**
	 * Statuses already read are not reported again, and a repeat of the current status is no change.
	 *
	 * @return void
	 */
	public function testOnlyNewStatusChangesAreReported(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->method('deliver')->willReturn('KNM-0001');
		$this->wus->method('statuses')->willReturnOnConsecutiveCalls(
			[$this->channelStatus(status: 'delivered', code: '100')],
			[$this->channelStatus(status: 'delivered', code: '100'), $this->channelStatus(status: 'delivered', code: '200')],
			[
				$this->channelStatus(status: 'delivered', code: '100'),
				$this->channelStatus(status: 'delivered', code: '200'),
				$this->channelStatus(status: 'accepted', code: '500'),
			]
		);
		$this->service->handleRequested(eventData: $this->request());

		$this->assertSame(0, $this->service->sweep()['changed']);
		$this->assertSame(1, $this->filings()[0]->getObject()['channelStatusCount']);
		$this->assertSame(0, $this->service->sweep()['changed']);
		$this->assertSame(2, $this->filings()[0]->getObject()['channelStatusCount']);
		$this->assertSame(1, $this->service->sweep()['changed']);

		$data = $this->filings()[0]->getObject();
		$this->assertSame('accepted', $data['status']);
		$this->assertSame(3, $data['channelStatusCount']);
		$this->assertSame(['delivered', 'accepted'], array_column($this->emitted, 'status'));
	}//end testOnlyNewStatusChangesAreReported()

	/**
	 * The first final status ends the filing: a status reported after it is ignored.
	 *
	 * @return void
	 */
	public function testNothingAfterAFinalStatusIsApplied(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->method('deliver')->willReturn('KNM-0001');
		$this->wus->method('statuses')->willReturn(
			[
				$this->channelStatus(status: 'accepted', code: '500'),
				$this->channelStatus(status: 'rejected', code: '510', errors: [['code' => 'X', 'text' => 'late']]),
			]
		);
		$this->service->handleRequested(eventData: $this->request());

		$this->assertSame(1, $this->service->sweep()['changed']);

		$data = $this->filings()[0]->getObject();
		$this->assertSame('accepted', $data['status']);
		$this->assertSame([], $data['errors']);
		$this->assertSame(1, $data['channelStatusCount']);
		$this->assertSame(['delivered', 'accepted'], array_column($this->emitted, 'status'));
	}//end testNothingAfterAFinalStatusIsApplied()

	/**
	 * A status poll that fails changes nothing and is tried on the next sweep.
	 *
	 * @return void
	 */
	public function testAFailedPollChangesNothing(): void {
		$this->source(configuration: ['provider' => 'wus']);
		$this->wus->method('deliver')->willReturn('KNM-0001');
		$this->wus->method('statuses')->willThrowException(new DigipoortException(message: 'Digipoort could not be reached'));
		$this->service->handleRequested(eventData: $this->request());

		$this->assertSame(['retried' => 0, 'polled' => 1, 'changed' => 0], $this->service->sweep());
		$this->assertSame('delivered', $this->filings()[0]->getObject()['status']);
	}//end testAFailedPollChangesNothing()

	/**
	 * The log provider's default script takes a delivered filing to accepted.
	 *
	 * @return void
	 */
	public function testTheLogSourceTakesAFilingToAccepted(): void {
		$this->source(configuration: ['provider' => 'log']);
		$this->service->handleRequested(eventData: $this->request());

		$this->service->sweep();

		$this->assertSame('accepted', $this->filings()[0]->getObject()['status']);
		$this->assertSame(['delivered', 'accepted'], array_column($this->emitted, 'status'));
	}//end testTheLogSourceTakesAFilingToAccepted()

	/**
	 * `wus` selects the WUS binding; anything else the log binding.
	 *
	 * @return void
	 */
	public function testTheProviderFollowsTheSourceConfiguration(): void {
		$this->assertSame($this->wus, $this->service->resolveProvider(configuration: ['provider' => 'wus']));
		$this->assertInstanceOf(LogDigipoortProvider::class, $this->service->resolveProvider(configuration: []));
		$this->assertInstanceOf(LogDigipoortProvider::class, $this->service->resolveProvider(configuration: ['provider' => 'rest']));
	}//end testTheProviderFollowsTheSourceConfiguration()

	/**
	 * The kenmerk is stable per object and filing type and differs between them.
	 *
	 * @return void
	 */
	public function testTheAanleverkenmerkIsStablePerObjectAndType(): void {
		$one = $this->service->aanleverkenmerk(objectUri: '/a/1', filingType: 'ob-aangifte');

		$this->assertSame($one, $this->service->aanleverkenmerk(objectUri: '/a/1', filingType: 'ob-aangifte'));
		$this->assertNotSame($one, $this->service->aanleverkenmerk(objectUri: '/a/1', filingType: 'kvk-deponering'));
		$this->assertMatchesRegularExpression('/^SBR-[0-9A-F]{16}$/', $one);
	}//end testTheAanleverkenmerkIsStablePerObjectAndType()

	/**
	 * Rebuild the service after replacing the reader double.
	 *
	 * @return void
	 */
	private function rebuildWithReader(): void {
		$objectService = $this->createMock(ORObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->findAll(config: $config)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (mixed $object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity
				=> $this->store(data: $object, schema: (string)$schema, uuid: $uuid)
		);
		$events = $this->createMock(CloudEventEmitterInterface::class);
		$this->service = new SbrFilingService(
			$objectService,
			new LogDigipoortProvider(),
			$this->wus,
			$this->reader,
			$events,
			$this->createMock(LoggerInterface::class)
		);
	}//end rebuildWithReader()

	/**
	 * A filing request as shillinq raises it.
	 *
	 * @param array<string,mixed> $override Fields to replace.
	 *
	 * @return array<string,mixed>
	 */
	private function request(array $override = []): array {
		return $override + [
			'sourceApp' => 'shillinq',
			'objectType' => 'VATReturn',
			'objectUri' => '/apps/shillinq/vat-returns/2026-q3',
			'filingType' => 'ob-aangifte',
			'recipient' => 'belastingdienst',
			'payloadFileUri' => '/admin/files/Belastingaangiften/ob-2026-q3.xbrl',
			'identityNumber' => '001234567B01',
			'identityType' => 'Fi',
		];
	}//end request()

	/**
	 * One channel status.
	 *
	 * @param string                               $status The status.
	 * @param string                               $code   The channel's code.
	 * @param list<array{code:string,text:string}> $errors The errors.
	 *
	 * @return array{status:string,code:string,description:string,at:string,errors:list<array{code:string,text:string}>}
	 */
	private function channelStatus(string $status, string $code, array $errors = []): array {
		return ['status' => $status, 'code' => $code, 'description' => '', 'at' => '2026-10-28T10:14:02+01:00', 'errors' => $errors];
	}//end channelStatus()

	/**
	 * Store a `digipoort-sbr` source.
	 *
	 * @param array<string,mixed> $configuration The source's configuration.
	 * @param boolean             $enabled       Whether it is enabled.
	 *
	 * @return void
	 */
	private function source(array $configuration, bool $enabled = true): void {
		$this->store(
			data: ['name' => 'Digipoort SBR', 'type' => 'digipoort-sbr', 'isEnabled' => $enabled, 'configuration' => $configuration],
			schema: 'source',
			uuid: null
		);
	}//end source()

	/**
	 * The stored filings.
	 *
	 * @return list<ObjectEntity>
	 */
	private function filings(): array {
		return array_values(
			array_filter(
				$this->rows,
				fn (ObjectEntity $row, int $id): bool => $this->schemas[$id] === 'sbr_filing',
				ARRAY_FILTER_USE_BOTH
			)
		);
	}//end filings()

	/**
	 * Save a row: replace the row with the uuid, or add one with the next numeric id.
	 *
	 * @param array<string,mixed> $data   The object.
	 * @param string              $schema The schema slug.
	 * @param string|null         $uuid   The uuid to replace, or null.
	 *
	 * @return ObjectEntity A fresh entity, as OpenRegister returns one.
	 */
	private function store(array $data, string $schema, ?string $uuid): ObjectEntity {
		$id = null;
		foreach ($this->rows as $rowId => $row) {
			if ($uuid !== null && $row->getUuid() === $uuid) {
				$id = $rowId;
			}
		}

		if ($id === null) {
			$id = (count($this->rows) + 1);
			$uuid = sprintf('00000000-0000-4000-8000-%012d', $id);
		}

		$entity = new ObjectEntity();
		$entity->setId($id);
		$entity->setUuid((string)$uuid);
		$entity->setObject($data);
		$this->rows[$id] = $entity;
		$this->schemas[$id] = $schema;

		return clone $entity;
	}//end store()

	/**
	 * Answer a findAll by matching every filter except the register.
	 *
	 * @param array<string,mixed> $config The findAll config.
	 *
	 * @return array{results:list<ObjectEntity>}
	 */
	private function findAll(array $config): array {
		$filters = (array)($config['filters'] ?? []);
		$this->assertSame('integriq', $filters['register'] ?? null);
		$schema = (string)($filters['schema'] ?? '');
		unset($filters['register'], $filters['schema']);

		$results = [];
		foreach ($this->rows as $id => $row) {
			if ($this->schemas[$id] !== $schema) {
				continue;
			}

			$data = $row->getObject();
			foreach ($filters as $field => $value) {
				if (($data[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$results[] = clone $row;
		}

		return ['results' => array_slice($results, 0, (int)($config['limit'] ?? 100))];
	}//end findAll()
}//end class
