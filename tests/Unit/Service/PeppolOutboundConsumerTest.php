<?php

/**
 * Unit tests for PeppolOutboundConsumer.
 *
 * OpenRegister stamps the numeric ids of the register and schema onto every
 * ObjectEntity it emits, and ObjectEntity declares getRegister()/getSchema()
 * concretely. These tests build the event exactly that way (a real
 * ObjectCreatedEvent carrying an ObjectEntity whose register and schema are
 * ids) and assert the consumer still starts a transmission for integriq's own
 * `event` schema and nothing else (integriq#1222).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ListenerSchemaResolver;
use OCA\Integriq\Service\PeppolOutboundConsumer;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the register and schema scoping of the outbound consumer.
 */
class PeppolOutboundConsumerTest extends TestCase {

	/**
	 * Register id of integriq's own register on the simulated instance.
	 */
	private const OWN_REGISTER_ID = '7';

	/**
	 * Schema id of integriq's `event` schema on the simulated instance.
	 */
	private const EVENT_SCHEMA_ID = '42';

	/**
	 * Register ids on the simulated instance, mapped to their slugs.
	 */
	private const REGISTERS = [
		self::OWN_REGISTER_ID => 'integriq',
		'9' => 'shillinq',
	];

	/**
	 * Schema ids on the simulated instance, mapped to their slugs. Schema 43 is
	 * another app's schema that happens to be slugged `event` too.
	 */
	private const SCHEMAS = [
		self::EVENT_SCHEMA_ID => 'event',
		'43' => 'event',
		'50' => 'source',
	];

	/**
	 * The transmission service the consumer dispatches to.
	 *
	 * @var PeppolTransmissionService&MockObject
	 */
	private PeppolTransmissionService&MockObject $transmissionService;

