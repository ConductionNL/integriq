<?php

/**
 * The soft-delete transition in ObjectUpdatedEventListener (WOO-557), pinned.
 *
 * OpenRegister persists a soft delete as an UPDATE (setDeleted() + save()) and
 * never dispatches ObjectDeletedEvent for it, so the listener has to read the
 * transition itself: old object without deletion metadata, new object with it.
 * These tests hold the three outcomes of that reading — and the one that is
 * easy to lose: an update to an object that was ALREADY soft-deleted must not
 * fire a second delete at a target that is already gone.
 *
 * The Nextcloud and OpenRegister classes the listener touches are not in this
 * repository's vendor/ (they live in the host), so the minimal shapes it reads
 * are declared here when absent. They declare nothing the listener does not
 * call.
 */

declare(strict_types=1);

namespace OCP\EventDispatcher {
    if (class_exists(Event::class) === false) {
        class Event
        {
        }
    }

    if (interface_exists(IEventListener::class) === false) {
        interface IEventListener
        {
            public function handle(Event $event): void;
        }
    }
}

namespace OCA\OpenRegister\Db {
    if (class_exists(ObjectEntity::class) === false) {
        class ObjectEntity
        {
            public function __construct(
                private readonly ?string $deleted = null,
            ) {
            }

            public function getDeleted(): ?string
            {
                return $this->deleted;
            }
        }
    }
}

namespace OCA\OpenRegister\Event {
    if (class_exists(ObjectUpdatedEvent::class) === false) {
        class ObjectUpdatedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(
                private readonly \OCA\OpenRegister\Db\ObjectEntity $newObject,
                private readonly ?\OCA\OpenRegister\Db\ObjectEntity $oldObject = null,
            ) {
            }

            public function getNewObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->newObject;
            }

            public function getOldObject(): ?\OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->oldObject;
            }
        }
    }
}

namespace OCA\OpenConnector\Tests\Unit\EventListener {

    use OCA\OpenConnector\EventListener\ObjectUpdatedEventListener;
    use OCA\OpenConnector\Service\SynchronizationService;
    use OCA\OpenRegister\Db\ObjectEntity;
    use OCA\OpenRegister\Event\ObjectUpdatedEvent;
    use OCP\EventDispatcher\Event;
    use PHPUnit\Framework\TestCase;

    class ObjectUpdatedEventListenerTest extends TestCase
    {
        /**
         * Run the listener against an old/new pair and return the mutation
         * types it forwarded, in order.
         *
         * @return array<int, string>
         */
        private function mutationsFor(?ObjectEntity $old, ObjectEntity $new): array
        {
            $forwarded = [];
            $sync = $this->createMock(SynchronizationService::class);
            $sync->method('handleObjectEventSynchronization')
                ->willReturnCallback(function (ObjectEntity $object, string $eventMutationType) use (&$forwarded): void {
                    $forwarded[] = $eventMutationType;
                });

            (new ObjectUpdatedEventListener($sync))->handle(new ObjectUpdatedEvent($new, $old));

            return $forwarded;
        }

        public function testTransitionIntoDeletedForwardsExactlyOneDelete(): void
        {
            $this->assertSame(
                ['delete'],
                $this->mutationsFor(new ObjectEntity(null), new ObjectEntity('2026-09-04T09:52:52+00:00'))
            );
        }

        public function testUpdateToAnAlreadyDeletedObjectDoesNotFireDeleteAgain(): void
        {
            $this->assertSame(
                ['update'],
                $this->mutationsFor(new ObjectEntity('2026-09-04T09:52:52+00:00'), new ObjectEntity('2026-09-04T09:52:52+00:00'))
            );
        }

        public function testOrdinaryUpdateStaysAnUpdate(): void
        {
            $this->assertSame(['update'], $this->mutationsFor(new ObjectEntity(null), new ObjectEntity(null)));
        }

        public function testDeletedObjectWithoutOldStateReadsAsFreshDelete(): void
        {
            // An OpenRegister that gives no old object cannot prove the object
            // was already deleted, so the transition is taken at face value.
            $this->assertSame(['delete'], $this->mutationsFor(null, new ObjectEntity('2026-09-04T09:52:52+00:00')));
        }

        public function testUnrelatedEventIsIgnored(): void
        {
            $sync = $this->createMock(SynchronizationService::class);
            $sync->expects($this->never())->method('handleObjectEventSynchronization');

            (new ObjectUpdatedEventListener($sync))->handle(new Event());
        }
    }
}
