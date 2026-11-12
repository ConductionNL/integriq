<?php

/**
 * Integriq ComposedMessage.
 *
 * One outgoing message while MessageComposer builds it: the body so far and
 * the quoting level used, and the result it hands back once the unsubscribe
 * material is added.
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * A message being composed.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
final class ComposedMessage {

	/**
	 * Constructor.
	 *
	 * @param string $body The body so far.
	 * @param string $level The quoting level used, which the log row records.
	 */
	public function __construct(
		public string $body,
		public readonly string $level,
	) {
	}//end __construct()

	/**
	 * The composed message as MessageComposer::compose() returns it.
	 *
	 * @param string|null $link The unsubscribe link, when there is one.
	 * @param array<string,string> $headers The List-Unsubscribe headers, for an email.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>}
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function result(?string $link, array $headers): array {
		return ['body' => $this->body, 'quotingLevel' => $this->level, 'unsubscribeLink' => $link, 'headers' => $headers];
	}//end result()
}//end class
