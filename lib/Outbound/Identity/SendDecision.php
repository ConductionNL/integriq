<?php

/**
 * Integriq SendDecision.
 *
 * What OptOutRegistry decides for one recipient while it decides: the
 * address and category it is about, the code and reason, whether an exempt
 * category overrode an opt-out, the unsubscribe material, and the detail a
 * suppression logs. OptOutRegistry turns it into the decision array.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * One recipient's decision being built.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
final class SendDecision {

	/**
	 * The decision code.
	 *
	 * @var string
	 */
	public string $code = '';

	/**
	 * The reason, empty for a plain allow.
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * Whether an exempt category was sent over an opt-out.
	 *
	 * @var bool
	 */
	public bool $overridden = false;

	/**
	 * The unsubscribe material of an allowed send, when there is any.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $unsubscribe = null;

	/**
	 * What a suppression logs besides its code.
	 *
	 * @var array<string,mixed>
	 */
	public array $logDetail = [];

	/**
	 * Constructor.
	 *
	 * @param string $address The normalised address, or what was given when there is none.
	 * @param string $category The category decided.
	 */
	public function __construct(
		public string $address,
		public readonly string $category,
	) {
	}//end __construct()

	/**
	 * Set the code and reason.
	 *
	 * @param string $code The decision code.
	 * @param string $reason The reason.
	 *
	 * @return self The same decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function because(string $code, string $reason): self {
		$this->code = $code;
		$this->reason = $reason;

		return $this;
	}//end because()

	/**
	 * The same decision about another address.
	 *
	 * @param string $address The address.
	 *
	 * @return self A copy for that address.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function forAddress(string $address): self {
		$copy = clone $this;
		$copy->address = $address;

		return $copy;
	}//end forAddress()

	/**
	 * The decision as the registry returns it.
	 *
	 * @param bool $send Whether the message is sent.
	 *
	 * @return array<string,mixed> The decision: send, overridden, code, reason, unsubscribe, address, category.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function toArray(bool $send): array {
		return [
			'send' => $send,
			'overridden' => $this->overridden,
			'code' => $this->code,
			'reason' => $this->reason,
			'unsubscribe' => $this->unsubscribe,
			'address' => $this->address,
			'category' => $this->category,
		];
	}//end toArray()
}//end class
