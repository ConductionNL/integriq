<?php

/**
 * Integriq MessageComposer.
 *
 * Composes what actually leaves: the body, the signature of the identity, as
 * much of the case history as the identity quotes, and the unsubscribe link
 * where the category allows one. The whole dossier quoted back to a
 * bezwaarmaker is a disclosure, so the level is a choice and `none` is a real
 * option.
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
 * Builds the body a recipient receives.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class MessageComposer {

	/**
	 * Constructor.
	 *
	 * @param UnsubscribeTokenService $unsubscribe Mints the link, or says there is none.
	 */
	public function __construct(private readonly UnsubscribeTokenService $unsubscribe) {

	}//end __construct()

	/**
	 * Compose one outgoing message.
	 *
	 * @param array<string,mixed> $identity The identity it leaves under.
	 * @param string $body What the handler wrote.
	 * @param array<int,array<string,mixed>> $history The case history, newest last, as
	 *                                                `{at, from, text}`.
	 * @param array<string,mixed> $options `recipient`, `caseRef`, `category`, `baseUrl`.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null} The composed body,
	 *         the level used (which the log row records) and the link, when there is one.
	 */
	public function compose(array $identity, string $body, array $history = [], array $options = []): array {
		$level = (string)($identity['quotingLevel'] ?? SenderIdentityService::QUOTING_LAST);
		if (in_array($level, SenderIdentityService::QUOTING_LEVELS, true) === false) {
			$level = SenderIdentityService::QUOTING_LAST;
		}

		$composed = rtrim($body);

		$signature = trim((string)($identity['signature'] ?? ''));
		if ($signature !== '') {
			$composed .= "\n\n-- \n" . $signature;
		}

		$quoted = $this->quote($level, $history);
		if ($quoted !== '') {
			$composed .= "\n\n" . $quoted;
		}

		$link = null;
		$recipient = trim((string)($options['recipient'] ?? ''));
		$caseRef = trim((string)($options['caseRef'] ?? ''));
		if ($recipient !== '' && $caseRef !== '') {
			$link = $this->unsubscribe->linkFor(
				$recipient,
				$caseRef,
				(string)($options['category'] ?? ''),
				(string)($options['baseUrl'] ?? '')
			);
		}

		if ($link !== null) {
			$composed .= "\n\nGeen updates meer over deze zaak ontvangen: " . $link;
		}

		return [
			'body' => $composed,
			'quotingLevel' => $level,
			'unsubscribeLink' => $link,
		];

	}//end compose()

	/**
	 * The quoted history for one level.
	 *
	 * @param string $level The quoting level.
	 * @param array<int,array<string,mixed>> $history The case history, newest last.
	 *
	 * @return string The quoted block, empty when the level is `none`.
	 */
	private function quote(string $level, array $history): string {
		if ($level === SenderIdentityService::QUOTING_NONE || $history === []) {
			return '';
		}

		$entries = $history;
		if ($level === SenderIdentityService::QUOTING_LAST) {
			$entries = [end($history)];
		}

		$lines = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$lines[] = 'Op ' . (string)($entry['at'] ?? '') . ' schreef ' . (string)($entry['from'] ?? '') . ':';
			foreach (explode("\n", (string)($entry['text'] ?? '')) as $line) {
				$lines[] = '> ' . $line;
			}

			$lines[] = '';
		}

		return rtrim(implode("\n", $lines));

	}//end quote()

}//end class
