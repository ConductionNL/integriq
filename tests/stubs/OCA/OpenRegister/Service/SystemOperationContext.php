<?php

/**
 * Stub for OCA\OpenRegister\Service\SystemOperationContext.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment, so every OpenRegister class integriq's lib/
 * touches needs a stub here or the call site raises `Class not found` at
 * runtime — which is what happened: three SynchronizationContractServiceTest
 * cases errored with `Class "OCA\OpenRegister\Service\SystemOperationContext"
 * not found` and read as broken persist logic rather than a missing stub.
 *
 * 🔑 THE REAL CLASS IS A DEPTH COUNTER AROUND A CALLABLE, and this stub
 * reproduces the only property a unit test can observe: `run()` INVOKES the
 * operation and returns whatever it returns. Anything less makes it a no-op
 * that swallows the write — the test would then pass while asserting nothing,
 * which is worse than the error it replaces.
 *
 * `isActive()` returns false. The real one answers "are we inside a system
 * operation", and outside a `run()` call that answer is false; no integriq
 * unit test asserts the true case, and returning true unconditionally would let
 * a test claim suppression that never happened.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for OCA\OpenRegister\Service\SystemOperationContext.
 */
class SystemOperationContext {

	/**
	 * Run an operation inside a system-operation context.
	 *
	 * @param callable $operation The operation to run.
	 *
	 * @return mixed Whatever the operation returns.
	 */
	public static function run(callable $operation) {
		return $operation();
	}

	/**
	 * Whether a system operation is currently in progress.
	 *
	 * @return bool Always false in the stub; see the file docblock.
	 */
	public static function isActive(): bool {
		return false;
	}
}
