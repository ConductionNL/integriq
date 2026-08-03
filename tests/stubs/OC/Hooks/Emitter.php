<?php
/**
 * Stub for OC\Hooks\Emitter — required by OCP\Files\IRootFolder in unit-test
 * environments where Nextcloud core is not fully bootstrapped.
 *
 * @category Stub
 * @package  OCA\OpenConnector\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Minimal stub for the Nextcloud hook emitter interface.
 *
 * The signatures below are a BYTE-FOR-BYTE mirror of the real
 * `OC\Hooks\Emitter` in nextcloud/server (identical on stable31, stable32 and
 * master) — deliberately untyped, with no `: void` return type.
 *
 * They must stay that way. This stub is loaded by name, so inside a real
 * Nextcloud tree it SHADOWS core's own interface, and core's implementors are
 * then checked against whatever is declared here. `OC\Files\Node\LazyFolder`
 * declares `listen($scope, $method, callable $callback)`; when this stub added
 * parameter types and a `: void` return, loading LazyFolder became a hard
 * PHP fatal:
 *
 *   Declaration of OC\Files\Node\LazyFolder::listen($scope, $method, callable
 *   $callback) must be compatible with OC\Hooks\Emitter::listen(string $scope,
 *   string $method, callable $callback): void
 *
 * That killed PHPUnit before it printed its banner — zero tests executed, all
 * four matrix legs identical. It stayed invisible because this repo's PHPUnit
 * job had never been switched on; a bare checkout has no core LazyFolder to
 * conflict with, so the suite is green locally either way.
 *
 * A stub that misdeclares the contract it stands in for is not a test double,
 * it is a defect. Mirror core exactly.
 */
interface Emitter
{

    /**
     * @param string   $scope    Hook scope.
     * @param string   $method   Hook method.
     * @param callable $callback Callback to invoke.
     *
     * @return void
     */
    public function listen($scope, $method, callable $callback);


    /**
     * @param string|null   $scope    Hook scope, optional.
     * @param string|null   $method   Hook method, optional.
     * @param callable|null $callback Optional specific callback to remove.
     *
     * @return void
     */
    public function removeListener($scope=null, $method=null, ?callable $callback=null);

}//end interface
