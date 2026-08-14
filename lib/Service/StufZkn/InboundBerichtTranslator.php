<?php

/**
 * OpenConnector StUF-ZKN Inbound Bericht Translator.
 *
 * Translates an inbound StUF-ZKN 3.10 SOAP kennisgeving (`zakLk01` — zaak
 * create/update, or `edcLk01` — document create/update) into this bridge's
 * normalised OR/ZGW-shaped representation. See design.md "StUF element/
 * attribute assumptions" for the full field table and its grounding — NO
 * live municipal StUF-ZKN endpoint was available in this environment to
 * verify the exact wire shape against; every element/attribute below is an
 * explicit, documented assumption (mirrors `iwmo-ijw-adapter`'s and
 * `dso-connector-adapter`'s identical honesty requirement).
 *
 * LITERAL-LEAK GUARD: a missing/empty `stuurgegevens.referentienummer` (the
 * correlation reference the `Bv03`/`Fo03` reply and idempotency check both
 * need) or a missing/empty `object.identificatie` (the zaak/document
 * business key) raises {@see StufZknTranslationException} BEFORE any OR
 * mapping is returned — this translator MUST NEVER guess or synthesise
 * either value (mirrors `IwmoIjwInboundRetourTranslator`'s `kenmerk` guard
 * and `DsoVerzoekTranslator`'s `verzoekId` guard).
 *
 * VERWERKINGSSOORT semantics (StUF 3.01 core, `StUF:verwerkingssoort`
 * attribute on the `object` element): `T` = toevoeging (create), `W` =
 * wijziging (update of a non-identifying attribute), `I` = wijziging van
 * een identificerend gegeven (update where an identifying attribute itself
 * changes — this bridge does not attempt a rename/re-key operation; it
 * upserts by the given `identificatie` exactly as `W` does, a documented
 * limitation), `V` = vervallen (logical delete — mapped to an OR
 * `status: vervallen` marker, never a hard delete, mirroring this fleet's
 * "never destroy data on an external signal" convention).
 *
 * NOVALUE/NIL handling: a field explicitly marked
 * `StUF:noValue="geenWaarde" xsi:nil="true"` (StUF's "explicitly no value"
 * convention, distinct from the element being entirely absent) is read as
 * `null`, never as an empty-string literal — for OPTIONAL fields this is
 * fine; for the two REQUIRED fields above it is treated identically to
 * "missing" and still trips the literal-leak guard.
 *
 * XXE hardening: parsing is delegated to the shared, XXE-hardened
 * {@see \OCA\OpenConnector\Service\Stuf\StufXmlParser} (`LIBXML_NONET`
 * only, never `LIBXML_NOENT`/`LIBXML_DTDLOAD`).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-zaklk01-edclk01-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\StufZkn;

use OCA\OpenConnector\Exception\StufZknTranslationException;
use OCA\OpenConnector\Service\Stuf\StufXmlParser;
use SimpleXMLElement;

/**
 * Inbound `zakLk01`/`edcLk01` SOAP kennisgeving -> normalised zaak/document representation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-inbound-zaklk01-edclk01-translation-with-a-literal-leak-guard-req-002
 */
class InboundBerichtTranslator {

	/**
	 * Recognised verwerkingssoort codes (StUF 3.01 core).
	 *
	 * @var array<int, string>
	 */
	private const VERWERKINGSSOORTEN = ['T', 'W', 'I', 'V'];

