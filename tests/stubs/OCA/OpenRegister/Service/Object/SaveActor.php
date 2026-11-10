<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\SaveActor.
 *
 * Same constructor as the OpenRegister value object (with the defaults
 * openregister #9123 gives it), so a test builds and reads it the way
 * production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * Who a save runs as and who will own the object.
 */
final class SaveActor {
	/**
	 * @param IUser|null $currentUser The acting user, or null for the session user.
	 * @param string|null $owner The owner to record.
	 * @param bool $unowned Save without an owner.
	 */
	public function __construct(
		public readonly ?IUser $currentUser = null,
		public readonly ?string $owner = null,
		public readonly bool $unowned = false,
	) {
	}
}
