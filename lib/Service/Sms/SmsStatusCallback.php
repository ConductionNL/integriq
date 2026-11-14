<?php

/**
 * Integriq SMS status callback.
 *
 * One vendor delivery callback as it arrived: the URL it was posted to, its
 * raw body, its parameters and the headers a signature check reads, plus
 * the source's signing key, decrypted for the instant of the check.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

/**
 * A delivery callback a binding reads.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
final class SmsStatusCallback {

	/**
	 * Constructor.
	 *
	 * @param string                $url        The absolute URL the vendor posted to, query included.
	 * @param string                $rawBody    The request body, byte for byte.
	 * @param array<string, mixed>  $params     The decoded parameters (form or JSON body).
	 * @param array<string, string> $headers    Request headers by lower-case name.
	 * @param string|null           $signingKey The source's signing key, or null when it stores none.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $rawBody,
		public readonly array $params,
		public readonly array $headers = [],
		public readonly ?string $signingKey = null,
	) {

	}//end __construct()

	/**
	 * One header, by name in any case.
	 *
	 * @param string $name The header name.
	 *
	 * @return string The value, or an empty string when absent.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function header(string $name): string {
		return (string)($this->headers[strtolower($name)] ?? '');

	}//end header()
}//end class