	/**
	 * The berichttype tag -> `entiteittype`/`kind` this translator recognises.
	 *
	 * @var array<string, array{entiteittype: string, kind: string}>
	 */
	private const BERICHTTYPEN = [
		'zakLk01' => ['entiteittype' => 'ZAK', 'kind' => 'zaak'],
		'edcLk01' => ['entiteittype' => 'EDC', 'kind' => 'document'],
	];

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
	 * Translate one inbound StUF-ZKN SOAP envelope.
	 *
	 * @param string $soapXml The raw SOAP envelope XML, exactly as received on the wire.
	 *
	 * @return array{kind: string, berichttype: string, referentienummer: string,
	 *         senderOrganisatie: string, verwerkingssoort: string, entiteittype: string,
	 *         fields: array<string, mixed>} The normalised representation.
	 *
	 * @throws StufZknTranslationException When the XML is malformed/empty, the berichttype is not
	 *                                     `zakLk01`/`edcLk01`, `verwerkingssoort` is unrecognised, or
	 *                                     `referentienummer`/`identificatie` is missing/empty.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-complete-zaklk01-toevoeging-translates-to-a-normalised-zaak-representation
	 */
	public function translate(string $soapXml): array {
		$root = $this->xmlParser->parse(xml: $soapXml);
		if ($root === null) {
			throw new StufZknTranslationException(message: 'StUF-ZKN envelope is empty or not well-formed XML.');
		}

		[$messageEl, $tag] = $this->locateMessage(root: $root);
		$meta = self::BERICHTTYPEN[$tag];

		$stuurgegevens = $messageEl->children(StufZknNamespaces::ZKN)->stuurgegevens;
		$stuur = $stuurgegevens->children(StufZknNamespaces::STUF);

		$referenceNumber = trim((string)$stuur->referentienummer);
		if ($referenceNumber === '') {
			throw new StufZknTranslationException(
				message: 'StUF-ZKN envelope is missing stuurgegevens.referentienummer — refusing to process an '
					. 'unacknowledgeable, uncorrelatable message.'
			);
		}

		$senderOrganisation = trim((string)$stuur->zender->organisatie);

		$object = $messageEl->children(StufZknNamespaces::ZKN)->object;
		if (count($object) === 0) {
			throw new StufZknTranslationException(message: 'StUF-ZKN envelope is missing the `object` element.');
		}

		$processingKind = trim((string)$this->stufAttribute(element: $object, name: 'verwerkingssoort'));
		if (in_array($processingKind, self::VERWERKINGSSOORTEN, true) === false) {
			throw new StufZknTranslationException(
				message: 'StUF-ZKN object carries an unrecognised verwerkingssoort "' . $processingKind . '" '
					. '(expected one of T/W/I/V).'
			);
		}

		$identification = $this->readField(parent: $object, name: 'identificatie');
		if ($identification === null || trim($identification) === '') {
			throw new StufZknTranslationException(
				message: 'StUF-ZKN object is missing identificatie — refusing to upsert an unidentifiable '
					. $meta['kind'] . '.'
			);
		}

		$fields = match ($meta['kind']) {
			'zaak' => $this->extractCaseFields(object: $object, identification: $identification),
			default => $this->extractDocumentFields(object: $object, identification: $identification),
		};

		return [
			'kind' => $meta['kind'],
			'berichttype' => $tag,
			'referentienummer' => $referenceNumber,
			'senderOrganisatie' => $senderOrganisation,
			'verwerkingssoort' => $processingKind,
			'entiteittype' => $meta['entiteittype'],
			'fields' => $fields,
		];

	}//end translate()

	/**
	 * Locate the `zakLk01`/`edcLk01` element inside `soap:Envelope/soap:Body`.
	 *
	 * @param SimpleXMLElement $root The parsed envelope root.
	 *
	 * @return array{0: SimpleXMLElement, 1: string} The bericht element and its tag name.
	 *
	 * @throws StufZknTranslationException When neither a `zakLk01` nor `edcLk01` element is present.
	 */
	private function locateMessage(SimpleXMLElement $root): array {
		$body = $root->children(StufZknNamespaces::SOAP)->Body;
		if (count($body) === 0) {
			// Tolerate a bare (non-SOAP-enveloped) bericht — some StUF
			// gateways deliver the sector message directly as the HTTP
			// body without a SOAP wrapper. Documented assumption, not a
			// silent guess: this only applies when soap:Body is entirely
			// absent, never when it is present-but-empty.
			$body = $root;
		}

		$zknChildren = $body->children(StufZknNamespaces::ZKN);
		foreach (array_keys(self::BERICHTTYPEN) as $tag) {
			if (count($zknChildren->{$tag}) > 0) {
				return [$zknChildren->{$tag}, $tag];
			}
		}

		throw new StufZknTranslationException(
			message: 'StUF-ZKN envelope does not contain a recognised berichttype (expected `zakLk01` or `edcLk01`).'
		);

	}//end locateBericht()

