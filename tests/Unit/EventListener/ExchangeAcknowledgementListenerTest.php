<?php

/**
 * ExchangeAcknowledgementListener: an authority's retour reported against its job.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\ExchangeJobAcknowledgedEvent;
use OCA\Integriq\Event\OsoAcknowledgementReceivedEvent;
use OCA\Integriq\Event\RodAcknowledgementReceivedEvent;
use OCA\Integriq\Event\UwlrEduVAcknowledgementReceivedEvent;
use OCA\Integriq\Event\VerzuimloketAcknowledgementReceivedEvent;
use OCA\Integriq\EventListener\ExchangeAcknowledgementListener;
use OCA\Integriq\Service\Exchange\ExchangeErrorCodeCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-013 scenarios, through the real acknowledgement events and the real
 * rejection service over an in-memory store.
 */
class ExchangeAcknowledgementListenerTest extends TestCase {

	/**
	 * Stored objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Schema per stored uuid.
	 *
	 * @var array<string, string>
	 */
	private array $schemaOf = [];

	/**
	 * Events the listener dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * The listener under test.
	 *
	 * @var ExchangeAcknowledgementListener
	 */
	private ExchangeAcknowledgementListener $listener;

	/**
	 * Set up an in-memory store and a recording dispatcher.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null, ...$rest) {
				$uuid = $uuid ?? ($schema . '-' . (count($this->store) + 1));
				$this->store[$uuid] = $object;
				$this->schemaOf[$uuid] = (string)$schema;
				return $this->entity(uuid: $uuid, data: $object);
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ...$rest) {
				if (isset($this->store[$id]) === false) {
					throw new \RuntimeException('not found');
				}

				return $this->entity(uuid: (string)$id, data: $this->store[$id]);
			}
		);
		$objects->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$targets = new ExchangeTargetCatalogue();
		$jobs = new ExchangeJobService($objects, $targets, $logger);
		$rejections = new ExchangeRejectionService($objects, new ExchangeErrorCodeCatalogue($objects, $logger), $jobs);

		$this->listener = new ExchangeAcknowledgementListener(
			jobs: $jobs,
			rejections: $rejections,
			targets: $targets,
			dispatcher: $dispatcher,
			logger: $logger
		);

	}//end setUp()

	/**
	 * Build an entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * Store an exchange job.
	 *
	 * @param string $uuid     The job's uuid.
	 * @param string $target   Its target.
	 * @param string $ownerApp Its owning app.
	 *
	 * @return void
	 */
	private function job(string $uuid, string $target, string $ownerApp = 'learniq'): void {
		$this->store[$uuid] = [
			'name' => 'Exchange ' . $target,
			'ownerApp' => $ownerApp,
			'exchangeTarget' => $target,
			'exchangeDirection' => 'export',
			'exchangeStatus' => 'succeeded',
		];
		$this->schemaOf[$uuid] = 'job';

	}//end job()

