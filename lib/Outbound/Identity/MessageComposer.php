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
	 * @param array<string,mixed> $options `recipient`, `caseRef`, `category`, `baseUrl`; or, for a
	 *                                    send that was decided (opt-out-before-send), `decision`
	 *                                    (from OptOutRegistry) and `channel`, so the link the
	 *                                    decision carries is the one rendered.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>} The
	 *         composed body, the level used (which the log row records), the link when there is
	 *         one, and the List-Unsubscribe headers for an email.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
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

		$quoted = $this->quote(level: $level, history: $history);
		if ($quoted !== '') {
			$composed .= "\n\n" . $quoted;
		}

		if (array_key_exists('decision', $options) === true) {
			return $this->composeDecided(composed: $composed, level: $level, options: $options);
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
			'headers' => [],
		];

	}//end compose()

	/**
	 * Add the unsubscribe material a decision carries, in the channel's form.
	 *
	 * An SMS gets the short text, an email the body line and the
	 * List-Unsubscribe headers, every other channel the body line. A decision
	 * without material (an exempt category, a reply) adds nothing.
	 *
	 * @param string $composed The body so far.
	 * @param string $level The quoting level used.
	 * @param array<string,mixed> $options `decision`, `channel`, `caseRef`.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>} The result.
	 */
	private function composeDecided(string $composed, string $level, array $options): array {
		$decision = ($options['decision'] ?? []);
		$material = null;
		if (is_array($decision) === true && is_array($decision['unsubscribe'] ?? null) === true) {
			$material = $decision['unsubscribe'];
		}

		if ($material === null) {
			return ['body' => $composed, 'quotingLevel' => $level, 'unsubscribeLink' => null, 'headers' => []];
		}

		$channel = (string)($options['channel'] ?? '');
		$url = (string)($material['url'] ?? '');
		if (in_array($channel, RecipientKey::PHONE_CHANNELS, true) === true) {
			$smsText = (string)($material['smsText'] ?? '');
			if ($smsText !== '') {
				$composed .= "\n" . $smsText;
			}

			return ['body' => $composed, 'quotingLevel' => $level, 'unsubscribeLink' => $url, 'headers' => []];
		}

		$line = 'Geen berichten meer ontvangen: ';
		if (trim((string)($options['caseRef'] ?? '')) !== '') {
			$line = 'Geen updates meer over deze zaak ontvangen: ';
		}

		$composed .= "\n\n" . $line . $url;
		$headers = [];
		if ($channel === RecipientKey::CHANNEL_EMAIL && is_array($material['headers'] ?? null) === true) {
			$headers = $material['headers'];
		}

		return ['body' => $composed, 'quotingLevel' => $level, 'unsubscribeLink' => $url, 'headers' => $headers];

	}//end composeDecided()

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
