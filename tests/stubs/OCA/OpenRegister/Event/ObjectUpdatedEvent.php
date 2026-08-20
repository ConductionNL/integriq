<?php

/**
 * Stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub mirrors the real class's shape (verified
 * against openregister/lib/Event/ObjectUpdatedEvent.php at the time
 * CloudEventListener was activated — including the nullable `$oldObject`) so
 * unit tests can construct and inspect real instances without a full
 * Nextcloud server installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\OpenRegister\Event\ObjectUpdatedEvent.
 */
class ObjectUpdatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $newObject The object entity after update.
	 * @param ObjectEntity|null $oldObject The object entity before update (null if not available).
	 */
	public function __construct(
		private readonly ObjectEntity $newObject,
		private readonly ?ObjectEntity $oldObject = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the updated object entity (alias for getNewObject()).
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->newObject;
	}//end getObject()

	/**
	 * Get the updated object entity.
	 *
	 * @return ObjectEntity
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * Get the original object entity.
	 *
	 * @return ObjectEntity|null
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
}//end class
