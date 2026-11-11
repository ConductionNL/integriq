<?php

/**
 * Integriq retour acknowledgement reader.
 *
 * Reads a DUO/education retour (acknowledgement) XML envelope into the plain
 * status update the OSO, ROD, UWLR/Edu-V and Verzuimloket acknowledgement
 * translators return: `kenmerk`, `signaalcode`, `signaalOmschrijving`,
 * `accepted`. A missing or empty `stuurgegevens.kenmerk` is refused before
 * any status update is returned. Parsing goes through the shared,
 * XXE-hardened StufXmlParser (`LIBXML_NONET` only).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
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
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

use SimpleXMLElement;

/**
 * Retour XML envelope -> plain acknowledgement status update.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 */
final class RetourAcknowledgementReader {

	/**
	 * The signaalcode value meaning "accepted, no correction needed".
	 *
	 * @var string
	 */
	private const SIGNAALCODE_ACCEPTED = '0';

	/**
	 * Constructor.
	 *
	 * @param StufXmlParser $xmlParser Shared XXE-hardened XML parser.
	 */
	public function __construct(
		private readonly StufXmlParser $xmlParser,
	) {

	}//end __construct()

	/**
	 * Read one retour XML envelope into a plain status update.
	 *
	 * @param string $xml The raw retour envelope XML, exactly as received on the wire.
	 *
	 * @return array{kenmerk: string, signaalcode: string, signaalOmschrijving: string|null, accepted: bool}
	 *
	 * @throws RetourEnvelopeRefusedException When the XML is empty or malformed, or the `kenmerk` is missing/empty.
	 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
	 */
	public function read(string $xml): array {
		$root = $this->parseXml(xml: $xml);

		$kenmerk = trim((string)($root->stuurgegevens->kenmerk ?? ''));
		if ($kenmerk === '') {
			throw new RetourEnvelopeRefusedException(
				message: 'Retour envelope is missing stuurgegevens.kenmerk — refusing to resolve an unrelated message.'
			);
		}

		$signaalcode = trim((string)($root->stuurgegevens->signaalcode ?? ''));
		$omschrijving = $this->nullableText(body: ($root->body ?? new SimpleXMLElement('<body/>')), field: 'omschrijving');

		return [
			'kenmerk' => $kenmerk,
			'signaalcode' => $signaalcode,
			'signaalOmschrijving' => $omschrijving,
			'accepted' => ($signaalcode === self::SIGNAALCODE_ACCEPTED),
		];
	}//end read()

	/**
	 * Read an optional body field, returning null instead of an empty string
	 * when absent.
	 *
	 * @param SimpleXMLElement $body The retour's `<body>` element.
	 * @param string $field The field name to read.
	 *
	 * @return string|null The trimmed value, or null when absent/empty.
	 */
	private function nullableText(SimpleXMLElement $body, string $field): ?string {
		$value = trim((string)($body->{$field} ?? ''));
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullableText()

	/**
	 * Safely parse the retour XML via the shared, XXE-hardened StufXmlParser.
	 *
	 * @param string $xml The raw retour envelope XML.
	 *
	 * @return SimpleXMLElement The parsed root element.
	 *
	 * @throws RetourEnvelopeRefusedException When the XML is empty or malformed.
	 */
	private function parseXml(string $xml): SimpleXMLElement {
		if (trim($xml) === '') {
			throw new RetourEnvelopeRefusedException(message: 'Retour envelope is empty.');
		}

		$root = $this->xmlParser->parse(xml: $xml);
		if ($root === null) {
			throw new RetourEnvelopeRefusedException(message: 'Retour envelope is not well-formed XML.');
		}

		return $root;
	}//end parseXml()
}//end class
