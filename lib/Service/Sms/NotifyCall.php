<?php

/**
 * Integriq NotifyNL call.
 *
 * One call to the NotifyNL REST API: the HTTP method, the path relative to
 * the base URL, and the JSON body when there is one.
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

/**
 * The values of one NotifyNL call.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
 */
final class NotifyCall {

	/**
	 * Constructor.
	 *
	 * @param string     $method   The HTTP method.
	 * @param string     $path     The path, relative to the base URL.
	 * @param array|null $jsonBody The JSON body, or null for none.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly ?array $jsonBody=null,
	) {

	}//end __construct()
}//end class
