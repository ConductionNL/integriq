<?php

/**
 * Integriq OSO Export Acknowledgement Translator.
 *
 * Translates an OSO export retour/acknowledgement XML envelope into a
 * plain status update array — `kenmerk`, `signaalcode`,
 * `signaalOmschrijving`, `accepted`. OsoService::receiveReturn() is the
 * caller that turns this into an OsoAcknowledgementReceivedEvent — this
 * class stays a pure XML-to-array translator (mirrors
 * RodAcknowledgementTranslator).
 *
 * LITERAL-LEAK GUARD (inbound side): a retour with an empty or missing
 * `stuurgegevens.kenmerk` raises OsoTranslationException BEFORE any status
 * update is returned.
 *
 * XXE hardening: parsing is delegated to the shared StufXmlParser
 * (`LIBXML_NONET` only).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Oso
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
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Stuf\RetourAcknowledgementReader;
use OCA\Integriq\Service\Stuf\RetourEnvelopeRefusedException;
use OCA\Integriq\Service\Stuf\StufXmlParser;

/**
 * Export retour XML envelope -> plain acknowledgement status update.
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */
class OsoAcknowledgementTranslator {

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
	 * Translate one export retour XML envelope into a plain status update.
	 *
	 * @param string $xml The raw retour envelope XML, exactly as received on the wire.
	 *
	 * @return array{kenmerk: string, signaalcode: string, signaalOmschrijving: string|null, accepted: bool}
	 *
	 * @throws OsoTranslationException When the XML is malformed or the `kenmerk` is missing/empty.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	public function translate(string $xml): array {
		try {
			return (new RetourAcknowledgementReader(xmlParser: $this->xmlParser))->read(xml: $xml);
		} catch (RetourEnvelopeRefusedException $exception) {
			throw new OsoTranslationException(message: $exception->getMessage());
		}
	}//end translate()
}//end class
