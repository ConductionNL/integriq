<?php

/**
 * Integriq RecipientSendScope.
 *
 * Where one send goes, as an opt-out or consent row's scope is compared with
 * it: the channel, and the case and list the recipient names (empty when it
 * names none). Built by OptOutMatcher for one recipient.
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
 * The channel, case and list of one send.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
final class RecipientSendScope {

	/**
	 * The case the recipient names, or empty.
	 *
	 * @var string
	 */
	public readonly string $caseRef;

	/**
	 * The list the recipient names, or empty.
	 *
	 * @var string
	 */
	public readonly string $listRef;

	/**
	 * Constructor.
	 *
	 * @param string $channel The batch's channel.
	 * @param array<string,mixed> $recipient The recipient, with optional `caseRef` and `listRef`.
	 */
	public function __construct(
		public readonly string $channel,
		array $recipient,
	) {
		$this->caseRef = (string)($recipient['caseRef'] ?? '');
		$this->listRef = (string)($recipient['listRef'] ?? '');
	}//end __construct()
}//end class
