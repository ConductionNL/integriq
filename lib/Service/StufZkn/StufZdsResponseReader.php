<?php

/**
 * Integriq StUF-ZDS Case Response Reader.
 *
 * Reads what a case system answers to a ZDS case message: a Bv01/Bv03
 * bevestiging (with the identificatie it allocated), a Du02 or La01 answer,
 * or a Fo01/Fo02/Fo03 fout with a code that says whether a retry can help.
 *
 * Ported from dossiq's StufMessageParser (the outbound half). It reads through
 * {@see \OCA\Integriq\Service\Stuf\StufXmlParser}, which blocks external
 * entities, and refuses any answer with a DOCTYPE: a StUF answer never has
 * one. Dossiq's parser loaded with LIBXML_NOENT, which expands entities.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-response-reading
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Service\Stuf\StufXmlParser;
use SimpleXMLElement;

/**
 * Parses StUF-ZDS case answers.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-response-reading
 */
class StufZdsResponseReader {

	/**
	 * StUF fout codes that mean "busy or locked, try again".
	 *
	 * @var string[]
	 */
	private const TRANSIENT_CODES = ['StUF067', 'StUF068', 'StUF019'];

	/**
	 * Constructor.
	 *
	 * @param StufXmlParser $xmlParser The hardened XML parser.
	 */
	public function __construct(
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * Read a bevestiging or Du02 answer: the crossRefnummer and the zaak identificatie, if any.
	 *
	 * @param string $xml The answer.
	 *
	 * @return array{crossRefnummer:string,caseIdentification:string|null}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-response-reading
	 */
	public function confirmation(string $xml): array {
		$root = $this->load(xml: $xml);
		if ($root === null) {
			return ['crossRefnummer' => '', 'caseIdentification' => null];
		}

		$caseId = $this->first(
			xml: $root,
			paths: [
				'//*[local-name()="antwoord"]/*[local-name()="object"]/*[local-name()="identificatie"]',
				'//*[local-name()="zaak"]/*[local-name()="identificatie"]',
				'//*[local-name()="identificatie"]',
			]
		);
		if ($caseId === '') {
			$caseId = null;
		}

		return [
			'crossRefnummer' => $this->first(xml: $root, paths: ['//*[local-name()="crossRefnummer"]']),
			'caseIdentification' => $caseId,
		];
	}//end confirmation()

	/**
	 * Read a zakLa01 answer to geefZaakdetails.
	 *
	 * @param string $xml The answer.
	 *
	 * @return array<string,mixed> The zaak (identificatie, omschrijving, startdatum, einddatum, zaaktype, statussen, betrokkenen); [] when unreadable.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-response-reading
	 */
	public function caseDetails(string $xml): array {
		$root = $this->load(xml: $xml);
		if ($root === null) {
			return [];
		}

		$object = '//*[local-name()="object"]/*[local-name()="';
		$case   = [
			'identificatie' => $this->first(xml: $root, paths: [$object . 'identificatie"]', '//*[local-name()="identificatie"]']),
			'omschrijving' => $this->first(xml: $root, paths: [$object . 'omschrijving"]']),
			'startdatum' => $this->first(xml: $root, paths: [$object . 'startdatum"]']),
			'einddatum' => $this->first(xml: $root, paths: [$object . 'einddatum"]']),
			'zaaktype' => ['omschrijving' => $this->first(xml: $root, paths: [$object . 'zaaktype"]/*[local-name()="omschrijving"]'])],
			'statussen' => [],
			'betrokkenen' => [],
		];

		foreach ((array)$root->xpath('//*[local-name()="heeftStatus"]') as $status) {
			$case['statussen'][] = [
				'datumStatusGezet' => $this->descendant(node: $status, localName: 'datumStatusGezet'),
				'statustype' => $this->descendant(node: $status, localName: 'statustype'),
			];
		}

		$roles = '//*[local-name()="heeftAlsInitiator" or local-name()="heeftAlsBelanghebbende" or local-name()="heeftAlsGemachtigde"]';
		foreach ((array)$root->xpath($roles) as $party) {
			$case['betrokkenen'][] = [
				'role' => $party->getName(),
				'bsn' => $this->descendant(node: $party, localName: 'inp.bsn'),
			];
		}

		return $case;
	}//end caseDetails()

	/**
	 * Read a fout answer: code, omschrijving, details, and whether a retry can help.
	 *
	 * @param string $xml The answer.
	 *
	 * @return array{code:string,omschrijving:string,details:string,kind:string}
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-response-reading
	 */
	public function fault(string $xml): array {
		$root = $this->load(xml: $xml);
		if ($root === null) {
			return ['code' => 'PARSE_ERROR', 'omschrijving' => 'The answer could not be read', 'details' => '', 'kind' => 'permanent'];
		}

		$code = $this->first(
			xml: $root,
			paths: ['//*[local-name()="body"]/*[local-name()="code"]', '//*[local-name()="fout"]/*[local-name()="code"]', '//*[local-name()="code"]']
		);
		$kind = 'permanent';
		if (in_array($code, self::TRANSIENT_CODES, true) === true) {
			$kind = 'transient';
		}

		return [
			'code' => $code,
			'omschrijving' => $this->first(xml: $root, paths: ['//*[local-name()="omschrijving"]', '//*[local-name()="faultstring"]']),
			'details' => $this->first(xml: $root, paths: ['//*[local-name()="details"]']),
			'kind' => $kind,
		];
	}//end fault()

	/**
	 * Parse an answer, or null when it is empty, carries a DOCTYPE, or is unreadable.
	 *
	 * @param string $xml The answer.
	 *
	 * @return SimpleXMLElement|null The root.
	 */
	private function load(string $xml): ?SimpleXMLElement {
		if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
			return null;
		}

		return $this->xmlParser->parse(xml: $xml);
	}//end load()

	/**
	 * The first non-empty text over a list of xpaths.
	 *
	 * @param SimpleXMLElement $xml   The root.
	 * @param string[]         $paths The xpaths.
	 *
	 * @return string The text, '' when none.
	 */
	private function first(SimpleXMLElement $xml, array $paths): string {
		foreach ($paths as $path) {
			$found = $xml->xpath($path);
			if (is_array($found) === true && $found !== []) {
				$value = trim((string)$found[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end first()

	/**
	 * The text of a descendant by local name.
	 *
	 * @param SimpleXMLElement $node      The node.
	 * @param string           $localName The local name.
	 *
	 * @return string The text, '' when none.
	 */
	private function descendant(SimpleXMLElement $node, string $localName): string {
		return $this->first(xml: $node, paths: ['.//*[local-name()="' . $localName . '"]']);
	}//end descendant()
}//end class
