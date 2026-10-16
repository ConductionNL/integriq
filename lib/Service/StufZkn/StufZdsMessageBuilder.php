<?php

/**
 * Integriq StUF-ZDS Case Message Builder.
 *
 * Builds the outbound StUF-ZKN 0310 case messages a ZDS client sends to a
 * legacy case system: zakLk01 (creeerZaak), zakLk02 (actualiseerZaak),
 * zakLv01 (geefZaakdetails), genereerZaakIdentificatie_Di02 and a vrij
 * bericht from a template the source registers. Every message is a complete
 * SOAP envelope, with a WS-Security UsernameToken header when the source
 * names one.
 *
 * Ported from dossiq's StufMessageBuilder (outbound half). The caller hands
 * in what the case system needs to know, including the zaaktype omschrijving:
 * which case type maps to which zaaktype is the calling app's configuration,
 * not this builder's.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Integriq\Exception\StufZknTranslationException;

/**
 * Renders StUF-ZDS case envelopes.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
 */
class StufZdsMessageBuilder {

	/**
	 * StUF-BG namespace (betrokkene BSN elements).
	 *
	 * @var string
	 */
	public const NS_BG = 'http://www.egem.nl/StUF/sector/bg/0310';

	/**
	 * WS-Security extension namespace.
	 *
	 * @var string
	 */
	public const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

	/**
	 * Default ceiling of the inline documents, before base64 (25 MiB).
	 *
	 * @var int
	 */
	public const PAYLOAD_LIMIT_BYTES = (25 * 1024 * 1024);

	/**
	 * The zone tijdstipBericht is written in: Dutch case systems read local time.
	 *
	 * @var string
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * The fields an actualiseerZaak may change.
	 *
	 * @var string[]
	 */
	private const UPDATABLE_FIELDS = ['omschrijving', 'einddatum', 'resultaattoelichting'];

