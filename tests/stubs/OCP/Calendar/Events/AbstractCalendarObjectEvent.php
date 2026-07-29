<?php

/**
 * Stub for OCP\Calendar\Events\AbstractCalendarObjectEvent.
 *
 * `OCP\Calendar\Events\*` is real stable OCP API but `@since 32.0.0` —
 * newer than the `nextcloud/ocp: dev-stable29` composer dev-dependency this
 * app pins for standalone unit tests, so the class is absent from
 * `vendor/nextcloud/ocp`. This stub mirrors the real class's shape (verified
 * against `lib/public/Calendar/Events/AbstractCalendarObjectEvent.php` in a
 * live NC 33 checkout during nextcloud-event-hub's implementation) so unit
 * tests can construct and inspect real instances without a full Nextcloud
 * server installation. Guarded by class_exists() in bootstrap.php so it
 * never clobbers the real class on an NC32+ instance.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCP\Calendar\Events;

use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCP\Calendar\Events\AbstractCalendarObjectEvent.
 */
abstract class AbstractCalendarObjectEvent extends Event
{


    /**
     * Constructor.
     *
     * @param integer $calendarId   The calendar id.
     * @param array   $calendarData The calendar row.
     * @param array   $shares       The calendar's shares.
     * @param array   $objectData   The calendar object row.
     */
    public function __construct(
        private int $calendarId,
        private array $calendarData,
        private array $shares,
        private array $objectData
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * Get the calendar id.
     *
     * @return integer
     */
    public function getCalendarId(): int
    {
        return $this->calendarId;

    }//end getCalendarId()


    /**
     * Get the calendar row.
     *
     * @return array
     */
    public function getCalendarData(): array
    {
        return $this->calendarData;

    }//end getCalendarData()


    /**
     * Get the calendar's shares.
     *
     * @return array
     */
    public function getShares(): array
    {
        return $this->shares;

    }//end getShares()


    /**
     * Get the calendar object row.
     *
     * @return array
     */
    public function getObjectData(): array
    {
        return $this->objectData;

    }//end getObjectData()
}//end class
