<?php

/**
 * Contract of the SmsStatusCallbackService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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
 *
 * @spec openspec/changes/archive/2026-11-24-messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see SmsStatusCallbackService} that other components call.
 */
interface SmsStatusCallbackServiceInterface {

	/**
	 * Configuration key of a source's callback token.
	 *
	 * @var string
	 */
	public const TOKEN_KEY = 'statusCallbackToken';

	/**
	 * Configuration key of a source's encrypted callback signing key.
	 *
	 * @var string
	 */
	public const SIGNING_KEY = 'encryptedWebhookSigningKey';

	/**
	 * The enabled SMS source of a provider whose callback token is the given one.
	 *
	 * @param string $provider The provider id from the callback URL.
	 * @param string $token    The token from the callback URL.
	 *
	 * @return ObjectEntity|null The source, raw, or null when none matches.
	 *
	 * @spec openspec/changes/archive/2026-11-24-messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function sourceFor(string $provider, string $token): ?ObjectEntity;

	/**
	 * Check a callback through the source's binding and apply the status it reports.
	 *
	 * @param ObjectEntity      $source   The source the token picked.
	 * @param SmsStatusCallback $callback The callback, without its signing key.
	 *
	 * @return ObjectEntity|null The updated message, or null when the callback names a message nobody sent.
	 *
	 * @throws SmsProviderException With code `bad_signature` when the vendor's signature does not verify,
	 *                              or `unreadable_callback` when the callback names no message.
	 *
	 * @spec openspec/changes/archive/2026-11-24-messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function receive(ObjectEntity $source, SmsStatusCallback $callback): ?ObjectEntity;
}//end interface