	/**
	 * The rejections stored so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rejections(): array {
		$rows = [];
		foreach ($this->schemaOf as $uuid => $schema) {
			if ($schema === ExchangeRejectionService::SCHEMA) {
				$rows[] = $this->store[$uuid];
			}
		}

		return $rows;

	}//end rejections()

	/**
	 * DUO rejects a ROD bericht: one event for the job and record, one rejection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function testDuoRejectingARodBerichtIsReportedAndStoredAsARejection(): void {
		$this->job(uuid: '5f0c6a9e-2b1d-4c3e-9a7f-1d2e3f4a5b6c', target: 'bron-rod');

		$this->listener->handle(new RodAcknowledgementReceivedEvent('5f0c6a9e-2b1d-4c3e-9a7f-1d2e3f4a5b6c:lp-9', 'BRON-102', 'Onbekend BSN', false, 'inschrijving'));

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(ExchangeJobAcknowledgedEvent::class, $event);
		$this->assertSame('learniq', $event->getOwnerApp());
		$this->assertSame('5f0c6a9e-2b1d-4c3e-9a7f-1d2e3f4a5b6c', $event->getJobId());
		$this->assertSame('lp-9', $event->getRecordId());
		$this->assertSame('bron-rod', $event->getTarget());
		$this->assertFalse($event->isAccepted());
		$this->assertSame('BRON-102', $event->getSignaalcode());
		$this->assertSame('Onbekend BSN', $event->getDescription());
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $event->getReceivedAt()));

		$rows = $this->rejections();
		$this->assertCount(1, $rows);
		$this->assertSame('5f0c6a9e-2b1d-4c3e-9a7f-1d2e3f4a5b6c', $rows[0]['exchangeJob']);
		$this->assertSame('lp-9', $rows[0]['originId']);
		$this->assertSame('BRON-102', $rows[0]['errorCode']);
		$this->assertSame('learniq', $rows[0]['ownerApp']);
		$this->assertSame([], RegisterSchemaValidator::errors('sync_item_dead_letter', $rows[0]));

	}//end testDuoRejectingARodBerichtIsReportedAndStoredAsARejection()

	/**
	 * The Verzuimloket accepts a melding: an accepted event, nothing stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function testAnAcceptedVerzuimloketMeldingIsReportedAndStoresNothing(): void {
		$this->job(uuid: 'job-lp', target: 'leerplicht');

		$this->listener->handle(new VerzuimloketAcknowledgementReceivedEvent('job-lp:ar-1', '0000', null, true, 'eerste-melding'));

		$this->assertCount(1, $this->dispatched);
		$this->assertTrue($this->dispatched[0]->isAccepted());
		$this->assertSame('ar-1', $this->dispatched[0]->getRecordId());
		$this->assertSame('', $this->dispatched[0]->getDescription());
		$this->assertSame([], $this->rejections());

	}//end testAnAcceptedVerzuimloketMeldingIsReportedAndStoresNothing()

	/**
	 * OSO and UWLR retours reach the jobs their adapters carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function testOsoAndUwlrRetoursReachTheirJobs(): void {
		$this->job(uuid: 'job-oso', target: 'oso');
		$this->job(uuid: 'job-edu', target: 'edu-v');

		$this->listener->handle(new OsoAcknowledgementReceivedEvent('job-oso:d-1', 'OSO-1', 'Dossier onvolledig', false));
		$this->listener->handle(new UwlrEduVAcknowledgementReceivedEvent('job-edu:p-1', '0000', null, true));

		$this->assertSame(['oso', 'edu-v'], array_map(static fn (ExchangeJobAcknowledgedEvent $event): string => $event->getTarget(), $this->dispatched));
		$this->assertCount(1, $this->rejections());

	}//end testOsoAndUwlrRetoursReachTheirJobs()

	/**
	 * A kenmerk that is no exchange job, a job another adapter carries, a job
	 * without an owner, and a kenmerk without a record: nothing happens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function testARetourThatAnswersNoExchangeJobChangesNothing(): void {
		$this->job(uuid: 'job-oso', target: 'oso');
		$this->job(uuid: 'job-orphan', target: 'bron-rod', ownerApp: '');

		$this->listener->handle(new RodAcknowledgementReceivedEvent('ROD-2026-000123', 'BRON-102', 'x', false));
		$this->listener->handle(new RodAcknowledgementReceivedEvent('job-missing:lp-1', 'BRON-102', 'x', false));
		$this->listener->handle(new RodAcknowledgementReceivedEvent('job-oso:d-1', 'BRON-102', 'x', false));
		$this->listener->handle(new RodAcknowledgementReceivedEvent('job-orphan:lp-1', 'BRON-102', 'x', false));
		$this->listener->handle(new RodAcknowledgementReceivedEvent('job-oso:', 'OSO-1', 'x', false));
		$this->listener->handle(new Event());

		$this->assertSame([], $this->dispatched);
		$this->assertSame([], $this->rejections());

	}//end testARetourThatAnswersNoExchangeJobChangesNothing()

	/**
	 * The wiring, asserted from the caller: Application registers the
	 * listener for every event in ADAPTER_OF, and each of those events is one
	 * an adapter service actually dispatches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function testTheListenerIsRegisteredForEveryAdaptersAcknowledgement(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$application = (string)file_get_contents($lib . '/AppInfo/Application.php');
		$this->assertMatchesRegularExpression(
			'/foreach \(array_keys\(ExchangeAcknowledgementListener::ADAPTER_OF\) as \$acknowledgement\) \{\s*'
			. '\$context->registerEventListener\(\$acknowledgement, ExchangeAcknowledgementListener::class\);/',
			$application
		);

		$services = [
			'rod' => 'RodService.php',
			'verzuimloket' => 'VerzuimloketService.php',
			'oso' => 'OsoService.php',
			'uwlr-eduv' => 'UwlrEduVService.php',
		];
		$this->assertSame(array_keys($services), array_values(ExchangeAcknowledgementListener::ADAPTER_OF));
		foreach (ExchangeAcknowledgementListener::ADAPTER_OF as $class => $adapter) {
			$short = substr($class, (int)strrpos($class, '\\') + 1);
			$source = (string)file_get_contents($lib . '/Service/' . $services[$adapter]);
			$this->assertStringContainsString('new ' . $short . '(', $source, $short . ' is dispatched by ' . $services[$adapter]);
		}

	}//end testTheListenerIsRegisteredForEveryAdaptersAcknowledgement()
}//end class