	/**
	 * Build a zakLk01 creeerZaak.
	 *
	 * The shape is the one StUF-ZKN 3.10 defines and integriq's own provider side
	 * reads ({@see StufProviderOperationMapper::caseOperation()}): the mutatiesoort
	 * in `parameters`, the zaaktype under `isVan/gerelateerde` and each person under
	 * `<role>/gerelateerde/natuurlijkPersoon`.
	 *
	 * @param array       $case                 The case: omschrijving, startdatum, betrokkenen[] (bsn, role), documenten[] (name, mime, bytes).
	 * @param string      $zaaktypeOmschrijving The zaaktype the calling app maps the case type to.
	 * @param array       $routing              Zender, ontvanger and optional wsse (see routing()).
	 * @param string|null $caseIdentification   A pre-allocated zaak identificatie, or null.
	 * @param array       $opts                 includeDocuments (bool), payloadLimitBytes (int).
	 *
	 * @return array{referentienummer:string,xml:string}
	 *
	 * @throws StufZknTranslationException When no zaaktype is given or the documents exceed the ceiling.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function caseCreation(
		array $case,
		string $zaaktypeOmschrijving,
		array $routing,
		?string $caseIdentification=null,
		array $opts=[],
	): array {
		if (trim($zaaktypeOmschrijving) === '') {
			throw new StufZknTranslationException(message: 'No zaaktype for this case: the calling app maps its case type to a zaaktype omschrijving.');
		}

		$documents = [];
		if ((bool)($opts['includeDocuments'] ?? false) === true) {
			$documents = $this->encodeDocuments(
				documents: (array)($case['documenten'] ?? []),
				limitBytes: (int)($opts['payloadLimitBytes'] ?? self::PAYLOAD_LIMIT_BYTES)
			);
		}

		$referenceNumber = $this->generateReferentienummer();

		$identification = '';
		if ($caseIdentification !== null && $caseIdentification !== '') {
			$identification = $this->element(name: 'zkn:identificatie', value: $caseIdentification);
		}

		$parties = '';
		foreach ((array)($case['betrokkenen'] ?? []) as $party) {
			$role     = $this->escape(value: (string)($party['role'] ?? 'heeftAlsInitiator'));
			$parties .= '<zkn:' . $role . '><zkn:gerelateerde><zkn:natuurlijkPersoon stuf:entiteittype="NPS">'
				. $this->element(name: 'bg:inp.bsn', value: (string)($party['bsn'] ?? ''))
				. '</zkn:natuurlijkPersoon></zkn:gerelateerde></zkn:' . $role . '>';
		}

		$documentXml = '';
		foreach ($documents as $document) {
			$documentXml .= '<zkn:heeftRelevant><zkn:gerelateerde stuf:entiteittype="EDC">'
				. $this->element(name: 'stuf:bestandsnaam', value: $document['name'])
				. $this->element(name: 'stuf:formaat', value: $document['mime'])
				. '<stuf:bestandsinhoud>' . $document['base64'] . '</stuf:bestandsinhoud>'
				. '</zkn:gerelateerde></zkn:heeftRelevant>';
		}

		$body = '<zkn:zakLk01>'
			. $this->stuurgegevens(messageCode: 'Lk01', routing: $routing, entityType: 'ZAK', role: 'creeerZaak', referenceNumber: $referenceNumber)
			. '<zkn:parameters><stuf:mutatiesoort>T</stuf:mutatiesoort><stuf:indicatorOvername>V</stuf:indicatorOvername></zkn:parameters>'
			. '<zkn:object stuf:entiteittype="ZAK" stuf:verwerkingssoort="T">'
			. $identification
			. $this->element(name: 'zkn:omschrijving', value: (string)($case['omschrijving'] ?? ''))
			. $this->element(name: 'zkn:startdatum', value: (string)($case['startdatum'] ?? ''))
			. '<zkn:isVan stuf:entiteittype="ZAKZKT"><zkn:gerelateerde stuf:entiteittype="ZKT">'
			. $this->element(name: 'zkn:omschrijving', value: $zaaktypeOmschrijving)
			. '</zkn:gerelateerde></zkn:isVan>'
			. $parties
			. $documentXml
			. '</zkn:object>'
			. '</zkn:zakLk01>';

		return ['referentienummer' => $referenceNumber, 'xml' => $this->envelope(bodyXml: $body, routing: $routing)];
	}//end caseCreation()

	/**
	 * Build a zakLk02 actualiseerZaak: a sparse update of the fields a case system accepts.
	 *
	 * @param string $caseIdentification The zaak identificatie in the case system.
	 * @param array  $changes            The changed fields (omschrijving, einddatum, resultaattoelichting).
	 * @param array  $routing            Zender, ontvanger and optional wsse.
	 *
	 * @return array{referentienummer:string,xml:string}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function caseUpdate(string $caseIdentification, array $changes, array $routing): array {
		$referenceNumber = $this->generateReferentienummer();

		$fields = '';
		foreach (self::UPDATABLE_FIELDS as $field) {
			if (array_key_exists($field, $changes) === true && $changes[$field] !== null) {
				$fields .= $this->element(name: 'zkn:' . $field, value: (string)$changes[$field]);
			}
		}

		$body = '<zkn:zakLk02>'
			. $this->stuurgegevens(messageCode: 'Lk02', routing: $routing, entityType: 'ZAK', role: 'actualiseerZaak', referenceNumber: $referenceNumber)
			. '<zkn:object stuf:entiteittype="ZAK" stuf:verwerkingssoort="W">'
			. $this->element(name: 'zkn:identificatie', value: $caseIdentification)
			. $fields
			. '</zkn:object>'
			. '</zkn:zakLk02>';

		return ['referentienummer' => $referenceNumber, 'xml' => $this->envelope(bodyXml: $body, routing: $routing)];
	}//end caseUpdate()

	/**
	 * Build a zakLv01 geefZaakdetails question.
	 *
	 * @param string   $caseIdentification The zaak identificatie.
	 * @param array    $routing            Zender, ontvanger and optional wsse.
	 * @param string[] $wantedElements     The zkn elements to ask for (the scope).
	 *
	 * @return array{referentienummer:string,xml:string}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function caseDetailsQuery(string $caseIdentification, array $routing, array $wantedElements=[]): array {
		$referenceNumber = $this->generateReferentienummer();

		$scope = '';
		foreach ($wantedElements as $element) {
			$scope .= '<zkn:' . $this->escape(value: (string)$element) . ' />';
		}

		$body = '<zkn:zakLv01>'
			. $this->stuurgegevens(messageCode: 'Lv01', routing: $routing, entityType: 'ZAK', role: 'geefZaakdetails', referenceNumber: $referenceNumber)
			. '<zkn:gelijk stuf:entiteittype="ZAK">' . $this->element(name: 'zkn:identificatie', value: $caseIdentification) . '</zkn:gelijk>'
			. '<zkn:scope><zkn:object stuf:entiteittype="ZAK">' . $scope . '</zkn:object></zkn:scope>'
			. '</zkn:zakLv01>';

		return ['referentienummer' => $referenceNumber, 'xml' => $this->envelope(bodyXml: $body, routing: $routing)];
	}//end caseDetailsQuery()

	/**
	 * Build a genereerZaakIdentificatie_Di02 (ZDS 1.2): ask the case system for a zaak identificatie.
	 *
	 * @param array $routing Zender, ontvanger and optional wsse.
	 *
	 * @return array{referentienummer:string,xml:string}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function caseIdentificationRequest(array $routing): array {
		$referenceNumber = $this->generateReferentienummer();

		$body = '<zkn:genereerZaakIdentificatie_Di02>'
			. $this->stuurgegevens(
				messageCode: 'Di02',
				routing: $routing,
				entityType: '',
				role: 'genereerZaakIdentificatie',
				referenceNumber: $referenceNumber
			)
			. '</zkn:genereerZaakIdentificatie_Di02>';

		return ['referentienummer' => $referenceNumber, 'xml' => $this->envelope(bodyXml: $body, routing: $routing)];
	}//end caseIdentificationRequest()

	/**
	 * Build a vrij bericht from a template the source registers.
	 *
	 * @param array $template The template: name, verplichteVelden[].
	 * @param array $payload  The values, one element per key.
	 * @param array $routing  Zender, ontvanger and optional wsse.
	 *
	 * @return array{referentienummer:string,xml:string}
	 *
	 * @throws StufZknTranslationException When the payload misses a required field.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function freeMessage(array $template, array $payload, array $routing): array {
		$name = (string)($template['name'] ?? '');
		foreach ((array)($template['verplichteVelden'] ?? []) as $required) {
			if (array_key_exists((string)$required, $payload) === false) {
				throw new StufZknTranslationException(message: sprintf('vrijBericht "%s" misses the required field "%s".', $name, (string)$required));
			}
		}

		$referenceNumber = $this->generateReferentienummer();

		$values = '';
		foreach ($payload as $field => $value) {
			$values .= $this->element(name: 'zkn:' . (string)$field, value: (string)$value);
		}

		$tag  = 'zkn:' . $this->escape(value: $name) . '_Du01';
		$body = '<' . $tag . '>'
			. $this->stuurgegevens(messageCode: 'Du01', routing: $routing, entityType: 'ZAK', role: $name, referenceNumber: $referenceNumber)
			. '<zkn:parameters>' . $values . '</zkn:parameters>'
			. '</' . $tag . '>';

		return ['referentienummer' => $referenceNumber, 'xml' => $this->envelope(bodyXml: $body, routing: $routing)];
	}//end freeMessage()

	/**
	 * Read the routing of a StUF-ZKN source: zender, ontvanger and the WS-Security user.
	 *
	 * @param array                                     $source The source object.
	 * @param array{username:string,password:string}|null $wsse The resolved UsernameToken, or null.
	 *
	 * @return array{zender:array{organisatie:string,applicatie:string},ontvanger:array{organisatie:string,applicatie:string,gebruiker:string},wsse:array{username:string,password:string}|null}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function routing(array $source, ?array $wsse): array {
		$configuration = (array)($source['configuration'] ?? []);

		return [
			'zender' => [
				'organisatie' => trim((string)($configuration['organisatie'] ?? '')),
				'applicatie' => trim((string)($configuration['applicatie'] ?? 'integriq')),
			],
			'ontvanger' => [
				'organisatie' => trim((string)($source['ontvangerOrganisatie'] ?? ($configuration['ontvangerOrganisatie'] ?? ''))),
				'applicatie' => trim((string)($source['ontvangerApplicatie'] ?? ($configuration['ontvangerApplicatie'] ?? ''))),
				'gebruiker' => trim((string)($configuration['ontvangerGebruiker'] ?? '')),
			],
			'wsse' => $wsse,
		];
	}//end routing()

	/**
	 * A fresh referentienummer: 26 Crockford-base32 characters, time first (ULID shape).
	 *
	 * @return string The referentienummer.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function generateReferentienummer(): string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$timeMs   = (int)round(microtime(true) * 1000);
		$timePart = '';
		for ($i = 0; $i < 10; $i++) {
			$timePart = $alphabet[($timeMs & 0x1F)] . $timePart;
			$timeMs   = ($timeMs >> 5);
		}

		$randomPart = '';
		for ($i = 0; $i < 16; $i++) {
			$randomPart .= $alphabet[random_int(0, 31)];
		}

		return $timePart . $randomPart;
	}//end generateReferentienummer()

	/**
	 * The current tijdstipBericht: yyyyMMddHHmmssSSS, Dutch local time.
	 *
	 * @return string The timestamp.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
	 */
	public function currentTimestamp(): string {
		$now = new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
		return $now->format('YmdHis') . substr($now->format('u'), 0, 3);
	}//end currentTimestamp()

