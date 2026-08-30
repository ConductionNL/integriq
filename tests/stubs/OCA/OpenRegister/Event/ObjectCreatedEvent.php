<?php

/**
 * Stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub mirrors the real class's shape (verified
 * against openregister/lib/Event/ObjectCreatedEvent.php at the time
 * CloudEventListener was activated) so unit tests can construct and inspect
 * real instances without a full Nextcloud server installation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\OpenRegister\Event\ObjectCreatedEvent.
 */
class ObjectCreatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object entity that was created.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the created object entity.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
