<?php

/**
 * Unit tests for SbrFilingConsumer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Digipoort\SbrFilingService;
use OCA\Integriq\Service\ListenerSchemaResolver;
use OCA\Integriq\Service\SbrFilingConsumer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The consumer hands integriq's own filing requests to the filing service and nothing else.
 */
#[CoversClass(SbrFilingConsumer::class)]
#[UsesClass(ListenerSchemaResolver::class)]
class SbrFilingConsumerTest extends TestCase {
	/**
	 * Register ids mapped to slugs.
	 */
	private const REGISTERS = ['7' => 'integriq', '9' => 'shillinq'];

	/**
	 * Schema ids mapped to slugs; 43 is another app's schema also slugged `event`.
	 */
	private const SCHEMAS = ['42' => 'event', '43' => 'event', '50' => 'source'];

	/**
	 * The filing service double.
	 *
	 * @var SbrFilingService&MockObject
	 */
	private SbrFilingService&MockObject $filings;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->filings = $this->createMock(SbrFilingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A filing request in integriq's `event` schema reaches the service with its data.
	 *
	 * @return void
	 */
	public function testAFilingRequestReachesTheService(): void {
		$this->filings->expects($this->once())->method('handleRequested')->with(
			[
				'objectUri' => '/apps/shillinq/vat-returns/2026-q3',
				'filingType' => 'ob-aangifte',
			]
		);

		$this->consumer()->handle($this->created(registerId: '7', schemaId: '42', type: SbrFilingService::EVENT_TYPE_REQUESTED));
	}//end testAFilingRequestReachesTheService()

	/**
	 * Another CloudEvent type is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventTypeIsIgnored(): void {
		$this->filings->expects($this->never())->method('handleRequested');

		$this->consumer()->handle($this->created(registerId: '7', schemaId: '42', type: 'nl.conduction.peppol.outbound.requested'));
	}//end testAnotherEventTypeIsIgnored()

	/**
	 * A request in another app's register, or another schema slugged `event`, is ignored.
	 *
	 * @return void
	 */
	public function testARequestOutsideIntegriqsEventSchemaIsIgnored(): void {
		$this->filings->expects($this->never())->method('handleRequested');

		$this->consumer()->handle($this->created(registerId: '9', schemaId: '43', type: SbrFilingService::EVENT_TYPE_REQUESTED));
		$this->consumer()->handle($this->created(registerId: '7', schemaId: '50', type: SbrFilingService::EVENT_TYPE_REQUESTED));
	}//end testARequestOutsideIntegriqsEventSchemaIsIgnored()

	/**
	 * When the ids cannot be resolved, the request is not trusted.
	 *
	 * @return void
	 */
	public function testUnresolvableIdsFailClosed(): void {
		$this->filings->expects($this->never())->method('handleRequested');

		$this->consumer(mappersFail: true)->handle($this->created(registerId: '7', schemaId: '42', type: SbrFilingService::EVENT_TYPE_REQUESTED));
	}//end testUnresolvableIdsFailClosed()

	/**
	 * Another Nextcloud event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->filings->expects($this->never())->method('handleRequested');

		$this->consumer()->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A failure in the service is logged, not thrown into the object write.
	 *
	 * @return void
	 */
	public function testAServiceFailureIsLogged(): void {
		$this->filings->method('handleRequested')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[SbrFilingConsumer] failed to process a filing request: store down',
			$this->anything()
		);

		$this->consumer(logger: $logger)->handle($this->created(registerId: '7', schemaId: '42', type: SbrFilingService::EVENT_TYPE_REQUESTED));
	}//end testAServiceFailureIsLogged()

	/**
	 * The consumer over mappers that resolve the ids above.
	 *
	 * @param boolean              $mappersFail Whether every lookup throws.
	 * @param LoggerInterface|null $logger      The consumer's logger.
	 *
	 * @return SbrFilingConsumer
	 */
	private function consumer(bool $mappersFail = false, ?LoggerInterface $logger = null): SbrFilingConsumer {
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
		$container->method('get')->willReturnMap([[RegisterMapper::class, $registerMapper], [SchemaMapper::class, $schemaMapper]]);

		return new SbrFilingConsumer(
			filings: $this->filings,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->logger),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end consumer()

	/**
	 * A register or schema entity serving its slug through Entity::__call().
	 *
	 * @param array<string,string> $map Id => slug.
	 * @param string               $id  The id asked for.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When the id is unknown.
	 */
	private function slugEntity(array $map, string $id): object {
		if (array_key_exists($id, $map) === false) {
			throw new RuntimeException('Register or schema ' . $id . ' does not exist');
		}

		$entity = new class extends Entity {
			/**
			 * The slug.
			 *
			 * @var string|null
			 */
			protected $slug = null;
		};
		$entity->setSlug($map[$id]);

		return $entity;
	}//end slugEntity()

	/**
	 * A created event for an event object stamped with ids.
	 *
	 * @param string $registerId The register id.
	 * @param string $schemaId   The schema id.
	 * @param string $type       The CloudEvent type.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(string $registerId, string $schemaId, string $type): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('3f1c2a4e-0000-4000-8000-000000000300');
		$entity->setRegister($registerId);
		$entity->setSchema($schemaId);
		$entity->setObject(
			[
				'type' => $type,
				'data' => ['objectUri' => '/apps/shillinq/vat-returns/2026-q3', 'filingType' => 'ob-aangifte'],
			]
		);

		return new ObjectCreatedEvent($entity);
	}//end created()
}//end class
