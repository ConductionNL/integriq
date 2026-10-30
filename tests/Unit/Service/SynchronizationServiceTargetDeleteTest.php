<?php

/**
 * The delete branch of updateTargetOpenRegister() (WOO-557), pinned end to end.
 *
 * SynchronizationServicePermanentDeleteTest covers the capability check in
 * isolation; this class drives the branch that consumes it, with a real
 * SynchronizationContract and Synchronization and an ObjectService double that
 * records the named arguments it receives. Two outcomes: an ObjectService that
 * knows `permanent` gets `permanent: true`; one that does not gets the plain
 * soft delete plus one warning. Removing `permanent: true`, or swapping the
 * two branches, turns a test here red.
 *
 * The service is built without its constructor (like
 * SynchronizationServiceContentDispositionTest); only the three collaborators
 * the branch touches are set through reflection.
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

// nextcloud/ocp is installed on this line but not on composer's autoload map, and
// the two entities below extend OCP\AppFramework\Db\Entity. Load it from the
// package when the host has not (the class needs nothing else from OCP).
if (class_exists(\OCP\AppFramework\Db\Entity::class) === false) {
    include_once __DIR__.'/../../../vendor/nextcloud/ocp/OCP/AppFramework/Db/Entity.php';
}

use OCA\OpenConnector\Db\Synchronization;
use OCA\OpenConnector\Db\SynchronizationContract;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class SynchronizationServiceTargetDeleteTest extends TestCase
{


    /**
     * Run updateTargetOpenRegister(action: 'delete') for target `t-1` against
     * the given ObjectService double and return the contract afterwards.
     */
    private function deleteTarget(object $objectService, LoggerInterface&MockObject $logger): SynchronizationContract
    {
        $reflection = new ReflectionClass(SynchronizationService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);
        $callService = $this->createMock(CallService::class);
        $callService->method('applyConfigDot')->willReturn([]);

        foreach (['containerInterface' => $container, 'callService' => $callService, 'logger' => $logger] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }

        $contract = new SynchronizationContract();
        $contract->setTargetId('t-1');
        $synchronization = new Synchronization();
        $synchronization->setTargetId('1/1');
        $synchronization->setSourceConfig([]);

        $targetObject = [];
        $arguments    = [
            $contract,
            $synchronization,
            &$targetObject,
            'delete',
        ];
        $method       = $reflection->getMethod('updateTargetOpenRegister');
        $method->setAccessible(true);
        $method->invokeArgs($service, $arguments);

        return $contract;

    }//end deleteTarget()


    public function testAnObjectServiceWithPermanentGetsPermanentTrue(): void
    {
        $objectService = new class {

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $calls = [];


            public function deleteObject(string $uuid, bool $_rbac=true, bool $_multitenancy=true, bool $permanent=false): bool
            {
                $this->calls[] = [
                    'uuid'      => $uuid,
                    'permanent' => $permanent,
                ];

                return true;
            }//end deleteObject()


        };
        $logger        = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $contract = $this->deleteTarget($objectService, $logger);

        $this->assertSame([['uuid' => 't-1', 'permanent' => true]], $objectService->calls);
        $this->assertNull($contract->getTargetId());
        $this->assertSame('delete', $contract->getTargetLastAction());

    }//end testAnObjectServiceWithPermanentGetsPermanentTrue()


    public function testAnObjectServiceWithoutPermanentGetsTheSoftDeleteAndOneWarning(): void
    {
        // OpenRegister 1.1.5 as released: no `permanent` parameter. A named
        // argument here would be a fatal "Unknown named parameter".
        $objectService = new class {

            /**
             * @var array<int, array<string, mixed>>
             */
            public array $calls = [];


            public function deleteObject(string $uuid, bool $_rbac=true, bool $_multitenancy=true): bool
            {
                $this->calls[] = ['uuid' => $uuid];

                return true;
            }//end deleteObject()


        };
        $logger        = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no permanent delete'), $this->arrayHasKey('targetId'));

        $contract = $this->deleteTarget($objectService, $logger);

        $this->assertSame([['uuid' => 't-1']], $objectService->calls);
        $this->assertNull($contract->getTargetId());
        $this->assertSame('delete', $contract->getTargetLastAction());

    }//end testAnObjectServiceWithoutPermanentGetsTheSoftDeleteAndOneWarning()


}//end class
