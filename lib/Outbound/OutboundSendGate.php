<?php

/**
 * Integriq OutboundSendGate.
 *
 * What every integriq sender does around a send to a person, in one place:
 * ask the opt-out list (OptOutRegistry::decideForSend, which fails closed),
 * compose the body with the unsubscribe material the decision carries
 * (MessageComposer), and keep an outbound log row (MessageRecorder). The
 * decision itself is made in OptOutRegistry only; this class adds no rule.
 *
 * The log row is an audit trail, not a precondition: a recorder that cannot
 * write is logged as a warning and the send goes on, so a broken log never
 * turns a refusal into a send or a send into silence.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask, compose, record.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */
class OutboundSendGate {

	/**
	 * The step a refused recipient fails in.
	 *
	 * @var string
	 */
	public const STEP_OPT_OUT = 'opt-out';

	/**
	 * The step a provider send fails in.
	 *
	 * @var string
	 */
	public const STEP_SEND = 'send';

	/**
	 * Constructor.
	 *
	 * @param OptOutRegistry $optOuts The one decision function.
	 * @param MessageComposer $composer Adds the unsubscribe material.
	 * @param MessageRecorder $recorder Keeps the outbound log row.
	 * @param LoggerInterface $logger Records a log row that could not be written.
	 */
	public function __construct(
		private readonly OptOutRegistry $optOuts,
		private readonly MessageComposer $composer,
		private readonly MessageRecorder $recorder,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
	public function check(string $channel, string $category, string $address, array $options = []): array {
		$recipient = ['address' => $address];
		if ((string)($options['caseRef'] ?? '') !== '') {
			$recipient['caseRef'] = (string)$options['caseRef'];
		}

		$decisions = $this->optOuts->decideForSend(
			channel: $channel,
			category: $category,
			recipients: [$recipient],
			options: $options
		);

		return $decisions[$address];

	}//end check()

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
	public function compose(string $body, array $decision, string $channel, string $caseRef = ''): array {
		$composed = $this->composer->compose(
			['quotingLevel' => 'none'],
			$body,
			[],
			['decision' => $decision, 'channel' => $channel, 'caseRef' => $caseRef]
		);

		return [
			'body' => $composed['body'],
			'unsubscribeLink' => $composed['unsubscribeLink'],
			'headers' => $composed['headers'],
		];

	}//end compose()

	/**
	 * Open the outbound log row for one send.
	 *
	 * @param string $channel The channel.
	 * @param string $subjectRef What the message is about.
	 * @param string $subject The subject line.
	 * @param string $body The body as it leaves.
	 * @param string $address The recipient key.
	 * @param array<string,mixed> $options `sourceApp`, `correlationId`, `context`.
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
	): ?string {
		try {
			$record = $this->recorder->start(
				subjectRef: $subjectRef,
				channel: $channel,
				subject: $subject,
				body: $body,
				recipients: [['address' => $address]],
				options: $options
			);

			return (string)$record->getUuid();
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[OutboundSendGate] the outbound log row could not be opened; the send is not affected',
				['channel' => $channel, 'exception' => $exception->getMessage()]
			);
			return null;
		}

	}//end open()

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
	public function handedOver(?string $uuid, string $address, ?string $reference = null): void {
		if ($uuid === null) {
			return;
		}

		try {
			$this->recorder->handedOver(uuid: $uuid, address: $address, reference: $reference);
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundSendGate] could not record the hand-over', ['uuid' => $uuid, 'exception' => $exception->getMessage()]);
		}

	}//end handedOver()

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
	public function failed(?string $uuid, string $address, string $step, string $reason): void {
		if ($uuid === null) {
			return;
		}

		try {
			$this->recorder->recipientFailed(uuid: $uuid, address: $address, step: $step, reason: $reason);
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundSendGate] could not record the failure', ['uuid' => $uuid, 'exception' => $exception->getMessage()]);
		}

	}//end failed()

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
	public function recordRefusal(string $channel, string $subjectRef, array $decision, array $options = []): ?string {
		$address = (string)($decision['address'] ?? '');
		if ($address === '') {
			$address = '(invalid address)';
		}

		$uuid = $this->open(channel: $channel, subjectRef: $subjectRef, subject: '', body: '', address: $address, options: $options);
		$this->failed(
			uuid: $uuid,
			address: $address,
			step: self::STEP_OPT_OUT,
			reason: (string)($decision['code'] ?? '') . ': ' . (string)($decision['reason'] ?? '')
		);

		return $uuid;

	}//end recordRefusal()

}//end class