	/**
	 * Base64 the documents after checking their total size before encoding.
	 *
	 * @param array $documents  The documents (name, mime, bytes).
	 * @param int   $limitBytes The ceiling.
	 *
	 * @return array<int,array{name:string,mime:string,base64:string}>
	 *
	 * @throws StufZknTranslationException When the total exceeds the ceiling.
	 */
	private function encodeDocuments(array $documents, int $limitBytes): array {
		$total   = 0;
		$encoded = [];
		foreach ($documents as $document) {
			$bytes  = (string)($document['bytes'] ?? '');
			$total += strlen($bytes);
			if ($total > $limitBytes) {
				throw new StufZknTranslationException(
					message: sprintf('The documents are %d bytes before base64, over the limit of %d bytes.', $total, $limitBytes)
				);
			}

			$encoded[] = [
				'name' => (string)($document['name'] ?? 'document'),
				'mime' => (string)($document['mime'] ?? 'application/octet-stream'),
				'base64' => base64_encode($bytes),
			];
		}

		return $encoded;
	}//end encodeDocuments()

	/**
	 * The stuurgegevens block.
	 *
	 * @param string $messageCode     The berichtcode (Lk01, Lk02, Lv01, Di02, Du01).
	 * @param array  $routing         Zender and ontvanger.
	 * @param string $entityType      The entiteittype, '' for none.
	 * @param string $role            The functie.
	 * @param string $referenceNumber The referentienummer.
	 *
	 * @return string The XML.
	 */
	private function stuurgegevens(string $messageCode, array $routing, string $entityType, string $role, string $referenceNumber): string {
		$zender    = (array)($routing['zender'] ?? []);
		$ontvanger = (array)($routing['ontvanger'] ?? []);

		$entity = '';
		if ($entityType !== '') {
			$entity = $this->element(name: 'stuf:entiteittype', value: $entityType);
		}

		return '<zkn:stuurgegevens>'
			. $this->element(name: 'stuf:berichtcode', value: $messageCode)
			. '<stuf:zender>'
			. $this->element(name: 'stuf:organisatie', value: (string)($zender['organisatie'] ?? ''))
			. $this->element(name: 'stuf:applicatie', value: (string)($zender['applicatie'] ?? ''))
			. '</stuf:zender>'
			. '<stuf:ontvanger>'
			. $this->element(name: 'stuf:organisatie', value: (string)($ontvanger['organisatie'] ?? ''))
			. $this->element(name: 'stuf:applicatie', value: (string)($ontvanger['applicatie'] ?? ''))
			. $this->element(name: 'stuf:gebruiker', value: (string)($ontvanger['gebruiker'] ?? ''))
			. '</stuf:ontvanger>'
			. $this->element(name: 'stuf:referentienummer', value: $referenceNumber)
			. $this->element(name: 'stuf:tijdstipBericht', value: $this->currentTimestamp())
			. $entity
			. $this->element(name: 'stuf:functie', value: $role)
			. '</zkn:stuurgegevens>';
	}//end stuurgegevens()

