<?php

/**
 * Integriq ChannelCapabilities.
 *
 * What `describe()` answers. Without it a caller has to know each channel by
 * name, which is the coupling the adapter contract exists to remove, and a
 * reply path only learns that a channel cannot reply at send time.
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
 * What one channel can do.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
final class ChannelCapabilities {

	/**
	 * Constructor.
	 *
	 * @param string $channelId The channel id.
	 * @param string $label What to call the channel on screen.
	 * @param bool $canReply Whether a reply can leave over this channel.
	 * @param bool $supportsLocation Whether messages can carry a location.
	 * @param bool $supportsMedia Whether messages can carry media.
	 * @param array<int,string> $fields The structured fields this channel supplies, for a mapping screen.
	 */
	public function __construct(
		private readonly string $channelId,
		private readonly string $label,
		private readonly bool $canReply = false,
		private readonly bool $supportsLocation = false,
		private readonly bool $supportsMedia = false,
		private readonly array $fields = [],
	) {

	}//end __construct()

	/**
	 * The channel id.
	 *
	 * @return string The id.
	 */
	public function getChannelId(): string {
		return $this->channelId;

	}//end getChannelId()

	/**
	 * What to call the channel on screen.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return $this->label;

	}//end getLabel()

	/**
	 * Whether a reply can leave over this channel.
	 *
	 * @return bool True when it can.
	 */
	public function canReply(): bool {
		return $this->canReply;

	}//end canReply()

	/**
	 * Whether messages can carry a location.
	 *
	 * @return bool True when they can.
	 */
	public function supportsLocation(): bool {
		return $this->supportsLocation;

	}//end supportsLocation()

	/**
	 * Whether messages can carry media.
	 *
	 * @return bool True when they can.
	 */
	public function supportsMedia(): bool {
		return $this->supportsMedia;

	}//end supportsMedia()

	/**
	 * The structured fields this channel supplies.
	 *
	 * @return array<int,string> The field names.
	 */
	public function getFields(): array {
		return $this->fields;

	}//end getFields()

	/**
	 * The capabilities as JSON for the configuration screens.
	 *
	 * @return array<string,mixed> The description.
	 */
	public function toArray(): array {
		return [
			'channelId' => $this->channelId,
			'label' => $this->label,
			'canReply' => $this->canReply,
			'supportsLocation' => $this->supportsLocation,
			'supportsMedia' => $this->supportsMedia,
			'fields' => $this->fields,
		];

	}//end toArray()

}//end class