	/**
	 * The logger handed to the resolver.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the transmission service and logger doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->transmissionService = $this->createMock(PeppolTransmissionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the consumer under test over OpenRegister mappers that resolve ids
	 * from the maps above.
	 *
	 * @param bool $mappersFail Whether every mapper lookup throws, as when OpenRegister cannot answer.
	 *
	 * @return PeppolOutboundConsumer
	 */
	private function consumer(bool $mappersFail = false): PeppolOutboundConsumer {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		if ($mappersFail === true) {
			$registerMapper->method('find')->willThrowException(new RuntimeException('OpenRegister unavailable'));
			$schemaMapper->method('find')->willThrowException(new RuntimeException('OpenRegister unavailable'));
		} else {
			$registerMapper->method('find')->willReturnCallback(fn (string|int $id): object => $this->slugEntity(map: self::REGISTERS, id: (string)$id));
			$schemaMapper->method('find')->willReturnCallback(fn (string|int $id): object => $this->slugEntity(map: self::SCHEMAS, id: (string)$id));
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapper::class, $registerMapper],
				[SchemaMapper::class, $schemaMapper],
			]
		);

		return new PeppolOutboundConsumer(
			transmissionService: $this->transmissionService,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->logger),
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end consumer()

	/**
	 * A register or schema entity as OpenRegister's mappers return it.
	 *
	 * OpenRegister's `Register` and `Schema` extend Nextcloud's `Entity` and
	 * serve `getSlug()` through `__call()`, so this one does too: a resolver
	 * that probed `getSlug` with `method_exists()` would fail here as it would
	 * in production.
	 *
	 * @param array<string,string> $map Id => slug.
	 * @param string $id The id asked for.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When the id is unknown, as the real mappers throw.
	 */
	private function slugEntity(array $map, string $id): object {
		if (array_key_exists($id, $map) === false) {
			throw new RuntimeException('Register or schema ' . $id . ' does not exist');
		}

		$entity = new class extends Entity {
			/**
			 * The slug, served through Entity::__call() like the real one.
			 *
			 * @var string|null
			 */
			protected $slug = null;
		};
		$entity->setSlug($map[$id]);

		return $entity;

	}//end slugEntity()

	/**
	 * A created event for an object stamped with register and schema ids.
	 *
	 * @param string $registerId The register id OpenRegister stamped.
	 * @param string $schemaId The schema id OpenRegister stamped.
	 * @param string $type The CloudEvent type in the object payload.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function createdEvent(string $registerId, string $schemaId, string $type): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('3f1c2a4e-0000-4000-8000-000000000142');
		$entity->setRegister($registerId);
		$entity->setSchema($schemaId);
		$entity->setObject(
			[
				'type' => $type,
				'source' => '/apps/shillinq/invoices',
				'data' => [
					'sourceApp' => 'shillinq',
					'objectType' => 'invoice',
					'objectUri' => '/apps/shillinq/invoices/2026-0142',
					'recipientPeppolId' => '0106:12345678',
					'documentType' => 'ubl-invoice-2.1',
					'payloadFileUri' => '/admin/files/Invoices/2026-0142.xml',
				],
			]
		);

		return new ObjectCreatedEvent($entity);

	}//end createdEvent()

	/**
	 * An outbound request saved in integriq's register and `event` schema,
	 * carrying the numeric ids OpenRegister really stamps, starts a
	 * transmission. Before the fix the ids were compared with the slugs
	 * `integriq` and `event`, so this never dispatched.
	 *
	 * @return void
	 */
	public function testOwnEventWithNumericIdsStartsATransmission(): void {
		$this->transmissionService->expects($this->once())
			->method('handleOutboundRequested')
			->with(
				$this->callback(
					static function (array $eventData): bool {
						return ($eventData['objectUri'] ?? null) === '/apps/shillinq/invoices/2026-0142'
							&& ($eventData['recipientPeppolId'] ?? null) === '0106:12345678';
					}
				)
			);

		$this->consumer()->handle(
			$this->createdEvent(
				registerId: self::OWN_REGISTER_ID,
				schemaId: self::EVENT_SCHEMA_ID,
				type: PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED
			)
		);

	}//end testOwnEventWithNumericIdsStartsATransmission()

	/**
	 * The same outbound request type in another app's register is ignored,
	 * even when that register also has a schema slugged `event`.
	 *
	 * @return void
	 */
	public function testSameTypeInAForeignRegisterIsRejected(): void {
		$this->transmissionService->expects($this->never())->method('handleOutboundRequested');

		$this->consumer()->handle(
			$this->createdEvent(
				registerId: '9',
				schemaId: '43',
				type: PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED
			)
		);

	}//end testSameTypeInAForeignRegisterIsRejected()

	/**
	 * Another schema in integriq's own register is ignored.
	 *
	 * @return void
	 */
	public function testOtherSchemaInOwnRegisterIsRejected(): void {
		$this->transmissionService->expects($this->never())->method('handleOutboundRequested');

		$this->consumer()->handle(
			$this->createdEvent(
				registerId: self::OWN_REGISTER_ID,
				schemaId: '50',
				type: PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED
			)
		);

	}//end testOtherSchemaInOwnRegisterIsRejected()

	/**
	 * When OpenRegister cannot resolve the ids, nothing starts and a warning
	 * is logged: the guard fails closed.
	 *
	 * @return void
	 */
	public function testUnresolvableIdsStartNothingAndWarn(): void {
		$this->transmissionService->expects($this->never())->method('handleOutboundRequested');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->consumer(mappersFail: true)->handle(
			$this->createdEvent(
				registerId: self::OWN_REGISTER_ID,
				schemaId: self::EVENT_SCHEMA_ID,
				type: PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED
			)
		);

	}//end testUnresolvableIdsStartNothingAndWarn()

	/**
	 * An entity that already carries the slugs still matches, so a future
	 * OpenRegister that stamps slugs keeps working.
	 *
	 * @return void
	 */
	public function testSlugsAreStillAccepted(): void {
		$this->transmissionService->expects($this->once())->method('handleOutboundRequested');

		$this->consumer()->handle(
			$this->createdEvent(
				registerId: 'integriq',
				schemaId: 'event',
				type: PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED
			)
		);

	}//end testSlugsAreStillAccepted()

	/**
	 * Any other event type in integriq's own event schema is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventTypeIsIgnored(): void {
		$this->transmissionService->expects($this->never())->method('handleOutboundRequested');

		$this->consumer()->handle(
			$this->createdEvent(
				registerId: self::OWN_REGISTER_ID,
				schemaId: self::EVENT_SCHEMA_ID,
				type: PeppolTransmissionService::EVENT_TYPE_DELIVERY_STATUS
			)
		);

	}//end testOtherEventTypeIsIgnored()
}//end class
