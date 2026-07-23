<?php

/**
 * Stub for OCA\DAV\Events\CachedCalendarObjectDeletedEvent.
 *
 * See CachedCalendarObjectCreatedEvent.php in this directory for why this
 * stub exists.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\DAV\Events;

use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\DAV\Events\CachedCalendarObjectDeletedEvent.
 */
class CachedCalendarObjectDeletedEvent extends Event
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