	/**
	 * Wrap a body in a SOAP envelope, with a WS-Security header when the routing names a user.
	 *
	 * @param string $bodyXml The body.
	 * @param array  $routing The routing (wsse).
	 *
	 * @return string The envelope.
	 */
	private function envelope(string $bodyXml, array $routing): string {
		$header = $this->securityHeader(wsse: ($routing['wsse'] ?? null));

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope'
			. ' xmlns:soapenv="' . StufZknNamespaces::SOAP . '"'
			. ' xmlns:stuf="' . StufZknNamespaces::STUF . '"'
			. ' xmlns:zkn="' . StufZknNamespaces::ZKN . '"'
			. ' xmlns:bg="' . self::NS_BG . '"'
			. ' xmlns:wsse="' . self::NS_WSSE . '">'
			. $header
			. '<soapenv:Body>' . $bodyXml . '</soapenv:Body>'
			. '</soapenv:Envelope>';
	}//end envelope()

	/**
	 * The WS-Security header of a UsernameToken, or '' when there is none.
	 *
	 * @param array|null $wsse The resolved UsernameToken (username, password), or null.
	 *
	 * @return string The `soapenv:Header` element, or ''.
	 */
	private function securityHeader(?array $wsse): string {
		if (is_array($wsse) === false || (string)($wsse['username'] ?? '') === '') {
			return '';
		}

		return '<soapenv:Header><wsse:Security><wsse:UsernameToken>'
			. $this->element(name: 'wsse:Username', value: (string)$wsse['username'])
			. $this->element(name: 'wsse:Password', value: (string)($wsse['password'] ?? ''))
			. '</wsse:UsernameToken></wsse:Security></soapenv:Header>';
	}//end securityHeader()

