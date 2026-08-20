<?php

/**
 * OpenConnector Forms Configuration Exception.
 *
 * Raised for hard configuration errors on a `nextcloud-form` source or an
 * `action.kind: 'mapping'` outbound dispatch that are caller/request-scoped
 * (bad `sourceId`/`formId`, an ambiguous question-text resolution passed up
 * from `FormsAnswerResolver` — `nextcloud-forms-connector` REQ-003) rather
 * than a per-submission data problem. Discovery-endpoint callers map this to
 * HTTP 400; `EventService::dispatchMappingAction()` treats it as any other
 * thrown exception from a resolution/mapping step — a retryable failure
 * (REQ-004), NOT a non-retryable configuration error, since a specific
 * submission's ambiguous answer does not permanently misconfigure the
 * subscription itself. Mirrors `TablesConfigException` exactly.
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on a bad request-shaped `nextcloud-form` configuration, or an
 * ambiguous question-text reference.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003
 */
class FormsConfigException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable, secret-free message.
	 * @param int $statusCode The status code discovery-endpoint callers should map this to (default 400).
	 */
	public function __construct(string $message, int $statusCode = 400) {
		parent::__construct(message: $message, code: $statusCode);

	}//end __construct()
}//end class
