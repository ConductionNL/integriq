<?php

/**
 * Contract of the OutboundSendGate.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound
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

namespace OCA\Integriq\Outbound;

/**
 * Public operations of {@see OutboundSendGate} that other components call.
 */
interface OutboundSendGateInterface {
	/**
	 * The step a provider send fails in.
	 *
	 * @var string
	 */
	public const STEP_SEND = 'send';

	/**
	 * Ask whether one recipient may be sent this message.
	 *
	 * @param string $channel The channel.
	 * @param string $category What kind of message this is.
	 * @param string $address The recipient as the sender has it.
	 * @param array<string,mixed> $options `caseRef`, `sourceApp`, `correlationId`, `baseUrl`, `inReplyTo`.
	 *
	 * @return array<string,mixed> The decision: `send`, `overridden`, `code`, `reason`, `unsubscribe`,
	 *         `address` (the key, never a plain BSN), `category`.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function check(string $channel, string $category, string $address, array $options = []): array;

	/**
	 * The body that leaves, with the decision's unsubscribe material.
	 *
	 * @param string $body The body the sender composed.
	 * @param array<string,mixed> $decision The decision from check().
	 * @param string $channel The channel.
	 * @param string $caseRef The case, or empty.
	 *
	 * @return array{body:string,unsubscribeLink:string|null,headers:array<string,string>} The result.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function compose(string $body, array $decision, string $channel, string $caseRef = ''): array;

	/**
	 * Open the outbound log row for one send.
	 *
	 * @param string $channel The channel.
	 * @param string $subjectRef What the message is about.
	 * @param string $subject The subject line.
	 * @param string $body The body as it leaves.
	 * @param string $address The recipient key.
	 * @param array<string,mixed> $options `sourceApp`, `correlationId`, `caseRef`, `context`.
	 * @param array<string,mixed>|null $decision The decision this send was made under. Its category
	 *                                           and the case are kept on the row, so a retry asks again
	 *                                           as the first send did.
	 *
	 * @return string|null The row uuid, or null when it could not be written.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function open(
		string $channel,
		string $subjectRef,
		string $subject,
		string $body,
		string $address,
		array $options = [],
		?array $decision = null,
	): ?string;

	/**
	 * Record that the transport took the copy.
	 *
	 * @param string|null $uuid The row, or null when none was opened.
	 * @param string $address The recipient key.
	 * @param string|null $reference The transport's reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function handedOver(?string $uuid, string $address, ?string $reference = null): void;

	/**
	 * Record that the copy did not leave, and why.
	 *
	 * @param string|null $uuid The row, or null when none was opened.
	 * @param string $address The recipient key.
	 * @param string $step The step it failed in.
	 * @param string $reason Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function failed(?string $uuid, string $address, string $step, string $reason): void;

	/**
	 * Open a row for a refused send and mark it refused in one go.
	 *
	 * @param string $channel The channel.
	 * @param string $subjectRef What the message is about.
	 * @param array<string,mixed> $decision The refusing decision.
	 * @param array<string,mixed> $options `sourceApp`, `correlationId`.
	 *
	 * @return string|null The row uuid.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function recordRefusal(string $channel, string $subjectRef, array $decision, array $options = []): ?string;
}
