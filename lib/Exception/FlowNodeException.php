<?php
/**
 * OpenConnector Flow Node Exception.
 *
 * Raised at flow-run time by the node types OpenConnector contributes to
 * OpenRegister's flow engine (`openconnector.source-call`,
 * `openconnector.synchronization-run`) when a step cannot be completed: an
 * unresolvable Source or Synchronization, an unattributed run, an endpoint
 * that escapes its Source's location, a non-2xx or transport failure the
 * author has not opted into, or a fan-out that exceeds the configured ceiling.
 *
 * It is deliberately a RUN-time exception and it is deliberately thrown rather
 * than swallowed: `FlowEngine` reads the step's `onError` policy
 * (`stop` / `continue` / `dead_letter`) to decide what happens next, and a node
 * that catches its own failure defeats that policy and produces a run which
 * reports success while doing nothing. Authoring mistakes are a different
 * class and are raised from `validateConfig()` as `\UnexpectedValueException`.
 *
 * Messages MUST stay secret-free: they may name a Source reference, an
 * endpoint path, an HTTP status and a step id — never a credential value or a
 * rendered authentication header.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use RuntimeException;
use Throwable;

/**
 * Signals a hard failure inside an OpenConnector flow node.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-3-explicit-failure-fail-closed-attribution-validation-and-scope
 */
class FlowNodeException extends RuntimeException
{

    /**
     * Structured, secret-free detail about the failure.
     *
     * Carried on the exception rather than parsed back out of the message,
     * because a step running under `onError: continue` must place the HTTP
     * status, the Source and the endpoint onto the failed item as DATA a
     * downstream step can branch on — and reconstructing that from a
     * translated sentence would be guesswork.
     *
     * @var array<string, mixed>
     */
    private array $details;

    /**
     * Constructor.
     *
     * @param string         $message  The human-readable, secret-free message.
     * @param array          $details  Structured detail (status, source, endpoint, ...).
     * @param Throwable|null $previous The underlying failure, when there was one.
     */
    public function __construct(string $message, array $details=[], ?Throwable $previous=null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);

        $this->details = $details;

    }//end __construct()

    /**
     * The structured detail carried with this failure.
     *
     * @return array<string, mixed> The detail.
     *
     * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
     */
    public function getDetails(): array
    {
        return $this->details;

    }//end getDetails()
}//end class
