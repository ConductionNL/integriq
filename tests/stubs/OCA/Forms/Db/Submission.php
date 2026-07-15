<?php

/**
 * Stub for OCA\Forms\Db\Submission.
 *
 * See Form.php in the same directory for why this stub exists. Verified
 * against the public `nextcloud/forms` source (`lib/Db/Submission.php`,
 * `main` branch, fetched during nextcloud-event-hub's implementation).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Forms\Db;

/**
 * Minimal stub for OCA\Forms\Db\Submission.
 */
class Submission
{


    /**
     * Constructor.
     *
     * @param integer $id     The submission id.
     * @param integer $formId The owning form id.
     * @param string  $userId The submitting user id.
     */
    public function __construct(private int $id, private int $formId, private string $userId='')
    {

    }//end __construct()


    /**
     * Get the submission id.
     *
     * @return integer
     */
    public function getId(): int
    {
        return $this->id;

    }//end getId()


    /**
     * Get the owning form id.
     *
     * @return integer
     */
    public function getFormId(): int
    {
        return $this->formId;

    }//end getFormId()


    /**
     * Read the submission as a plain array (mirrors the real class's `read()`).
     *
     * @return array
     */
    public function read(): array
    {
        return [
            'id'     => $this->id,
            'formId' => $this->formId,
            'userId' => $this->userId,
        ];

    }//end read()
}//end class
