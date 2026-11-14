<?php

/**
 * Redaction contract of the SensitiveFieldRegistry: credential header names and issued tokens.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * The header and issued-token rules of {@see SensitiveFieldRegistry}, for the
 * call log that masks an exchange before it is stored.
 */
interface SecretRedactorInterface {
	/**
	 * Returns true when a header name looks like it carries a credential.
	 *
	 * @param string $name The header name to test.
	 *
	 * @return boolean Whether the header must be masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	public function isSensitiveHeaderName(string $name): bool;

	/**
	 * Masks the tokens an upstream issued in a JSON body, at any depth.
	 *
	 * @param string $body The response body as text.
	 *
	 * @return string The body with every issued token masked.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	public function redactIssuedTokensInJson(string $body): string;
}
