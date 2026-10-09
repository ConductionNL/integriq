<?php

/**
 * Integriq — the MijnOverheid Berichtenbox client (abstract base).
 *
 * The contract follows the interface Logius publishes, in the Technische
 * Aansluithandleiding MijnOverheid Berichtenbox 1.6.4:
 *
 *   - a letter is a `GLOBE-R-BV-Request` batch, sent over Digikoppeling ebMS;
 *   - a subscription is checked with WUS `ValidateAbonnementen`;
 *   - a result comes back as a `GLOBE-R-BV-Result` ebMS message.
 *
 * Authentication is two-way TLS with a PKIoverheid certificate. Nothing is
 * signed: "Het is niet mogelijk om de payload van het ebMS bericht digitaal te
 * ondertekenen" (section 3.2). There is no OAuth, no webhook and no read
 * status.
 *
 * Concrete bindings:
 *   - {@see BerichtenboxClientMock}: no network, every send simulated. The default.
 *   - {@see BerichtenboxClientHttp}: the live binding, with
 *     `logius.berichtenbox.feature_flag` set.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 * @link https://www.logius.nl/domeinen/interactie/mijnoverheid/documentatie/technische-aansluithandleiding-mijnoverheid-berichtenbox
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

/**
 * The Berichtenbox operations integriq uses.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006
 */
abstract class BerichtenboxClient {
	/**
	 * Which binding this is, for the structured log: `mock` or `https`.
	 *
	 * @return string The flavour.
	 */
	abstract public function flavour(): string;

	/**
	 * What this source still lacks before this binding can send, one line per missing value.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals, empty when the binding can send.
	 */
	abstract public function configurationRefusals(array $config): array;

	/**
	 * Ask whether these citizens take letters of this BerichtType from this sender.
	 *
	 * @param array<int,string> $bsns 1 to 250 BSNs.
	 * @param string $berichtType The BerichtType code, as configured in the Leveranciersportaal.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,bool> BSN => whether a letter may be sent.
	 *
	 * @throws BerichtenboxException When Logius answers a fault or cannot be reached.
	 */
	abstract public function checkSubscriptions(array $bsns, string $berichtType, array $config): array;

	/**
	 * Offer one built batch to Logius.
	 *
	 * @param BerichtenboxBatch $batch The batch, already checked against the XSD.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The transport (ebMS) message id.
	 *
	 * @throws BerichtenboxException When the batch could not be handed over.
	 */
	abstract public function deliver(BerichtenboxBatch $batch, array $config): string;

	/**
	 * What Logius answered for the letters of this source, keyed by BerichtID.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,array{code:string,stadium:string,resultMessageId:string}> The results.
	 */
	abstract public function results(array $config): array;

	/**
	 * Transport events for this source's letters, keyed by transport message id.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,string> Transport message id => `DELIVERED`, `FAILED` or `EXPIRED`.
	 */
	abstract public function transportEvents(array $config): array;

	/**
	 * The result for this letter is stored; the adapter may let go of it.
	 *
	 * @param string $resultMessageId The result message id from {@see results()}.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	abstract public function resultProcessed(string $resultMessageId, array $config): void;

	/**
	 * The transport event for this message is handled; the adapter may let go of it.
	 *
	 * @param string $transportMessageId The transport message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	abstract public function eventProcessed(string $transportMessageId, array $config): void;
}//end class
