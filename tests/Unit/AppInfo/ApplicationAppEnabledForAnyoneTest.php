<?php
/**
 * Unit test for Application::appEnabledForAnyone() — version-safe optional-app
 * feature detection (#1103).
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
 * THE DEFECT THESE TESTS EXIST FOR (#1103). The Tables, Forms and
 * WorkflowEngine registrations were gated on
 * `IAppManager::isEnabledForAnyUser()`, which is not a method on
 * `OCP\App\IAppManager` — not in any vendored `nextcloud/ocp` snapshot, and not
 * in Nextcloud 35's. Every call raised
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
class ApplicationAppEnabledForAnyoneTest extends TestCase
{


    /**
     * Invoke the private appEnabledForAnyone() helper.
     *
     * The instance is created without the parent `App` constructor, which would
     * require a live Nextcloud server container — same technique as the sibling
     * Application tests. appEnabledForAnyone() touches no container state, so an
     * unconstructed instance is sufficient.
     *
     * @param IAppManager $appManager The app manager double.
     * @param string      $appId      The app id to test.
     *
     * @return boolean
     */
    private function invokeAppEnabledForAnyone(IAppManager $appManager, string $appId): bool
    {
        $app    = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(Application::class))->getMethod('appEnabledForAnyone');
        $method->setAccessible(true);

        return $method->invoke($app, $appManager, $appId);

    }//end invokeAppEnabledForAnyone()


    /**
     * GUARD (#1174): `isEnabledForAnyone()` must be REAL interface API here.
     *
     * This assertion is the reason the two behavioural tests below can be plain
     * `createMock()` calls. They used to branch on
     * `method_exists(IAppManager::class, 'isEnabledForAnyone')` and fall back to
     * PHPUnit's `addMethods()`, because `nextcloud/ocp` was pinned to
     * `dev-stable29` and that interface snapshot — three majors below the
     * `min-version="32"` this app declares — genuinely did not have the method.
     * A mock built with `addMethods()` fabricates the API it is verifying, which
     * is the exact failure this whole test class was written to close (#1103).
     *
     * With `nextcloud/ocp ^32.0` the method is declared, `onlyMethods` semantics
     * apply, and any future removal fails HERE with a clear message instead of
     * silently downgrading every mock below to a fabricated one.
     *
     * @return void
     */
    public function testIsEnabledForAnyoneIsRealInterfaceApi(): void
    {
        $this->assertTrue(
            method_exists(IAppManager::class, 'isEnabledForAnyone'),
            'isEnabledForAnyone() is @since 32.0.0 and this app declares min-version="32"; '
            .'if it is missing, nextcloud/ocp is pinned below the declared floor again (#1174)'
        );

    }//end testIsEnabledForAnyoneIsRealInterfaceApi()


    /**
     * GIVEN a disabled optional app WHEN it is feature-detected
     * THEN the helper reports false.
     *
     * @return void
     */
    public function testReportsFalseWhenTheAppIsNotEnabled(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForAnyone')->with('forms')->willReturn(false);

        $this->assertFalse($this->invokeAppEnabledForAnyone($appManager, 'forms'));

    }//end testReportsFalseWhenTheAppIsNotEnabled()


    /**
     * GIVEN an enabled optional app
     * WHEN it is feature-detected
     * THEN `isEnabledForAnyone()` answers and `isInstalled()` (deprecated 32.0.0)
     * is NOT called.
     *
     * @return void
     */
    public function testPrefersIsEnabledForAnyoneWhenTheServerHasIt(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->expects($this->once())
            ->method('isEnabledForAnyone')
            ->with('workflowengine')
            ->willReturn(true);
        $appManager->expects($this->never())->method('isInstalled');

        $this->assertTrue($this->invokeAppEnabledForAnyone($appManager, 'workflowengine'));

    }//end testPrefersIsEnabledForAnyoneWhenTheServerHasIt()


    /**
     * REGRESSION GUARD (#1103): no production source may call
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
            'these files call a method Nextcloud does not have; use Application::appEnabledForAnyone() instead'
        );

    }//end testNoProductionCodeCallsTheNonExistentIsEnabledForAnyUser()
}//end class
