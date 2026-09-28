<?php

/**
 * The `permanent` capability check behind the synced hard delete (WOO-557).
 *
 * OpenConnector on the 0.2.24 line may run against OpenRegister 1.1.5 (no
 * `permanent` parameter on deleteObject()), 1.1.5-woo-1 or later (has it), or
 * 2.x (has it). A named argument the callee does not know is a fatal error,
 * so the service reads the signature before it decides which call to make.
 * These tests pin that reading; the private method is reached through
 * reflection because it is only ever consumed by the delete branch of
 * updateTargetOpenRegister().
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SynchronizationServicePermanentDeleteTest extends TestCase
{
    private function supportsPermanentDelete(object $objectService): bool
    {
        $reflection = new ReflectionClass(SynchronizationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('objectServiceSupportsPermanentDelete');

        return $method->invoke($service, $objectService);
    }

    public function testSignatureWithPermanentParameterIsRecognised(): void
    {
        $objectService = new class {
            public function deleteObject(string $uuid, bool $_rbac = true, bool $_multitenancy = true, bool $permanent = false): bool
            {
                return true;
            }
        };

        $this->assertTrue($this->supportsPermanentDelete($objectService));
    }

    public function testSignatureWithoutPermanentParameterFallsBack(): void
    {
        // OpenRegister 1.1.5 as released: three parameters, no `permanent`.
        $objectService = new class {
            public function deleteObject(string $uuid, bool $_rbac = true, bool $_multitenancy = true): bool
            {
                return true;
            }
        };

        $this->assertFalse($this->supportsPermanentDelete($objectService));
    }

    public function testObjectWithoutDeleteObjectFallsBackInsteadOfThrowing(): void
    {
        $this->assertFalse($this->supportsPermanentDelete(new \stdClass()));
    }
}
