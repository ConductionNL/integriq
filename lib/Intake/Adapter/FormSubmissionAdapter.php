<?php

/**
 * Integriq FormSubmissionAdapter.
 *
 * A submitted form arrives as an object over a signed webhook and becomes a
 * case by a mapping, which is the Open Formulieren pattern generalised: the
 * mapping is the configuration that makes it work without code, so a new form
 * is a rule rather than a release.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake\Adapter
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\ReplyResult;

/**
 * Receives form submissions posted as objects.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-submission-arrives-over-a-signed-webhook-and-maps-to-a-case-type-req-ic-003
 */
class FormSubmissionAdapter implements IntakeChannelAdapterInterface {

	/**
	 * The channel id.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'form-submission';

	/**
	 * The channel id this adapter answers to.
	 *
	 * @return string The channel id.
	 */
	public function getChannelId(): string {
		return self::CHANNEL_ID;

	}//end getChannelId()

	/**
	 * What this channel can do.
	 *
	 * @return ChannelCapabilities The capabilities.
	 */
	public function describe(): ChannelCapabilities {
		return new ChannelCapabilities(
			channelId: self::CHANNEL_ID,
			label: 'Form submission',
			canReply: false,
			supportsLocation: false,
			supportsMedia: true,
			fields: ['formId', 'submissionId']);

	}//end describe()

	/**
	 * Normalise one submission.
	 *
	 * The submission's own `data` becomes the structured fields a mapping
	 * names, so a rule reads `fields.<name>` and never the raw payload.
	 *
	 * @param array<string,mixed> $payload The submission.
	 *
	 * @return InboundMessage The normalised message.
	 *
	 * @throws IntakeChannelException When the submission names no id or no form.
	 */
	public function receive(array $payload): InboundMessage {
		$submissionId = trim((string)($payload['submissionId'] ?? ''));
		$formId = trim((string)($payload['formId'] ?? ''));
		if ($submissionId === '' || $formId === '') {
			throw new IntakeChannelException(
				message: 'A "' . self::CHANNEL_ID . '" payload must carry a submissionId and a formId.'
			);
		}

		$submitter = IntakePayloadReader::mapOrEmpty(value: ($payload['submitter'] ?? []));

		$fields = IntakePayloadReader::mapOrEmpty(value: ($payload['data'] ?? []));
		$fields['formId'] = $formId;
		$fields['submissionId'] = $submissionId;

		return new InboundMessage(
			channelId: self::CHANNEL_ID,
			externalId: $submissionId,
			correspondent: [
				'id' => (string)($submitter['bsn'] ?? $submitter['kvk'] ?? ''),
				'name' => (string)($submitter['name'] ?? ''),
				'address' => (string)($submitter['email'] ?? ''),
				'phone' => (string)($submitter['phone'] ?? ''),
			],
			text: (string)($payload['summary'] ?? ''),
			attachments: IntakePayloadReader::decodeFiles(
				items: ($payload['attachments'] ?? []),
				defaults: new IntakeFileNaming(nameFormat: 'bijlage-%d', mime: 'application/octet-stream')
			),
			location: null,
			media: [],
			rawPayload: $payload,
			receivedAt: IntakePayloadReader::optionalString(value: ($payload['submittedAt'] ?? null)),
			fields: $fields);

	}//end receive()

	/**
	 * A form submission carries no reply leg.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult Always unsupported, never a fallback to another channel.
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult {
		return ReplyResult::unsupported(
			self::CHANNEL_ID,
			'A form submission is one way; answer the case on a channel the submitter gave you.'
		);

	}//end reply()

}//end class
