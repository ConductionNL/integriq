<?php

/**
 * The binding an instance gets when it asks for the live Berichtenbox and the
 * live Berichtenbox is not there.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

use RuntimeException;

/**
 * REQ-DPA-005: the mock must not be reachable on an instance whose flag is
 * set. An operator who turns the flag on is saying "send real letters"; if the
 * live network leg is not there, the honest answer is a refusal naming what is
 * missing, not a mock reporting delivered for a letter that never left the
 * building.
 *
 * `BerichtenboxClientHttp` is the class that will replace this one. It is
 * blocked on three things the repo cannot supply for itself: Logius BBK OAuth
 * client credentials, a PKIoverheid Services-server certificate, and
 * `CredentialBrokerService::issueSigningMaterial` in OpenRegister, without
 * which `PkiOverheidCredentialResolver` fails closed for every reference.
 * Until all three clear, this is what a flagged instance resolves to.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-the-feature-flag-selects-the-binding-and-a-flagged-instance-without-credentials-refuses-req-dpa-005
 */
class BerichtenboxClientUnavailable extends BerichtenboxClient {
	/**
	 * What this binding is, in one word, for the structured log.
	 *
	 * @return string Always `unavailable`.
	 */
	public function flavour(): string {
		return 'unavailable';
	}//end flavour()

	/**
	 * Refuse the dispatch, naming what is missing.
	 *
	 * @param array<string,mixed> $message BBK 1.7-shaped envelope.
	 * @param string $certificateRef The certificate reference.
	 *
	 * @return array<string,mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 */
	public function dispatch(array $message, string $certificateRef): array {
		unset($message);

		throw new RuntimeException($this->refusal(certificateRef: $certificateRef));
	}//end dispatch()

	/**
	 * Refuse to verify a webhook, rather than answering signatureValid false
	 * and letting a caller read that as a checked answer.
	 *
	 * @param string $rawBody Raw inbound body bytes.
	 * @param array<string,string> $headers Inbound headers.
	 *
	 * @return array<string,mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 */
	public function verifyWebhook(string $rawBody, array $headers): array {
		unset($rawBody, $headers);

		throw new RuntimeException($this->refusal(certificateRef: ''));
	}//end verifyWebhook()

	/**
	 * Refuse a mailbox check.
	 *
	 * @param string $bsn The BSN, never inspected.
	 *
	 * @return array<string,mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 */
	public function checkMailbox(string $bsn): array {
		unset($bsn);

		throw new RuntimeException($this->refusal(certificateRef: ''));
	}//end checkMailbox()

	/**
	 * The refusal an operator reads.
	 *
	 * @param string $certificateRef The certificate reference that was named, if any.
	 *
	 * @return string The message.
	 */
	private function refusal(string $certificateRef): string {
		$missing = 'a PKIoverheid Services-server certificate';
		if (trim($certificateRef) !== '') {
			$missing = sprintf('a credential broker that can supply signing material for "%s"', $certificateRef);
		}

		return 'logius.berichtenbox.feature_flag is set, so this instance asked for the live Berichtenbox, '
			. 'and the live Berichtenbox is not available: it needs Logius BBK OAuth client credentials, '
			. $missing . '. Nothing was sent, and the mock is deliberately not served: a simulated delivery '
			. 'on a flagged instance would be indistinguishable from a real one.';
	}//end refusal()
}//end class
