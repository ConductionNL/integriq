<?php
/**
 * OpenConnector LTI Validation Exception.
 *
 * Thrown by the LTI 1.3 / LTI Advantage services (login initiation, launch
 * validation, service-token issuance, AGS/NRPS scope enforcement) on any
 * hard-reject condition. Carries the HTTP status the controller layer must
 * surface, per design.md D6: "Any failure is a hard reject (HTTP 401/400)
 * with no partial-trust fallback."
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

/**
 * Exception for LTI launch/login/service-token validation failures.
 *
 * Extends {@see AuthenticationException} so it is caught by any existing
 * `catch (AuthenticationException $e)` block, while adding an explicit HTTP
 * status so callers do not have to guess 400 vs 401 vs 403 from the message.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiValidationException extends AuthenticationException
{

    /**
     * The HTTP status code the controller layer MUST return for this failure.
     *
     * @var integer
     */
    private int $httpStatus;

    /**
     * Constructor.
     *
     * @param string  $message    The exception message (never includes raw key material).
     * @param array   $details    Details describing why validation failed.
     * @param integer $httpStatus The HTTP status code to surface (400, 401, or 403).
     */
    public function __construct(string $message, array $details=[], int $httpStatus=401)
    {
        parent::__construct(message: $message, details: $details);
        $this->httpStatus = $httpStatus;

    }//end __construct()

    /**
     * Get the HTTP status this failure MUST be surfaced as.
     *
     * @return integer
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;

    }//end getHttpStatus()
}//end class
