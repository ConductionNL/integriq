<?php

/**
 * Integriq ChannelReportingCapabilities.
 *
 * What each channel can tell us about a message after it left. This is
 * declared, not inferred: the difference between a channel that reported
 * nothing and a channel that cannot report is the difference the recipient
 * states exist to keep.
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

/**
 * Declares, per channel, whether delivery and read can be reported at all.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-delivery-and-read-status-are-recorded-where-the-transport-reports-them-and-absent-where-it-does-not-req-ocl-005
 */
class ChannelReportingCapabilities {

	/**
	 * The channels integriq already carries, and what each can report.
	 *
	 * `delivery` and `read` say whether the channel is capable of reporting
	 * that state, never whether it did.
	 *
	 * @var array<string,array<string,bool>>
	 */
	public const DECLARED = [
		'mail' => ['delivery' => false, 'read' => false],
		'sms' => ['delivery' => true, 'read' => false],
		'notifynl' => ['delivery' => true, 'read' => false],
		'digitalPost' => ['delivery' => true, 'read' => true],
		'berichtenbox' => ['delivery' => true, 'read' => true],
		'messaging' => ['delivery' => true, 'read' => true],
		'peppol' => ['delivery' => true, 'read' => false],
		'webhook' => ['delivery' => true, 'read' => false],
		'log' => ['delivery' => false, 'read' => false],
	];

	/**
	 * Whether a channel can report a delivery at all.
	 *
	 * An unknown channel is treated as unable to report, which reads as
	 * `unsupported by this channel` rather than as a delivery nobody saw.
	 *
	 * @param string $channel The channel id.
	 *
	 * @return bool True when the channel can report deliveries.
	 */
	public function reportsDelivery(string $channel): bool {
		return (bool)(self::DECLARED[$channel]['delivery'] ?? false);

	}//end reportsDelivery()

	/**
	 * Whether a channel can report a read at all.
	 *
	 * @param string $channel The channel id.
	 *
	 * @return bool True when the channel can report reads.
	 */
	public function reportsRead(string $channel): bool {
		return (bool)(self::DECLARED[$channel]['read'] ?? false);

	}//end reportsRead()

	/**
	 * The delivery state a freshly handed-over recipient starts in.
	 *
	 * @param string $channel The channel id.
	 *
	 * @return string One of the {@see RecipientState} states.
	 */
	public function initialDeliveryState(string $channel): string {
		if ($this->reportsDelivery($channel) === true) {
			return RecipientState::NOT_REPORTED;
		}

		return RecipientState::UNSUPPORTED;

	}//end initialDeliveryState()

	/**
	 * The read state a freshly handed-over recipient starts in.
	 *
	 * @param string $channel The channel id.
	 *
	 * @return string One of the {@see RecipientState} states.
	 */
	public function initialReadState(string $channel): string {
		if ($this->reportsRead($channel) === true) {
			return RecipientState::NOT_REPORTED;
		}

		return RecipientState::UNSUPPORTED;

	}//end initialReadState()

	/**
	 * What every known channel can report, for a configuration screen.
	 *
	 * @return array<int,array<string,mixed>> The declarations, in channel order.
	 */
	public function describeAll(): array {
		$described = [];
		foreach (self::DECLARED as $channel => $capabilities) {
			$described[] = [
				'channel' => $channel,
				'delivery' => $capabilities['delivery'],
				'read' => $capabilities['read'],
			];
		}

		return $described;

	}//end describeAll()

}//end class
