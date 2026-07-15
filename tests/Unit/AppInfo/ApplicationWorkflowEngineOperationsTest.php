<?php
/**
 * Unit test for the `workflowengine` feature-detected registration in
 * Application::register() (flow-workflowengine-integration).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Covers the two normative scenarios from
 * openspec/specs/flow-workflowengine-operations/spec.md
 * (Requirement: WorkflowEngine operation registration MUST be feature-detected — REQ-001):
 *   - `workflowengine` enabled -> RegisterOperationsListener IS registered against
 *     RegisterOperationsEvent.
 *   - `workflowengine` disabled -> no registration occurs, nothing is logged.
 *   - IAppManager resolution throws -> no registration occurs, a warning is logged,
 *     no exception propagates.
 *
 * The private registerWorkflowEngineOperations() method is exercised in isolation
 * via reflection on an instance created without invoking the parent App constructor
 * (which would require a live Nextcloud server container) — same technique as
 * ApplicationStorageMigratedTest.
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\AppInfo;

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\WorkflowEngine\RegisterOperationsListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenConnector\AppInfo\Application
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */
class ApplicationWorkflowEngineOperationsTest extends TestCase
{


    /**
     * Build an Application instance whose getContainer() resolves IAppManager
     * (or throws) and LoggerInterface from the given mocks, without running
     * the real App constructor.
     *
     * @param IAppManager|null     $appManager The IAppManager mock to expose, or null
     *                                         to make the container throw on get().
     * @param LoggerInterface|null $logger     Optional logger mock.
     *
     * @return Application
     */
    private function makeApp(?IAppManager $appManager, ?LoggerInterface $logger=null): Application
    {
        $logger    = ($logger ?? $this->createMock(LoggerInterface::class));
        $container = $this->createMock(IAppContainer::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($appManager, $logger) {
                if ($id === IAppManager::class) {
                    if ($appManager === null) {
                        throw new \RuntimeException('IAppManager unavailable');
                    }

                    return $appManager;
                }

                if ($id === LoggerInterface::class) {
                    return $logger;
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        // Inject the mock container into App's protected $container property.
        $appReflection = new ReflectionClass(\OCP\AppFramework\App::class);
        if ($appReflection->hasProperty('container') === true) {
            $prop = $appReflection->getProperty('container');
            $prop->setAccessible(true);
            $prop->setValue($app, $container);
        }

        return $app;

    }//end makeApp()


    /**
     * Build an `IAppManager` mock exposing `isEnabledForAnyUser('workflowengine')`.
     *
     * `isEnabledForAnyUser()` is real, current `OCP\App\IAppManager` API (used
     * unconditionally by the pre-existing Tables/Forms gate in
     * `registerNextcloudEventTriggers()`) but is absent from the
     * `nextcloud/ocp:dev-stable29` dev-dependency's older interface snapshot,
     * so `createMock()` cannot configure it directly — `addMethods()` adds it
     * to the generated mock class.
     *
     * @param bool $enabled The value `isEnabledForAnyUser('workflowengine')` should return.
     *
     * @return IAppManager
     */
    private function makeAppManagerMock(bool $enabled): IAppManager
    {
        $appManager = $this->getMockBuilder(IAppManager::class)
            ->addMethods(['isEnabledForAnyUser'])
            ->getMockForAbstractClass();
        $appManager->method('isEnabledForAnyUser')->with('workflowengine')->willReturn($enabled);

        return $appManager;

    }//end makeAppManagerMock()


    /**
     * Invoke the private registerWorkflowEngineOperations() method.
     *
     * @param Application      $app        The instance under test.
     * @param IEventDispatcher $dispatcher The dispatcher mock to pass through.
     *
     * @return void
     */
    private function invokeRegister(Application $app, IEventDispatcher $dispatcher): void
    {
        $method = (new ReflectionClass(Application::class))->getMethod('registerWorkflowEngineOperations');
        $method->setAccessible(true);
        $method->invoke($app, $this->createMock(IRegistrationContext::class), $dispatcher);

    }//end invokeRegister()


    /**
     * GIVEN `workflowengine` is enabled WHEN OpenConnector boots THEN
     * RegisterOperationsListener IS registered against RegisterOperationsEvent.
     *
     * @return void
     */
    public function testRegistersListenerWhenWorkflowEngineEnabled(): void
    {
        $appManager = $this->makeAppManagerMock(true);

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('addServiceListener')
            ->with(
                eventName: RegisterOperationsEvent::class,
                className: RegisterOperationsListener::class
            );

        $this->invokeRegister($this->makeApp($appManager), $dispatcher);

    }//end testRegistersListenerWhenWorkflowEngineEnabled()


    /**
     * GIVEN `workflowengine` is disabled WHEN OpenConnector boots THEN no
     * registration occurs and nothing is logged (a disabled app is a normal
     * state, not a fault).
     *
     * @return void
     */
    public function testNoRegistrationAndNoLogWhenWorkflowEngineDisabled(): void
    {
        $appManager = $this->makeAppManagerMock(false);

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->expects($this->never())->method('addServiceListener');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('error');

        $this->invokeRegister($this->makeApp($appManager, $logger), $dispatcher);

    }//end testNoRegistrationAndNoLogWhenWorkflowEngineDisabled()


    /**
     * GIVEN IAppManager resolution throws WHEN OpenConnector boots THEN no
     * registration occurs, a warning (not error) is logged, and no exception
     * propagates.
     *
     * @return void
     */
    public function testDegradesSoftlyWhenAppManagerResolutionThrows(): void
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->expects($this->never())->method('addServiceListener');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->never())->method('error');

        // Must not throw.
        $this->invokeRegister($this->makeApp(null, $logger), $dispatcher);
        $this->addToAssertionCount(1);

    }//end testDegradesSoftlyWhenAppManagerResolutionThrows()
}//end class
