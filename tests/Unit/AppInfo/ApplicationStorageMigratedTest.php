<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the pre-flight `storage_migrated` assertion in Application::register().
 *
 * Covers the three normative scenarios from
 * openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md
 * (Requirement: "Application.php DI bindings MUST be updated"):
 *   - flag absent / 'false' -> non-fatal warning logged, no exception (consolidate-merge:
 *     PR #29's hard \LogicException was downgraded to a soft warning so an unmigrated
 *     instance still boots, per development's deferral decision)
 *   - OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1 -> no exception regardless of flag
 *   - flag === 'true' -> assertion passes silently
 *
 * The private assertStorageMigrated() method is exercised in isolation via
 * reflection on an instance created without invoking the parent App constructor
 * (which would require a live Nextcloud server container).
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\AppInfo;

use OCA\OpenConnector\AppInfo\Application;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenConnector\AppInfo\Application
 */
class ApplicationStorageMigratedTest extends TestCase
{
    /**
     * The env var name that bypasses the assertion.
     *
     * @var string
     */
    private const SKIP_ENV = 'OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT';

    /**
     * Remembered prior env-var value, restored in tearDown().
     *
     * @var string|false
     */
    private $priorEnv;

    /**
     * Snapshot the skip env var before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->priorEnv = getenv(self::SKIP_ENV);
        putenv(self::SKIP_ENV);
    }//end setUp()

    /**
     * Restore the skip env var after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->priorEnv === false) {
            putenv(self::SKIP_ENV);
        } else {
            putenv(self::SKIP_ENV.'='.$this->priorEnv);
        }

        parent::tearDown();
    }//end tearDown()

    /**
     * Build an Application instance whose getContainer() returns a container
     * resolving IAppConfig::class to the supplied mock — without running the
     * real App constructor.
     *
     * @param IAppConfig|null    $appConfig The app-config mock to expose, or null
     *                                      to make the container throw on get().
     * @param LoggerInterface|null $logger  Optional logger mock resolved by the
     *                                      soft-warning path; null falls back to a
     *                                      no-op logger so get() never fails.
     *
     * @return Application
     */
    private function makeApp(?IAppConfig $appConfig, ?LoggerInterface $logger = null): Application
    {
        $container = $this->createMock(IAppContainer::class);
        if ($appConfig === null) {
            $container->method('get')->willThrowException(new \RuntimeException('no IAppConfig'));
        } else {
            $logger = ($logger ?? $this->createMock(LoggerInterface::class));
            $container->method('get')->willReturnCallback(
                function (string $id) use ($appConfig, $logger) {
                    if ($id === IAppConfig::class) {
                        return $appConfig;
                    }

                    if ($id === LoggerInterface::class) {
                        return $logger;
                    }

                    throw new \RuntimeException('unexpected container id: '.$id);
                }
            );
        }

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
     * Invoke the private assertStorageMigrated() method.
     *
     * @param Application $app The instance under test.
     *
     * @return void
     */
    private function invokeAssert(Application $app): void
    {
        $method = (new ReflectionClass(Application::class))->getMethod('assertStorageMigrated');
        $method->setAccessible(true);
        $method->invoke($app);
    }//end invokeAssert()

    /**
     * Build an IAppConfig mock that returns the given storage_migrated value.
     *
     * @param string $value The value getValueString() should return.
     *
     * @return IAppConfig
     */
    private function appConfigReturning(string $value): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->with('openconnector', 'storage_migrated', 'false')
            ->willReturn($value);
        return $appConfig;
    }//end appConfigReturning()

    /**
     * GIVEN storage_migrated is 'false' WHEN the assertion runs THEN it logs a
     * non-fatal warning mentioning the migrate-storage runbook command and does
     * NOT throw (consolidate-merge: PR #29's hard LogicException was downgraded to
     * a soft warning so an unmigrated instance still boots).
     *
     * @return void
     */
    public function testWarnsWithoutThrowingWhenFlagFalse(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->matchesRegularExpression('/occ openconnector:migrate-storage/'));

        $app = $this->makeApp($this->appConfigReturning('false'), $logger);

        // Must not throw.
        $this->invokeAssert($app);

        $this->addToAssertionCount(1);
    }//end testWarnsWithoutThrowingWhenFlagFalse()

    /**
     * GIVEN storage_migrated is 'true' WHEN the assertion runs THEN it returns
     * without throwing.
     *
     * @return void
     */
    public function testPassesWhenFlagTrue(): void
    {
        $app = $this->makeApp($this->appConfigReturning('true'));

        $this->invokeAssert($app);

        $this->addToAssertionCount(1);
    }//end testPassesWhenFlagTrue()

    /**
     * GIVEN the skip env var is set WHEN the assertion runs THEN it returns
     * without throwing and never touches IAppConfig (container.get throws).
     *
     * @return void
     */
    public function testBypassedByEnvVar(): void
    {
        putenv(self::SKIP_ENV.'=1');

        // Container would throw if consulted — proving the env var short-circuits first.
        $app = $this->makeApp(null);

        $this->invokeAssert($app);

        $this->addToAssertionCount(1);
    }//end testBypassedByEnvVar()

    /**
     * GIVEN IAppConfig cannot be resolved (early bootstrap) and the env var is
     * unset WHEN the assertion runs THEN it soft-skips rather than crashing boot.
     *
     * @return void
     */
    public function testSoftSkipsWhenAppConfigUnavailable(): void
    {
        $app = $this->makeApp(null);

        $this->invokeAssert($app);

        $this->addToAssertionCount(1);
    }//end testSoftSkipsWhenAppConfigUnavailable()
}//end class