	/**
	 * Extract normalised zaak fields from a `ZAK` object element.
	 *
	 * @param SimpleXMLElement $object The `object` element (`StUF:entiteittype="ZAK"`).
	 * @param string $identification The already-validated, non-empty identificatie.
	 *
	 * @return array<string, mixed> The normalised zaak fields — see design.md's inbound field table.
	 */
	private function extractCaseFields(SimpleXMLElement $object, string $identification): array {
		$caseType = $object->children(StufZknNamespaces::ZKN)->zaaktype;

		return [
			'identificatie' => $identification,
			'omschrijving' => ($this->readField(parent: $object, name: 'omschrijving') ?? ''),
			'toelichting' => $this->readField(parent: $object, name: 'toelichting'),
			'zaaktypeCode' => ($this->readField(parent: $caseType, name: 'code') ?? ''),
			'zaaktypeOmschrijving' => $this->readField(parent: $caseType, name: 'omschrijving'),
			'registratiedatum' => $this->readField(parent: $object, name: 'registratiedatum'),
			'startdatum' => $this->readField(parent: $object, name: 'startdatum'),
			'einddatumGepland' => $this->readField(parent: $object, name: 'einddatumGepland'),
			'einddatum' => $this->readField(parent: $object, name: 'einddatum'),
			'archiefnominatie' => $this->readField(parent: $object, name: 'archiefnominatie'),
			'betalingsIndicatie' => $this->readField(parent: $object, name: 'betalingsIndicatie'),
		];

	}//end extractZaakFields()

	/**
	 * Extract normalised document fields from an `EDC` object element.
	 *
	 * @param SimpleXMLElement $object The `object` element (`StUF:entiteittype="EDC"`).
	 * @param string $identification The already-validated, non-empty identificatie.
	 *
	 * @return array<string, mixed> The normalised document fields — see design.md's inbound field table.
	 */
	private function extractDocumentFields(SimpleXMLElement $object, string $identification): array {
		$isRelevantFor = $object->children(StufZknNamespaces::ZKN)->isRelevantVoor;
		$gerelateerde = $isRelevantFor->children(StufZknNamespaces::ZKN)->gerelateerde;

		return [
			'identificatie' => $identification,
			'titel' => ($this->readField(parent: $object, name: 'titel') ?? ''),
			'formaat' => $this->readField(parent: $object, name: 'formaat'),
			'language' => $this->readField(parent: $object, name: 'language'),
			'creatiedatum' => $this->readField(parent: $object, name: 'creatiedatum'),
			'ontvangstdatum' => $this->readField(parent: $object, name: 'ontvangstdatum'),
			'vertrouwelijkAanduiding' => $this->readField(parent: $object, name: 'vertrouwelijkAanduiding'),
			'auteur' => $this->readField(parent: $object, name: 'auteur'),
			'versie' => $this->readField(parent: $object, name: 'versie'),
			'bestandsnaam' => $this->readField(parent: $object, name: 'bestandsnaam'),
			'zaakIdentificatie' => $this->readField(parent: $gerelateerde, name: 'identificatie'),
		];

	}//end extractDocumentFields()

	/**
	 * Read one StUF-namespaced attribute off an element (e.g. `verwerkingssoort`, `entiteittype`).
	 *
	 * @param SimpleXMLElement $element The element carrying the attribute.
	 * @param string $name The attribute local name.
	 *
	 * @return string The attribute value, or an empty string when absent.
	 */
	private function stufAttribute(SimpleXMLElement $element, string $name): string {
		$attributes = $element->attributes(StufZknNamespaces::STUF);
		return trim((string)($attributes->{$name} ?? ''));
	}//end stufAttribute()

	/**
	 * Read one zkn-namespaced field, honouring StUF's `noValue`/`xsi:nil` convention.
	 *
	 * @param SimpleXMLElement $parent The parent element the field is a direct child of.
	 * @param string $name The field's local name (zkn namespace).
	 *
	 * @return string|null The trimmed text value, or null when the element is absent OR explicitly
	 *                     marked `StUF:noValue="geenWaarde" xsi:nil="true"`.
	 */
	private function readField(SimpleXMLElement $parent, string $name): ?string {
		$field = $parent->children(StufZknNamespaces::ZKN)->{$name};
		if (count($field) === 0) {
			return null;
		}

		$stufAttrs = $field->attributes(StufZknNamespaces::STUF);
		$xsiAttrs = $field->attributes(StufZknNamespaces::XSI);
		$isNil = (((string)($xsiAttrs->nil ?? '')) === 'true');
		$isNoValue = ((string)($stufAttrs->noValue ?? '')) !== '';

		if ($isNil === true || $isNoValue === true) {
			return null;
		}

		$value = trim((string)$field);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end readField()
}//end class
