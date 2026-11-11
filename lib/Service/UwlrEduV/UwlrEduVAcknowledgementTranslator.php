<?php

/**
 * Integriq UWLR/Edu-V Acknowledgement Translator.
 *
 * Translates an acknowledgement/retour XML envelope — shared across all
 * four targets (uwlr, edu-v, basispoort, entree-content), since none of
 * their real acknowledgement wire formats are documented in the corpus
 * (see design.md "Open Questions") — into a plain status update array:
 * `kenmerk`, `signaalcode`, `signaalOmschrijving`, `accepted`.
 * UwlrEduVService::receiveReturn() is the caller that turns this into a
 * UwlrEduVAcknowledgementReceivedEvent — this class stays a pure
 * XML-to-array translator (mirrors OsoAcknowledgementTranslator).
 *
 * LITERAL-LEAK GUARD (inbound side): a retour with an empty or missing
 * `stuurgegevens.kenmerk` raises UwlrEduVTranslationException BEFORE any
 * status update is returned.
 *
 * XXE hardening: parsing is delegated to the shared StufXmlParser
 * (`LIBXML_NONET` only).
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\RetourAcknowledgementReader;
use OCA\Integriq\Service\Stuf\RetourEnvelopeRefusedException;
use OCA\Integriq\Service\Stuf\StufXmlParser;

/**
 * Retour XML envelope -> plain acknowledgement status update.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */
class UwlrEduVAcknowledgementTranslator {

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
	 * @throws UwlrEduVTranslationException When the XML is malformed or the `kenmerk` is missing/empty.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-an-accepted-acknowledgement-dispatches-the-event-as-accepted
	 */
	public function translate(string $xml): array {
		try {
			return (new RetourAcknowledgementReader(xmlParser: $this->xmlParser))->read(xml: $xml);
		} catch (RetourEnvelopeRefusedException $exception) {
			throw new UwlrEduVTranslationException(message: $exception->getMessage());
		}
	}//end translate()
}//end class
