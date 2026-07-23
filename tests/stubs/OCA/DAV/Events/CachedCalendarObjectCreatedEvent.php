<?php

/**
 * Stub for OCA\DAV\Events\CachedCalendarObjectCreatedEvent.
 *
 * `dav` is an NC core app not present in the standalone composer
 * dev-environment. This stub mirrors the real class's shape (verified
 * against `apps/dav/lib/Events/CachedCalendarObjectCreatedEvent.php` in a
 * live NC 33 checkout during nextcloud-event-hub's implementation) so unit
 * tests can construct and inspect real instances without a full Nextcloud
 * server installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\DAV\Events;

use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\DAV\Events\CachedCalendarObjectCreatedEvent.
 */
class CachedCalendarObjectCreatedEvent extends Event
{


    /**
     * Constructor.
     *
     * @param integer $subscriptionId   The calendar subscription id.
     * @param array   $subscriptionData The subscription row.
     * @param array   $shares           The subscription's shares.
     * @param array   $objectData       The cached calendar object row.
     */
    public function __construct(
        private int $subscriptionId,
        private array $subscriptionData,
        private array $shares,
        private array $objectData
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * Get the calendar subscription id.
     *
     * @return integer
     */
    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;

    }//end getSubscriptionId()


    /**
     * Get the subscription row.
     *
     * @return array
     */
    public function getSubscriptionData(): array
    {
        return $this->subscriptionData;

    }//end getSubscriptionData()


    /**
     * Get the subscription's shares.
     *
     * @return array
     */
    public function getShares(): array
    {
        return $this->shares;

    }//end getShares()


    /**
     * Get the cached calendar object row.
     *
     * @return array
     */
    public function getObjectData(): array
    {
        return $this->objectData;

    }//end getObjectData()
}//end class
