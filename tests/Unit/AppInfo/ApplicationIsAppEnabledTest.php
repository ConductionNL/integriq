<?php
/**
 * Unit test for Application::isAppEnabled() — the version-safe optional-app
 * feature detection introduced by #1089.
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
 * THE DEFECT THESE TESTS EXIST FOR (#1089). The Tables, Forms and
 * WorkflowEngine registrations were gated on
 * `IAppManager::isEnabledForAnyUser()`, which is not a method on
 * `OCP\App\IAppManager` — not in the vendored `nextcloud/ocp:dev-stable29`
 * interface, and not in Nextcloud 35's. Every call raised
 * `Error: Call to undefined method OC\App\AppManager::isEnabledForAnyUser()`,
 * and because each call site is wrapped in a `catch (\Throwable)` that only
 * logs a warning, the outcome was silent: those three integrations were never
 * registered on any server, and nothing failed loudly enough to notice.
 *
 * It survived CI because the one test covering it fabricated the method with
 * PHPUnit's `addMethods(['isEnabledForAnyUser'])`, so the mock had an API the
 * real interface never did. A test that invents the method it is verifying can
 * only ever pass. That is the failure mode these tests are written to close.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\AppInfo;

use OCA\OpenConnector\AppInfo\Application;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenConnector\AppInfo\Application
 */
class ApplicationIsAppEnabledTest extends TestCase
{


    /**
     * Invoke the private isAppEnabled() helper.
     *
     * The instance is created without the parent `App` constructor, which would
     * require a live Nextcloud server container — same technique as the sibling
     * Application tests. isAppEnabled() touches no container state, so an
     * unconstructed instance is sufficient.
     *
     * @param IAppManager $appManager The app manager double.
     * @param string      $appId      The app id to test.
     *
     * @return boolean
     */
    private function invokeIsAppEnabled(IAppManager $appManager, string $appId): bool
    {
        $app    = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(Application::class))->getMethod('isAppEnabled');
        $method->setAccessible(true);

        return $method->invoke($app, $appManager, $appId);

    }//end invokeIsAppEnabled()


    /**
     * GIVEN a server that predates `isEnabledForAnyone()` (`@since 32.0.0`)
     * WHEN an optional app is feature-detected
     * THEN `isInstalled()` answers the question.
     *
     * This is the branch this test environment exercises: the pinned
     * `nextcloud/ocp:dev-stable29` interface has no `isEnabledForAnyone()`, so a
     * plain `createMock()` of it does not either, and `method_exists()` is false.
     * That makes the pre-32 fallback genuinely covered rather than assumed.
     *
     * @return void
     */
    public function testFallsBackToIsInstalledOnServersWithoutIsEnabledForAnyone(): void
    {
        $this->assertFalse(
            method_exists(IAppManager::class, 'isEnabledForAnyone'),
            'precondition: the pinned OCP interface must predate isEnabledForAnyone() for this branch to be under test'
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->expects($this->once())
            ->method('isInstalled')
            ->with('tables')
            ->willReturn(true);

        $this->assertTrue($this->invokeIsAppEnabled($appManager, 'tables'));

    }//end testFallsBackToIsInstalledOnServersWithoutIsEnabledForAnyone()


    /**
     * GIVEN a disabled optional app on a pre-32 server WHEN it is
     * feature-detected THEN the helper reports false.
     *
     * @return void
     */
    public function testReportsFalseWhenTheAppIsNotInstalled(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('forms')->willReturn(false);

        $this->assertFalse($this->invokeIsAppEnabled($appManager, 'forms'));

    }//end testReportsFalseWhenTheAppIsNotInstalled()


    /**
     * GIVEN a Nextcloud 32+ server, where `isEnabledForAnyone()` exists,
     * WHEN an optional app is feature-detected
     * THEN that method is preferred and `isInstalled()` (deprecated since 32.0.0)
     * is NOT called.
     *
     * `addMethods()` is legitimate here in the way it was not in the code this
     * fixes: `isEnabledForAnyone()` genuinely exists on NC >= 32 and is absent
     * only from the pinned stub, so adding it to the mock reproduces a real
     * server rather than inventing an API.
     *
     * @return void
     */
    public function testPrefersIsEnabledForAnyoneWhenTheServerHasIt(): void
    {
        $appManager = $this->getMockBuilder(IAppManager::class)
            ->addMethods(['isEnabledForAnyone'])
            ->getMockForAbstractClass();
        $appManager->expects($this->once())
            ->method('isEnabledForAnyone')
            ->with('workflowengine')
            ->willReturn(true);
        $appManager->expects($this->never())->method('isInstalled');

        $this->assertTrue($this->invokeIsAppEnabled($appManager, 'workflowengine'));

    }//end testPrefersIsEnabledForAnyoneWhenTheServerHasIt()


    /**
     * REGRESSION GUARD (#1089): no production source may call
     * `isEnabledForAnyUser()`, because no Nextcloud version provides it.
     *
     * A behavioural test cannot catch a reintroduction of this defect: the call
     * sites swallow `\Throwable` and only log, so a wrong method name degrades to
     * "feature quietly absent" rather than a failing assertion. Asserting on the
     * source is therefore the only guard that actually holds — and it also
     * documents the name to never reach for again.
     *
     * @return void
     */
    public function testNoProductionCodeCallsTheNonExistentIsEnabledForAnyUser(): void
    {
        $this->assertFalse(
            method_exists(IAppManager::class, 'isEnabledForAnyUser'),
            'isEnabledForAnyUser() is not IAppManager API on any supported Nextcloud version'
        );

        $libDir   = dirname(__DIR__, 3).'/lib';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($libDir));
        foreach ($files as $file) {
            if ($file->isFile() === false || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, '->isEnabledForAnyUser(') === true) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files call a method Nextcloud does not have; use Application::isAppEnabled() instead'
        );

    }//end testNoProductionCodeCallsTheNonExistentIsEnabledForAnyUser()
}//end class
