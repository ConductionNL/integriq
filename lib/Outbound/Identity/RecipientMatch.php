<?php

/**
 * Integriq RecipientMatch.
 *
 * What the rows of a chunk say about one usable recipient: its own rows and
 * the opt-out among them that stops this send, if any.
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

use OCA\Integriq\Db\OptOut;

/**
 * One recipient matched against the rows of its chunk.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
final class RecipientMatch {
	/**
	 * Constructor.
	 *
	 * @param string              $key       The normalised address.
	 * @param array<string,mixed> $recipient The recipient as given.
	 * @param list<OptOut>        $mine      The recipient's own rows.
	 * @param OptOut|null         $optOut    The opt-out that stops this send, or null.
	 */
	public function __construct(
		public readonly string $key,
		public readonly array $recipient,
		public readonly array $mine,
		public readonly ?OptOut $optOut,
	) {
	}//end __construct()
}//end class
