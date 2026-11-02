<?php

/**
 * Contract of the SmsDispatchService.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\SmsProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see SmsDispatchService} that other components call.
 */
interface SmsDispatchServiceInterface {
	/**
	 * Validate and send one SMS message: validates the recipient to E.164, dispatches
	 * through the configured provider, and persists the resulting `sms_message` record.
	 *
	 * @param string $to The raw recipient phone number (normalised to E.164 before dispatch).
	 * @param string $body Free-text body (audit context — template providers ignore it for the wire
	 *                     call).
	 * @param array $options Provider-specific send options (e.g. `templateId`, `personalisation`), plus
	 *                       `category` (default `service`) and `caseRef` for the opt-out decision.
	 * @param string|null $sourceApp Slug of the producing app (e.g. `procest`), stored for audit.
	 * @param string|null $objectUri Optional reference to the producing app's own object.
	 *
	 * @return ObjectEntity The created `sms_message` record.
	 *
	 * @throws SmsProviderException When the recipient is not a valid phone number, no active SMS source is
	 *                              configured, the opt-out list refuses the send (error code `opted-out`,
	 *                              `no-consent` or `authority-unavailable`), or the provider rejects/cannot
	 *                              reach the request.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function sendMessage(
		string $to,
		string $body,
		array $options = [],
		?string $sourceApp = null,
		?string $objectUri = null,
	): ObjectEntity;

	/**
	 * Poll the provider for a message's current delivery status and persist any change.
	 *
	 * @param string $uuid The `sms_message` uuid.
	 *
	 * @return ObjectEntity The (possibly updated) message.
	 *
	 * @throws SmsProviderException When the message is unknown or has no `providerMessageId` yet.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function pollStatus(string $uuid): ObjectEntity;

	/**
	 * Apply a verified provider delivery callback to its `sms_message` record.
	 *
	 * A callback for an unknown `providerMessageId` is recorded (logged) and MUST NOT
	 * throw — it returns null rather than surfacing an error to the (already-verified) caller.
	 *
	 * @param string $providerMessageId The provider-assigned message id from the callback.
	 * @param string $status The reported normalised status ({@see DeliveryResult::STATUSES}).
	 * @param string|null $detail An optional reason/detail message.
	 *
	 * @return ObjectEntity|null The updated message, or null when the providerMessageId is unknown.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function handleStatusCallback(string $providerMessageId, string $status, ?string $detail): ?ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The SMS source's `configuration` object.
	 *
	 * @return SmsProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function resolveProvider(array $configuration): SmsProviderInterface;

	/**
	 * Resolve the single active SMS source (`type=sms`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws SmsProviderException When no active SMS source is configured.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-send-endpoint-consumable-by-sibling-apps-req-006
	 */
	public function resolveActiveSource(): ObjectEntity;
}
