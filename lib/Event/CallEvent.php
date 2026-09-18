<?php

/**
 * A phone call reaching the KCC, with whatever is known about who is calling.
 *
 * Dispatched on every normalised call event so the case app's panel can show
 * an agent who is on the line before they pick up, and published on the
 * CloudEvents fan-out as `nl.conduction.integriq.call.<kind>`.
 *
 * `caller` is null when the number matched no partij, and when the number was
 * withheld. Both are dispatched: the phone rings either way, and an agent who
 * sees "unknown caller" can answer it while an agent who sees nothing thinks
 * the panel is broken. Which of the two it was is readable from
 * `callerNumber`, which is empty only when the number was withheld.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * One call event, with its caller context.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- a flat readonly envelope, the same
 * ADR-041 contract shape as DeliveryConcludedEvent; folding the fields into an array would
 * untype the contract the consuming panel mirrors.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */
class CallEvent extends Event {

	/**
	 * The phone is ringing and nobody has picked up.
	 */
	public const KIND_RINGING = 'ringing';

	/**
	 * An agent picked up.
	 */
	public const KIND_ANSWERED = 'answered';

	/**
	 * The call is over.
	 */
	public const KIND_ENDED = 'ended';

	/**
	 * The call was passed to somebody else.
	 */
	public const KIND_TRANSFERRED = 'transferred';

	/**
	 * The CloudEvents type prefix this event is published under.
	 */
	public const CLOUDEVENT_PREFIX = 'nl.conduction.integriq.call.';

	/**
	 * Constructor.
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @param string $callId The PBX's own call identifier, stable across this call's kinds.
	 * @param string $callerNumber The caller in E.164, or '' when the number was withheld or unreadable.
	 * @param array|null $caller The resolved partij, or null when the number matched nobody.
	 * @param array $openCases Open case references for that partij, newest first.
	 * @param string $agentId The agent the call is for, or ''.
	 * @param string $sourceId The CTI source this came through.
	 * @param string $at When the PBX says it happened, ISO 8601, or ''.
	 * @param integer $durationSeconds How long the call lasted; 0 except on `ended`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $callId,
		public readonly string $callerNumber,
		public readonly ?array $caller,
		public readonly array $openCases,
		public readonly string $agentId,
		public readonly string $sourceId,
		public readonly string $at,
		public readonly int $durationSeconds = 0,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The CloudEvents type this event is published under.
	 *
	 * @return string The event type.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function cloudEventType(): string {
		return (self::CLOUDEVENT_PREFIX.$this->kind);

	}//end cloudEventType()

	/**
	 * Whether the caller was identified.
	 *
	 * @return boolean True when a partij was resolved.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function isIdentified(): bool {
		return ($this->caller !== null);

	}//end isIdentified()

	/**
	 * Whether the caller withheld their number, as opposed to being unknown.
	 *
	 * The panel says different things for the two. "Number withheld" is a
	 * fact about the caller; "we do not know this number" is an invitation to
	 * ask for it and add it.
	 *
	 * @return boolean True when no number arrived at all.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function isAnonymous(): bool {
		return ($this->callerNumber === '');

	}//end isAnonymous()

}//end class
