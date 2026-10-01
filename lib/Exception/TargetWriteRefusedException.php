<?php

/**
 * The target of a push answered a write with a 4xx.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Raised only for a push synchronization that declares
 * `targetConfig.conflictStatusProperty`; it names that property so the object
 * handler can mark the local object without reading the synchronization again.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
 */
class TargetWriteRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param int    $statusCode     The status the target answered.
	 * @param string $statusProperty The local property that records the conflict.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function __construct(
		private readonly int $statusCode,
		private readonly string $statusProperty,
	) {
		parent::__construct(
			message: sprintf('The target refused the write with status %d; the local change is kept and marked as a conflict.', $statusCode)
		);
	}//end __construct()

	/**
	 * The status the target answered.
	 *
	 * @return int The HTTP status.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The local property that records the conflict.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function getStatusProperty(): string {
		return $this->statusProperty;
	}//end getStatusProperty()
}//end class
