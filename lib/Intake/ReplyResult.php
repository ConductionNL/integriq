<?php

/**
 * Integriq ReplyResult.
 *
 * Three states, not two: sent, failed, and unsupported by this channel. The
 * third is the one that keeps a reply from silently leaving over a channel
 * the person never wrote on.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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

namespace OCA\Integriq\Intake;

/**
 * What happened to one reply.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-reply-goes-back-over-the-channel-it-arrived-on-req-ic-005
 */
final class ReplyResult {

	/**
	 * The reply left over the channel.
	 *
	 * @var string
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * The channel accepted replies but this one did not leave.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * This channel cannot carry a reply at all.
	 *
	 * @var string
	 */
	public const STATUS_UNSUPPORTED = 'unsupported by this channel';

	/**
	 * Constructor.
	 *
	 * @param string $status One of the three states.
	 * @param string $channelId The channel the reply was meant for.
	 * @param string|null $reference The channel's own id for the sent reply, when there is one.
	 * @param string|null $detail Why it failed, or why the channel cannot reply.
	 */
	private function __construct(
		private readonly string $status,
		private readonly string $channelId,
		private readonly ?string $reference = null,
		private readonly ?string $detail = null,
	) {

	}//end __construct()

	/**
	 * The reply left.
	 *
	 * @param string $channelId The channel.
	 * @param string|null $reference The channel's id for the reply.
	 *
	 * @return self The result.
	 */
	public static function sent(string $channelId, ?string $reference = null): self {
		return new self(self::STATUS_SENT, $channelId, $reference);

	}//end sent()

	/**
	 * The reply did not leave.
	 *
	 * @param string $channelId The channel.
	 * @param string $detail Why.
	 *
	 * @return self The result.
	 */
	public static function failed(string $channelId, string $detail): self {
		return new self(self::STATUS_FAILED, $channelId, null, $detail);

	}//end failed()

	/**
	 * This channel cannot carry a reply.
	 *
	 * @param string $channelId The channel.
	 * @param string|null $detail What the channel says about it.
	 *
	 * @return self The result.
	 */
	public static function unsupported(string $channelId, ?string $detail = null): self {
		return new self(self::STATUS_UNSUPPORTED, $channelId, null, $detail);

	}//end unsupported()

	/**
	 * The status.
	 *
	 * @return string One of the three states.
	 */
	public function getStatus(): string {
		return $this->status;

	}//end getStatus()

	/**
	 * The channel the reply was meant for.
	 *
	 * @return string The channel id.
	 */
	public function getChannelId(): string {
		return $this->channelId;

	}//end getChannelId()

	/**
	 * The channel's own id for the sent reply.
	 *
	 * @return string|null The reference, or null.
	 */
	public function getReference(): ?string {
		return $this->reference;

	}//end getReference()

	/**
	 * Why it failed, or why the channel cannot reply.
	 *
	 * @return string|null The detail, or null.
	 */
	public function getDetail(): ?string {
		return $this->detail;

	}//end getDetail()

	/**
	 * Whether the reply actually left.
	 *
	 * @return bool True when it was sent.
	 */
	public function isSent(): bool {
		return $this->status === self::STATUS_SENT;

	}//end isSent()

	/**
	 * The result as JSON.
	 *
	 * @return array<string,mixed> The result.
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'channelId' => $this->channelId,
			'reference' => $this->reference,
			'detail' => $this->detail,
		];

	}//end toArray()

}//end class