	/**
	 * Add the WS-Security UsernameToken to an envelope built without one.
	 *
	 * The envelope a retry resends is persisted on the `stuf_message` row, so
	 * it is built WITHOUT credentials; the token is added here, at send time,
	 * from the source's decrypted password (REQ credentials stay encrypted).
	 *
	 * @param string     $envelopeXml The envelope, without a Security header.
	 * @param array|null $wsse        The resolved UsernameToken, or null for none.
	 *
	 * @return string The envelope with the header, or unchanged when there is no token.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
	 */
	public function withUsernameToken(string $envelopeXml, ?array $wsse): string {
		$header = $this->securityHeader(wsse: $wsse);
		$body   = strpos($envelopeXml, '<soapenv:Body>');
		if ($header === '' || $body === false || strpos($envelopeXml, '<soapenv:Header>') !== false) {
			return $envelopeXml;
		}

		return (substr($envelopeXml, 0, $body) . $header . substr($envelopeXml, $body));
	}//end withUsernameToken()

	/**
	 * One element with escaped text.
	 *
	 * @param string $name  The qualified element name.
	 * @param string $value The text.
	 *
	 * @return string The XML.
	 */
	private function element(string $name, string $value): string {
		$tag = $this->escape(value: $name);
		return '<' . $tag . '>' . $this->escape(value: $value) . '</' . $tag . '>';
	}//end element()

	/**
	 * XML-escape a value.
	 *
	 * @param string $value The value.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $value): string {
		return htmlspecialchars($value, (ENT_XML1 | ENT_QUOTES), 'UTF-8');
	}//end escape()
}//end class
