<?php

/**
 * Integriq RecipientState.
 *
 * Three states, because two force a lie. Most transports report nothing after
 * a successful hand-over, and "not failed" is not "delivered": a term that
 * runs from a delivery nobody reported is a term that runs from an
 * assumption. A channel that cannot report at all says so, which is a
 * different fact again.
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
 * The delivery and read states a recipient row can carry.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-delivery-and-read-status-are-recorded-where-the-transport-reports-them-and-absent-where-it-does-not-req-ocl-005
 */
final class RecipientState {

	/**
	 * The transport reported this state.
	 *
	 * @var string
	 */
	public const REPORTED = 'reported';

	/**
	 * The channel can report this state and has not.
	 *
	 * @var string
	 */
	public const NOT_REPORTED = 'not reported';

	/**
	 * This channel cannot report this state at all.
	 *
	 * @var string
	 */
	public const UNSUPPORTED = 'unsupported by this channel';

	/**
	 * Every state a delivery or a read can be in.
	 *
	 * @var array<int,string>
	 */
	public const ALL = [self::REPORTED, self::NOT_REPORTED, self::UNSUPPORTED];

	/**
	 * The recipient has not been handed over yet.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * The transport accepted this recipient's copy.
	 *
	 * @var string
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * This recipient's copy did not leave.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Every per-recipient status.
	 *
	 * @var array<int,string>
	 */
	public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED];

}//end class
