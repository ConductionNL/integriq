<?php

/**
 * Unit tests for ViewDeletedEventListener (ADR-078 / gate-61).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\OpenConnector\Cron\DeferredViewCascadeJob;
use OCA\OpenConnector\EventListener\ViewDeletedEventListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener must hand its cascade to a background job and do no work itself.
 *
 * ORDER MATTERS IN THIS FILE. The positive control — "a matching deleted view
 * DOES produce a deferral entry" — is asserted first. Every negative assertion
 * below it ("an unrelated schema defers nothing") passes identically against a
 * listener that does nothing at all, so on its own it proves nothing.
 */
class ViewDeletedEventListenerTest extends TestCase {

	/**
	 * A tiny id+slug value object standing in for a Register/Schema row.
	 *
	 * @param int    $id   The row id.
	 * @param string $slug The slug to return.
	 *
	 * @return object
	 */
	private function row(int $id, string $slug): object {
		return new class($id, $slug) {
			public function __construct(
				private int $id,
				private string $slug,
			) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end row()

	/**
	 * Build the deleted-view entity the listener reacts to.
	 *
	 * @param string $identifier The view identifier carried in the payload.
	 *
	 * @return ObjectEntity
	 */
	private function view(string $identifier): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('view-uuid');
		$entity->setRegister(7);
		$entity->setSchema(11);
		$entity->setObject(['identifier' => $identifier]);

		return $entity;
	}//end view()

	/**
	 * POSITIVE CONTROL: a deleted vng-gemma/view defers the cascade.
	 *
	 * @return void
	 */
	public function testMatchingViewDeferstheCascadeToABackgroundJob(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->row(7, 'vng-gemma'));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (mixed $ref): object {
				if ($ref === 'extendview') {
					return $this->row(42, 'extendview');
				}

				return $this->row(11, 'view');
			}
		);

		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects($this->once())
			->method('defer')
			->with(
				DeferredViewCascadeJob::class,
				[
					'identifier' => 'gemma-view-1',
					'register'   => 7,
					'schema'     => 42,
				],
				ListenerDeferralService::DEFAULT_CHUNK_SIZE,
				'7|42|gemma-view-1'
			);

		$listener = new ViewDeletedEventListener(
			$schemaMapper,
			$registerMapper,
			$deferral,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new ObjectDeletedEvent($this->view('gemma-view-1')));
	}//end testMatchingViewDeferstheCascadeToABackgroundJob()

	/**
	 * A delete in another register defers nothing.
	 *
	 * Only meaningful because the test above proved a match DOES defer.
	 *
	 * @return void
	 */
	public function testForeignRegisterDefersNothing(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->row(7, 'some-other-register'));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->row(11, 'view'));

		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects($this->never())->method('defer');

		$listener = new ViewDeletedEventListener(
			$schemaMapper,
			$registerMapper,
			$deferral,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new ObjectDeletedEvent($this->view('gemma-view-1')));
	}//end testForeignRegisterDefersNothing()

	/**
	 * A view carrying no identifier has nothing to cascade on.
	 *
	 * @return void
	 */
	public function testMissingIdentifierDefersNothing(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->row(7, 'vng-gemma'));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (mixed $ref): object {
				if ($ref === 'extendview') {
					return $this->row(42, 'extendview');
				}

				return $this->row(11, 'view');
			}
		);

		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects($this->never())->method('defer');

		$listener = new ViewDeletedEventListener(
			$schemaMapper,
			$registerMapper,
			$deferral,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new ObjectDeletedEvent($this->view('')));
	}//end testMissingIdentifierDefersNothing()

	/**
	 * An unresolvable schema is swallowed — the delete that triggered us must
	 * never fail because a cascade could not be planned.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaIsSwallowed(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new \RuntimeException('no such register'));

		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects($this->never())->method('defer');

		$listener = new ViewDeletedEventListener(
			$this->createMock(SchemaMapper::class),
			$registerMapper,
			$deferral,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new ObjectDeletedEvent($this->view('gemma-view-1')));
		$this->addToAssertionCount(1);
	}//end testUnresolvableSchemaIsSwallowed()

	/**
	 * A non-ObjectDeletedEvent is ignored.
	 *
	 * @return void
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects($this->never())->method('defer');

		$listener = new ViewDeletedEventListener(
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$deferral,
			$this->createMock(LoggerInterface::class)
		);

		$listener->handle(new class extends Event {
		});
	}//end testUnrelatedEventIsIgnored()
}//end class
