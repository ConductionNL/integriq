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
    public function listen(string $scope, string $method, callable $callback): void;


    /**
     * @param string        $scope    Hook scope.
     * @param string        $method   Hook method.
     * @param callable|null $callback Optional specific callback to remove.
     *
     * @return void
     */
    public function removeListener(string $scope='', string $method='', ?callable $callback=null): void;

}//end interface
