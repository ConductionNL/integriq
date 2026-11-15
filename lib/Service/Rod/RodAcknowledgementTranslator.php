<?php

/**
 * Integriq DUO ROD Acknowledgement Translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Translates a DUO ROD retour/acknowledgement XML envelope into a plain
 * status update array — `kenmerk`, `signaalcode`, `signaalOmschrijving`,
 * `accepted`. {@see \OCA\Integriq\Service\RodService::receiveReturn()} is
 * the caller that turns this into a {@see \OCA\Integriq\Event\RodAcknowledgementReceivedEvent}
 * — this class stays a pure XML-to-array translator with no dispatcher
 * dependency, mirroring InboundReturnTranslator.
 *
 * LITERAL-LEAK GUARD (inbound side): a retour with an empty or missing
 * `stuurgegevens.kenmerk` raises {@see RodTranslationException} BEFORE any
 * status update is returned — the caller MUST NEVER guess or fall back to
 * an unrelated message.
 *
 * XXE hardening: the retour XML originates from an external party (a real
 * DUO delivery). Parsing is delegated to the shared
 * {@see \OCA\Integriq\Service\Stuf\StufXmlParser} (`LIBXML_NONET` only) so
 * a malicious retour body cannot read local files or make outbound
 * requests via XML entities.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Stuf\RetourAcknowledgementReader;
use OCA\Integriq\Service\Stuf\RetourEnvelopeRefusedException;
use OCA\Integriq\Service\Stuf\StufXmlParser;

/**
 * Retour XML envelope -> plain acknowledgement status update.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
class RodAcknowledgementTranslator {

	/**
	 * Constructor.
	 *
	 * @param StufXmlParser $xmlParser Shared XXE-hardened XML parser.
	 */
	public function __construct(
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * Translate one retour XML envelope into a plain status update.
	 *
	 * @param string $xml The raw retour envelope XML, exactly as received on the wire.
	 *
	 * @return array{kenmerk: string, signaalcode: string, signaalOmschrijving: string|null, accepted: bool}
	 *
	 * @throws RodTranslationException When the XML is malformed or the `kenmerk` is missing/empty.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-an-accepted-acknowledgement-dispatches-an-event-with-accepted-true
	 */
	public function translate(string $xml): array {
		try {
			return (new RetourAcknowledgementReader(xmlParser: $this->xmlParser))->read(xml: $xml);
		} catch (RetourEnvelopeRefusedException $exception) {
			throw new RodTranslationException(message: $exception->getMessage());
		}
	}//end translate()
}//end class
