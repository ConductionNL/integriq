<?php

/**
 * Contract of the NotifyNlEmailService.
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SmsProviderException;

/**
 * Public operations of {@see NotifyNlEmailService} that other components call.
 */
interface NotifyNlEmailServiceInterface {

	/**
	 * Error code of a send to an address that is not an e-mail address.
	 *
	 * @var string
	 */
	public const ERROR_INVALID_ADDRESS = 'invalid-address';

	/**
	 * Error code of a send without a NotifyNL template id.
	 *
	 * @var string
	 */
	public const ERROR_MISSING_TEMPLATE = 'missing-template';

	/**
	 * Send one NotifyNL e-mail and record it per recipient.
	 *
	 * @param string              $emailAddress    The recipient.
	 * @param string              $templateId      The NotifyNL template id.
	 * @param array<string,mixed> $personalisation The template's personalisation values.
	 * @param array<string,mixed> $options         `category` (default `service`), `caseRef`, `sourceApp`,
	 *                                             `objectUri`, `sourceSlug`.
	 *
	 * @return array{id: string|null, reference: string, status: string} The log row, NotifyNL's id and `sent`.
	 *
	 * @throws SmsProviderException With code `invalid-address` or `missing-template` before anything is
	 *                              sent or recorded, `unknown-source` when no NotifyNL source answers,
	 *                              the opt-out decision code when the list refuses, or NotifyNL's failure.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function sendEmail(string $emailAddress, string $templateId, array $personalisation, array $options = []): array;

	/**
	 * Apply a NotifyNL mail delivery callback to the log row it names.
	 *
	 * @param string      $reference         The callback's `reference`: the log row uuid.
	 * @param string      $providerMessageId NotifyNL's notification id.
	 * @param string      $status            The normalised status.
	 * @param string|null $detail            What NotifyNL said.
	 *
	 * @return bool True when a recipient on a log row was updated.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function applyCallback(string $reference, string $providerMessageId, string $status, ?string $detail): bool;
}//end interface
