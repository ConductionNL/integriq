<?php

/**
 * Stub for OCA\Tables\Event\AbstractRowEvent.
 *
 * See tests/stubs/OCA/Tables/Model/Public/Row.php for why this stub exists.
 * Mirrors `lib/Event/AbstractRowEvent.php` (public `nextcloud/tables`
 * source, `main` branch) minus the `Row2`-hydration constructor path (which
 * depends on Tables' internal DB entity, not needed for a test stub that is
 * constructed with a public `Row` directly).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Tables\Event;

use OCA\Tables\Model\Public\Row;
use OCP\EventDispatcher\Event;

/**
 * Minimal stub for OCA\Tables\Event\AbstractRowEvent.
 */
abstract class AbstractRowEvent extends Event
{

    /**
     * The affected row.
     *
     * @var Row
     */
    protected Row $row;


    /**
     * Constructor.
     *
     * @param Row $row The affected row.
     */
    public function __construct(Row $row)
    {
        parent::__construct();
        $this->row = $row;

    }//end __construct()


    /**
     * Get the affected row.
     *
     * @return Row
     */
    public function getRow(): Row
    {
        return $this->row;

    }//end getRow()
}//end class
