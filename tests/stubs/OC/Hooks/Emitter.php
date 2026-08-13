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
 * The signatures below are deliberately untyped, matching
 * lib/private/Hooks/Emitter.php in Nextcloud core verbatim rather than the
 * stricter shape a stub would otherwise be written with.
 *
 * The guard in tests/bootstrap.php cannot keep this file out of a run that has
 * a real Nextcloud: interface_exists() is evaluated before base.php is
 * required, so core's autoloader is not yet registered and the check reports
 * "absent" even when core is about to define it. The stub therefore wins, and
 * core's OC\Files\Node\LazyFolder — which implements the untyped signature —
 * then fails to load against a typed declaration. That is a fatal at parse
 * time, so it killed the whole PHPUnit run before a single test executed, in
 * every matrix leg, on development as well as on branches.
 *
 * Keeping the declaration byte-compatible with core makes the stub harmless
 * whichever of the two happens to load first.
 */
interface Emitter {

	/**
	 * @param string $scope Hook scope.
	 * @param string $method Hook method.
	 * @param callable $callback Callback to invoke.
	 *
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string $scope Hook scope.
	 * @param string $method Hook method.
	 * @param callable|null $callback Optional specific callback to remove.
	 *
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);

}//end interface
