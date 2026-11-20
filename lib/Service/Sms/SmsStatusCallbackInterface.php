<?php

/**
 * Integriq SMS status callback contract.
 *
 * A binding whose vendor posts delivery status implements this to check the
 * callback's signature, where the vendor signs, and to read the message id
 * and status it reports.
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

use OCA\Integriq\Exception\SmsProviderException;

/**
 * Reads a vendor's delivery callback.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
interface SmsStatusCallbackInterface {

	/**
	 * Whether the vendor signs its callbacks, so a source of this binding needs a signing key.
	 *
	 * @return bool True when the callback carries a signature this binding checks.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function signsStatusCallbacks(): bool;

	/**
	 * Check a callback and read the status it reports.
	 *
	 * @param array             $sourceConfiguration The SMS source's configuration.
	 * @param SmsStatusCallback $callback            The callback as it arrived.
	 *
	 * @return DeliveryResult The reported message id and normalised status.
	 *
	 * @throws SmsProviderException With code `bad_signature` when the signature is missing or wrong,
	 *                              or `unreadable_callback` when it names no message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function readStatusCallback(array $sourceConfiguration, SmsStatusCallback $callback): DeliveryResult;
}//end interface
