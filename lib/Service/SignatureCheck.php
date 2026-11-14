<?php

/**
 * Integriq SignatureCheck — one inbound signature verification: the raw body
 * and the received header, and the rule's scheme, secret and tolerance.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One verification in progress. Built and used by WebhookSignatureService only.
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-1
 */
final class SignatureCheck {
	/**
	 * The configured scheme.
	 *
	 * @var string
	 */
	public string $scheme = 'openconnector';

	/**
	 * The shared secret.
	 *
	 * @var string
	 */
	public string $secret = '';

	/**
	 * The configured tolerance in seconds.
	 *
	 * @var integer
	 */
	public int $tolerance = 0;

	/**
	 * Constructor.
	 *
	 * @param string $rawBody     The raw request body bytes (pre-decode).
	 * @param string $headerValue The received signature header value.
	 */
	public function __construct(
		public readonly string $rawBody,
		public readonly string $headerValue,
	) {
	}//end __construct()
}//end class
