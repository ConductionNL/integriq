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
		$message = new ComposedMessage(body: rtrim($body), level: $this->quotingLevel(identity: $identity));
		$this->appendSignature(message: $message, identity: $identity);
		$this->appendQuote(message: $message, history: $history);

		if (array_key_exists('decision', $options) === true) {
			return $this->composeDecided(message: $message, options: $options);
		}

		return $this->composeCaseLinked(message: $message, options: $options);

	}//end compose()

	/**
	 * The identity's quoting level, or `last-message` when it names none this composer knows.
	 *
	 * @param array<string,mixed> $identity The identity.
	 *
	 * @return string The quoting level.
	 */
	private function quotingLevel(array $identity): string {
		$level = (string)($identity['quotingLevel'] ?? SenderIdentityService::QUOTING_LAST);
		if (in_array($level, SenderIdentityService::QUOTING_LEVELS, true) === false) {
			return SenderIdentityService::QUOTING_LAST;
		}

		return $level;

	}//end quotingLevel()

	/**
	 * Append the identity's signature, when it has one.
	 *
	 * @param ComposedMessage $message The message.
	 * @param array<string,mixed> $identity The identity.
	 *
	 * @return void
	 */
	private function appendSignature(ComposedMessage $message, array $identity): void {
		$signature = trim((string)($identity['signature'] ?? ''));
		if ($signature !== '') {
			$message->body .= "\n\n-- \n" . $signature;
		}

	}//end appendSignature()

	/**
	 * Append the quoted history for the message's level, when there is any.
	 *
	 * @param ComposedMessage $message The message.
	 * @param array<int,array<string,mixed>> $history The case history, newest last.
	 *
	 * @return void
	 */
	private function appendQuote(ComposedMessage $message, array $history): void {
		$quoted = $this->quote(level: $message->level, history: $history);
		if ($quoted !== '') {
			$message->body .= "\n\n" . $quoted;
		}

	}//end appendQuote()

	/**
	 * Add the case unsubscribe link of an undecided send, when recipient and case are known.
	 *
	 * @param ComposedMessage $message The message.
	 * @param array<string,mixed> $options `recipient`, `caseRef`, `category`, `baseUrl`.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>} The result.
	 */
	private function composeCaseLinked(ComposedMessage $message, array $options): array {
		$link = $this->caseLink(options: $options);
		if ($link !== null) {
			$message->body .= "\n\nGeen updates meer over deze zaak ontvangen: " . $link;
		}

		return $message->result(link: $link, headers: []);

	}//end composeCaseLinked()

	/**
	 * The case unsubscribe link, or null without a recipient and a case, or when there is none.
	 *
	 * @param array<string,mixed> $options `recipient`, `caseRef`, `category`, `baseUrl`.
	 *
	 * @return string|null The link.
	 */
	private function caseLink(array $options): ?string {
		$recipient = trim((string)($options['recipient'] ?? ''));
		$caseRef = trim((string)($options['caseRef'] ?? ''));
		if ($recipient === '' || $caseRef === '') {
			return null;
		}

		return $this->unsubscribe->linkFor(
			$recipient,
			$caseRef,
			(string)($options['category'] ?? ''),
			(string)($options['baseUrl'] ?? '')
		);

	}//end caseLink()

	/**
	 * Add the unsubscribe material a decision carries, in the channel's form.
	 *
	 * An SMS gets the short text, an email the body line and the
	 * List-Unsubscribe headers, every other channel the body line. A decision
	 * without material (an exempt category, a reply) adds nothing.
	 *
	 * @param ComposedMessage $message The message.
	 * @param array<string,mixed> $options `decision`, `channel`, `caseRef`.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>} The result.
	 */
	private function composeDecided(ComposedMessage $message, array $options): array {
		$material = $this->decisionMaterial(options: $options);
		if ($material === null) {
			return $message->result(link: null, headers: []);
		}

		$unsubscribe = new UnsubscribeMaterial(material: $material, channel: (string)($options['channel'] ?? ''));
		if ($unsubscribe->isPhone() === true) {
			return $this->composeShortText(message: $message, unsubscribe: $unsubscribe);
		}

		$message->body .= "\n\n" . $this->unsubscribeLine(options: $options) . $unsubscribe->url;

		return $message->result(link: $unsubscribe->url, headers: $unsubscribe->headers());

	}//end composeDecided()

	/**
	 * The decision's unsubscribe material, or null when the decision carries none.
	 *
	 * @param array<string,mixed> $options `decision`.
	 *
	 * @return array<string,mixed>|null The material.
	 */
	private function decisionMaterial(array $options): ?array {
		$decision = ($options['decision'] ?? []);
		if (is_array($decision) === true && is_array($decision['unsubscribe'] ?? null) === true) {
			return $decision['unsubscribe'];
		}

		return null;

	}//end decisionMaterial()

	/**
	 * Add the short text a phone channel gets, when the material has one.
	 *
	 * @param ComposedMessage $message The message.
	 * @param UnsubscribeMaterial $unsubscribe The material.
	 *
	 * @return array{body:string,quotingLevel:string,unsubscribeLink:string|null,headers:array<string,string>} The result.
	 */
	private function composeShortText(ComposedMessage $message, UnsubscribeMaterial $unsubscribe): array {
		$smsText = $unsubscribe->smsText();
		if ($smsText !== '') {
			$message->body .= "\n" . $smsText;
		}

		return $message->result(link: $unsubscribe->url, headers: []);

	}//end composeShortText()

	/**
	 * The text before the unsubscribe url: about this case when the send names one.
	 *
	 * @param array<string,mixed> $options `caseRef`.
	 *
	 * @return string The line's text.
	 */
	private function unsubscribeLine(array $options): string {
		if (trim((string)($options['caseRef'] ?? '')) !== '') {
			return 'Geen updates meer over deze zaak ontvangen: ';
		}

		return 'Geen berichten meer ontvangen: ';

	}//end unsubscribeLine()

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
