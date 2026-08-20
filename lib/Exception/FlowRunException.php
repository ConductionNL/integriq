<?php

/**
 * OpenConnector Flow Run Exception.
 *
 * Raised for flow-configuration errors that must fail a run fatally,
 * independent of any step's own `onError` policy: duplicate step `order`
 * values (Task 1 acceptance) and an unresolvable `branch` step target
 * (flow-orchestration REQ-004). Distinct from an ordinary step-dispatch
 * failure (which is caught and routed through the step's `onError`
 * policy) — this exception represents the flow DEFINITION being invalid,
 * not a runtime call failing.
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown when a flow's definition is fatally invalid (duplicate step
 * `order`, or a `branch` step targeting a non-existent step `order`).
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004
 */
class FlowRunException extends Exception {
}//end class
