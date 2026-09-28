<?php

/**
 * Integriq DUO ROD AanleverenAdviesVO_Request builder.
 *
 * Renders the school advice message of DUO contract `DUO_PO_AdviesVO_V1`
 * (PvE ROD-PO 1.14.2, 15-4-2026, section 7.9.1,
 * https://duo.nl/zakelijk/images/pve-po.pdf). The PvE names the fields but
 * does not print the XSD: element names are the PvE names in camelCase and
 * the namespace follows the PvE's `wsa:Action` pattern
 * (`http://duo.nl/contract/<contract>`).
 *
 * Every message names the field, never the value.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\RodTranslationException;

/**
 * School advice payload -> AanleverenAdviesVO_Request element.
 *
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
 */
class RodAdviesVoBuilder {

	/**
	 * The DUO contract namespace of AanleverenAdviesVO_Request.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'http://duo.nl/contract/DUO_PO_AdviesVO_V1';

	/**
	 * Format rules of the AanleverenAdviesVO_Request fields (PvE 7.9.1),
	 * checked when the field has a value. Presence is checked separately.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = [
		'adviesvolgnummer' => '/^[A-Za-z0-9]{1,20}$/',
		'onderwijsaanbieder' => '/^\d{3}A\d{3}$/',
		'onderwijslocatie' => '/^\d{3}X\d{3}$/',
		'vestigingscode' => '/^[A-Za-z0-9]{6}$/',
		'adviesjaar' => '/^\d{4}$/',
	];

	/**
	 * Format of an AdviesVO value: DUO's value list uses upper case, digits,
	 * underscores and a slash, at most 70 characters. DUO checks the value
	 * itself (`046_waardenlijst_fout`).
	 *
	 * @var string
	 */
	private const VALUE_FORMAT = '/^[A-Z][A-Z0-9_\/]{0,69}$/';

	/**
	 * Append an AanleverenAdviesVO_Request (PvE 7.9.1) to the body.
	 *
	 * @param DOMDocument $document       The owning document.
	 * @param DOMElement  $body           The body element.
	 * @param array       $payload        The field payload.
	 * @param DOMElement  $personalNumber The rendered persoonsgebondenNummer choice element.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException When a field is malformed or no advice is given.
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
	 */
	public function append(DOMDocument $document, DOMElement $body, array $payload, DOMElement $personalNumber): void {
		$this->assertAdviesFormats(payload: $payload);

		$advies1 = $this->advies(payload: $payload, adviesField: 'advies1', dateField: 'advies1Datum');
		$advies2 = $this->advies(payload: $payload, adviesField: 'advies2', dateField: 'advies2Datum');
		if ($advies1 === null && $advies2 === null) {
			throw new RodTranslationException(
				message: 'At least one of "advies1" and "advies2" is required (DUO 103_advies_ontbreekt).'
			);
		}

		$request = $document->createElementNS(self::NAMESPACE, 'AanleverenAdviesVO_Request');
		$body->appendChild($request);

		$request->appendChild($personalNumber);
		$this->appendText(document: $document, parent: $request, name: 'adviesvolgnummer', value: (string) $payload['adviesvolgnummer']);
		foreach (['onderwijsaanbieder', 'onderwijslocatie'] as $field) {
			$value = $this->stringOrNull(value: ($payload[$field] ?? null));
			if ($value !== null) {
				$this->appendText(document: $document, parent: $request, name: $field, value: $value);
			}
		}

		$this->appendText(document: $document, parent: $request, name: 'vestigingscode', value: (string) $payload['vestigingscode']);
		$this->appendText(document: $document, parent: $request, name: 'adviesjaar', value: (string) $payload['adviesjaar']);

		foreach (['advies1' => $advies1, 'advies2' => $advies2] as $name => $advies) {
			if ($advies === null) {
				continue;
			}

			$element = $document->createElement($name);
			$request->appendChild($element);
			$this->appendText(document: $document, parent: $element, name: 'advies', value: $advies['advies']);
			$this->appendText(document: $document, parent: $element, name: 'adviesdatum', value: $advies['adviesdatum']);
		}
	}//end append()

	/**
	 * Check every AanleverenAdviesVO field that has a value against its DUO format.
	 *
	 * @param array $payload The field payload.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException Naming the first malformed field.
	 */
	private function assertAdviesFormats(array $payload): void {
		foreach (self::FORMATS as $field => $pattern) {
			$value = $this->stringOrNull(value: ($payload[$field] ?? null));
			if ($value !== null && preg_match($pattern, $value) !== 1) {
				throw new RodTranslationException(message: 'Field "'.$field.'" does not match the DUO format.');
			}
		}
	}//end assertAdviesFormats()

	/**
	 * Read one combined Advies + Adviesdatum pair; both or neither.
	 *
	 * @param array  $payload     The field payload.
	 * @param string $adviesField The advice value field.
	 * @param string $dateField   The advice date field.
	 *
	 * @return array{advies: string, adviesdatum: string}|null The pair, or null when both are empty.
	 *
	 * @throws RodTranslationException When only one half is given or either is malformed.
	 */
	private function advies(array $payload, string $adviesField, string $dateField): ?array {
		$advies = $this->stringOrNull(value: ($payload[$adviesField] ?? null));
		$date   = $this->stringOrNull(value: ($payload[$dateField] ?? null));
		if ($advies === null && $date === null) {
			return null;
		}

		if ($advies === null || $date === null) {
			throw new RodTranslationException(
				message: 'Fields "'.$adviesField.'" and "'.$dateField.'" must be given together.'
			);
		}

		if (preg_match(self::VALUE_FORMAT, $advies) !== 1) {
			throw new RodTranslationException(message: 'Field "'.$adviesField.'" is not an AdviesVO value.');
		}

		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
			|| checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) === false
		) {
			throw new RodTranslationException(message: 'Field "'.$dateField.'" must be a Y-m-d date.');
		}

		return ['advies' => $advies, 'adviesdatum' => $date];
	}//end advies()

	/**
	 * A scalar as a trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$string = trim((string) $value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

	/**
	 * Append a text-valued child element.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement  $parent   The parent element.
	 * @param string      $name     The child element name.
	 * @param string      $value    The text value.
	 *
	 * @return void
	 */
	private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$parent->appendChild($document->createElement($name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES)));
	}//end appendText()
}//end class
