<?php

/**
 * Integriq OSO Import Translator.
 *
 * Parses an inbound OSO XML overstapdossier into the exact field shape
 * `oso-inbound-contract`'s `OsoImportDossier` expects:
 * `sourceSchoolBrin`, `learnerEckId`, `categories` (array of
 * `{category, included, data}`), `draftProfile` (nullable
 * `{givenName, familyName, birthDate, eckId, schoolId}` snapshot — NOT a
 * live LearnerProfile), `attachmentRefs`. Read directly against that
 * sibling change's proposal (`leerlingEckId`, `voornamen`, `achternaam`,
 * `geboortedatum`, sending-school BRIN) — see design.md "Trade-offs" for
 * why the output field names match `OsoImportDossier` verbatim rather than
 * integriq's own naming convention.
 *
 * XXE hardening: the dossier XML originates from an external party
 * (Kennisnet/another school). Parsing is delegated to the shared
 * StufXmlParser (`LIBXML_NONET` only).
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Stuf\StufXmlParser;
use SimpleXMLElement;

/**
 * Inbound OSO overstapdossier XML -> OsoImportDossier-shaped field array.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-003-import-parsing-into-learniqs-osoimportdossier-field-shape
 */
class OsoImportTranslator {

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
	 * Translate one inbound OSO overstapdossier XML into the
	 * OsoImportDossier-shaped field array.
	 *
	 * @param string $xml The raw inbound dossier XML, exactly as received on the wire.
	 *
	 * @return array{sourceSchoolBrin: string, learnerEckId: string, categories: array,
	 *         draftProfile: array|null, attachmentRefs: array}
	 *
	 * @throws OsoTranslationException When the XML is malformed or the sending school BRIN /
	 *                                 learner ECK iD are missing.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-complete-inbound-dossier-dispatches-osodossierreceivedevent
	 */
	public function translate(string $xml): array {
		$root = $this->parseXml(xml: $xml);

		$sourceSchoolBrin = trim((string)($root->stuurgegevens->afzenderBrin ?? ''));
		if ($sourceSchoolBrin === '') {
			throw new OsoTranslationException(
				message: 'Inbound OSO dossier is missing stuurgegevens.afzenderBrin — refusing to import from an unidentified school.'
			);
		}

		$body = $root->body ?? new SimpleXMLElement('<body/>');
		$learnerEckId = trim((string)($body->leerlingEckId ?? ''));
		if ($learnerEckId === '') {
			throw new OsoTranslationException(
				message: 'Inbound OSO dossier is missing body.leerlingEckId — refusing to import an unidentified learner.'
			);
		}

		$categories = [];
		if (isset($body->categories->category) === true) {
			foreach ($body->categories->category as $category) {
				$categories[] = [
					'category' => (string)($category->name ?? ''),
					'included' => ((string)($category->included ?? 'false') === 'true'),
					'data' => json_decode((string)($category->data ?? '{}'), true) ?? [],
				];
			}
		}

		$attachmentRefs = [];
		if (isset($body->attachmentRefs->ref) === true) {
			foreach ($body->attachmentRefs->ref as $ref) {
				$attachmentRefs[] = (string)$ref;
			}
		}

		$draftProfile = null;
		$givenName = $this->nullableText(body: $body, field: 'voornamen');
		if ($givenName !== null) {
			$draftProfile = [
				'givenName' => $givenName,
				'familyName' => $this->nullableText(body: $body, field: 'achternaam'),
				'birthDate' => $this->nullableText(body: $body, field: 'geboortedatum'),
				'eckId' => $learnerEckId,
				'schoolId' => $sourceSchoolBrin,
			];
		}

		return [
			'sourceSchoolBrin' => $sourceSchoolBrin,
			'learnerEckId' => $learnerEckId,
			'categories' => $categories,
			'draftProfile' => $draftProfile,
			'attachmentRefs' => $attachmentRefs,
		];
	}//end translate()

	/**
	 * Read an optional body field, returning null instead of an empty string
	 * when absent.
	 *
	 * @param SimpleXMLElement $body The dossier's `<body>` element.
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
	 * Safely parse the inbound dossier XML via the shared, XXE-hardened StufXmlParser.
	 *
	 * @param string $xml The raw inbound dossier XML.
	 *
	 * @return SimpleXMLElement The parsed root element.
	 *
	 * @throws OsoTranslationException When the XML is empty or malformed.
	 */
	private function parseXml(string $xml): SimpleXMLElement {
		if (trim($xml) === '') {
			throw new OsoTranslationException(message: 'Inbound OSO dossier is empty.');
		}

		$root = $this->xmlParser->parse(xml: $xml);
		if ($root === null) {
			throw new OsoTranslationException(message: 'Inbound OSO dossier is not well-formed XML.');
		}

		return $root;
	}//end parseXml()
}//end class
