<?php

/**
 * Integriq StUF Provider Operation Mapper.
 *
 * Maps the StUF-ZDS 1.2 write messages a case system sends (zakLk01 for
 * creeerZaak and updateZaak, edcLk01 for voegZaakdocumentToe) onto the generic
 * case operations the owning app carries out (decision 206). The StUF element
 * names stop here: the owning app reads `title`, `startDate` and `caseTypeCode`,
 * never `omschrijving`, `startdatum` or `isVan`. No I/O: XML in, array out.
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
 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use SimpleXMLElement;

/**
 * Turns a StUF write message into a generic case operation.
 *
 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
 */
class StufProviderOperationMapper {

	/**
	 * Open a new case.
	 *
	 * @var string
	 */
	public const ACTION_CREATE = 'create';

	/**
	 * Change an existing case.
	 *
	 * @var string
	 */
	public const ACTION_UPDATE = 'update';

	/**
	 * Add a document to an existing case.
	 *
	 * @var string
	 */
	public const ACTION_ADD_DOCUMENT = 'add-document';

	/**
	 * A mutatiesoort this adapter does not carry out (V, E, a document change, none).
	 *
	 * @var string
	 */
	public const ACTION_UNSUPPORTED = 'unsupported';

	/**
	 * The zaak elements that map one to one, StUF name to generic name.
	 *
	 * @var array<string, string>
	 */
	private const CASE_TEXT = [
		'identificatie' => 'identifier',
		'omschrijving' => 'title',
		'toelichting' => 'description',
	];

	/**
	 * The zaak dates, StUF name to generic name.
	 *
	 * @var array<string, string>
	 */
	private const CASE_DATES = [
		'startdatum' => 'startDate',
		'registratiedatum' => 'registrationDate',
		'einddatumGepland' => 'plannedEndDate',
		'uiterlijkeEinddatum' => 'finalEndDate',
	];

	/**
	 * The document elements that map one to one, StUF name to generic name.
	 *
	 * @var array<string, string>
	 */
	private const DOCUMENT_TEXT = [
		'identificatie' => 'identifier',
		'titel' => 'title',
		'beschrijving' => 'description',
		'dct.omschrijving' => 'documentTypeName',
		'formaat' => 'format',
		'taal' => 'language',
		'auteur' => 'author',
	];

	/**
	 * The generic operation for a zakLk01.
	 *
	 * Keys: `action` (always), then only what the message carried: `identifier`,
	 * `title`, `description`, `startDate`, `registrationDate`, `plannedEndDate`,
	 * `finalEndDate` (dates as Y-m-d), `caseTypeCode`, `caseTypeName` and
	 * `initiator` (`type` person or company, `sourceId`, `name`). A W that clears an
	 * element the old object held (`xsi:nil="true"` or `StUF:noValue` in the new
	 * object) also carries `clears`, the names of those elements, so the receiver
	 * can refuse it instead of answering Bv03 for a clear the owning app never saw.
	 *
	 * @param SimpleXMLElement $message      The zakLk01 element.
	 * @param string           $mutatiesoort The StUF mutatiesoort.
	 *
	 * @return array<string, mixed> The operation.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
	 */
	public function caseOperation(SimpleXMLElement $message, string $mutatiesoort): array {
		$operation = ['action' => self::ACTION_UNSUPPORTED];
		if ($mutatiesoort === 'T') {
			$operation['action'] = self::ACTION_CREATE;
		}

		if ($mutatiesoort === 'W') {
			$operation['action'] = self::ACTION_UPDATE;
		}

		$object = $this->currentObject(message: $message);
		if ($object === null) {
			return $operation;
		}

		foreach (self::CASE_TEXT as $element => $key) {
			$operation[$key] = $this->text(xml: $object, path: './*[local-name()="' . $element . '"]');
		}

		foreach (self::CASE_DATES as $element => $key) {
			$operation[$key] = self::isoDate(stuf: $this->text(xml: $object, path: './*[local-name()="' . $element . '"]'));
		}

		$type = './*[local-name()="isVan"]/*[local-name()="gerelateerde"]/*[local-name()="';
		$operation['caseTypeCode'] = $this->text(xml: $object, path: $type . 'code"]');
		$operation['caseTypeName'] = $this->text(xml: $object, path: $type . 'omschrijving"]');
		$operation['initiator']    = $this->initiator(object: $object);
		if ($mutatiesoort === 'W') {
			$operation['clears'] = $this->clearedElements(message: $message, object: $object);
		}

		return array_filter($operation, static fn(mixed $value): bool => $value !== '' && $value !== []);
	}//end caseOperation()

	/**
	 * The elements a W kennisgeving clears: empty in the new object, filled in the old one.
	 *
	 * In StUF a W clears a value by sending the element empty with
	 * `xsi:nil="true"` and `StUF:noValue` in the new object. Many senders also
	 * write every optional element they have no value for that way, integriq's
	 * own translators included, so an empty element only counts as a clear when
	 * the old object (the first `object`) holds a value for it. Only the zaak's
	 * own elements are compared; a related object's are not this message's to
	 * clear.
	 *
	 * @param SimpleXMLElement $message The zakLk01 element.
	 * @param SimpleXMLElement $object  The new object.
	 *
	 * @return string[] The local names of the cleared elements, document order.
	 */
	private function clearedElements(SimpleXMLElement $message, SimpleXMLElement $object): array {
		$objects = $message->xpath('./*[local-name()="object"]');
		if (is_array($objects) === false || count($objects) < 2) {
			return [];
		}

		$old     = $objects[0];
		$cleared = [];
		foreach ((array)$object->xpath('./*') as $element) {
			$this->collectCleared(element: $element, old: $old, cleared: $cleared);
		}

		return array_values(array_unique($cleared));
	}//end clearedElements()

	/**
	 * Add an element's name to the list when it clears a value the old object holds.
	 *
	 * @param SimpleXMLElement $element The element of the new object.
	 * @param SimpleXMLElement $old     The old object.
	 * @param string[]         $cleared The list so far.
	 *
	 * @return void
	 */
	private function collectCleared(SimpleXMLElement $element, SimpleXMLElement $old, array &$cleared): void {
		$empty = $element->xpath('self::*[@*[local-name()="noValue"] or @*[local-name()="nil" and (. = "true" or . = "1")]]');
		if (is_array($empty) === false || $empty === []) {
			return;
		}

		$name = $element->getName();
		if ($this->text(xml: $old, path: './*[local-name()="' . $name . '"]') !== '') {
			$cleared[] = $name;
		}
	}//end collectCleared()

	/**
	 * The generic operation for an edcLk01.
	 *
	 * Keys: `action` (always), then only what the message carried: `caseIdentifier`,
	 * `identifier`, `title`, `description`, `documentTypeName`, `fileName`, `format`,
	 * `language`, `author`, `confidentiality` (lower case, underscores),
	 * `creationDate`, `receivedDate` (Y-m-d) and `content` (the decoded bytes).
	 * `contentError` is set instead of `content` when inhoud is not base64.
	 *
	 * @param SimpleXMLElement $message      The edcLk01 element.
	 * @param string           $mutatiesoort The StUF mutatiesoort.
	 *
	 * @return array<string, mixed> The operation.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
	 */
	public function documentOperation(SimpleXMLElement $message, string $mutatiesoort): array {
		$operation = ['action' => self::ACTION_UNSUPPORTED];
		if ($mutatiesoort === 'T') {
			$operation['action'] = self::ACTION_ADD_DOCUMENT;
		}

		$object = $this->currentObject(message: $message);
		if ($object === null) {
			return $operation;
		}

		foreach (self::DOCUMENT_TEXT as $element => $key) {
			$operation[$key] = $this->text(xml: $object, path: './*[local-name()="' . $element . '"]');
		}

		$operation['caseIdentifier']  = $this->text(
			xml: $object,
			path: './*[local-name()="isRelevantVoor"]/*[local-name()="gerelateerde"]/*[local-name()="identificatie"]'
		);
		$operation['creationDate']    = self::isoDate(stuf: $this->text(xml: $object, path: './*[local-name()="creatiedatum"]'));
		$operation['receivedDate']    = self::isoDate(stuf: $this->text(xml: $object, path: './*[local-name()="ontvangstdatum"]'));
		$operation['confidentiality'] = str_replace(
			' ',
			'_',
			strtolower($this->text(xml: $object, path: './*[local-name()="vertrouwelijkAanduiding"]'))
		);
		$operation['fileName']        = $this->text(xml: $object, path: './*[local-name()="inhoud"]/@*[local-name()="bestandsnaam"]');

		$inhoud = $this->text(xml: $object, path: './*[local-name()="inhoud"]');
		if ($inhoud !== '') {
			$bytes = base64_decode((string)preg_replace('/\s+/', '', $inhoud), true);
			if ($bytes === false) {
				$operation['contentError'] = 'inhoud is geen geldige base64';
			}

			if ($bytes !== false) {
				$operation['content'] = $bytes;
			}
		}

		return array_filter($operation, static fn(mixed $value): bool => $value !== '' && $value !== []);
	}//end documentOperation()

	/**
	 * The object carrying the current values: the last one, since a W kennisgeving sends old then new.
	 *
	 * @param SimpleXMLElement $message The Lk01 element.
	 *
	 * @return SimpleXMLElement|null The object, null when the message has none.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-write-message-reaches-the-owning-app-as-a-generic-case-operation
	 */
	public function currentObject(SimpleXMLElement $message): ?SimpleXMLElement {
		$objects = $message->xpath('./*[local-name()="object"]');
		if (is_array($objects) === false || $objects === []) {
			return null;
		}

		return $objects[(count($objects) - 1)];
	}//end currentObject()

	/**
	 * The initiator of a zaak: a natural person by BSN, or an organisation by its number.
	 *
	 * @param SimpleXMLElement $object The zaak object.
	 *
	 * @return array<string, string> `type`, `sourceId` and `name`; [] when the zaak names none.
	 */
	private function initiator(SimpleXMLElement $object): array {
		$base   = './*[local-name()="heeftAlsInitiator"]/*[local-name()="gerelateerde"]/*[local-name()="';
		$person = $base . 'natuurlijkPersoon"]/*[local-name()="';
		$bsn    = $this->text(xml: $object, path: $person . 'inp.bsn"]');
		if ($bsn !== '') {
			$name = trim($this->text(xml: $object, path: $person . 'voornamen"]') . ' ' . $this->text(xml: $object, path: $person . 'geslachtsnaam"]'));
			return array_filter(['type' => 'person', 'sourceId' => $bsn, 'name' => $name], static fn(string $value): bool => $value !== '');
		}

		$company = $base . 'nietNatuurlijkPersoon"]/*[local-name()="';
		$branch  = $base . 'vestiging"]/*[local-name()="';
		$number  = $this->text(xml: $object, path: $company . 'inn.nnpId"]');
		$name    = $this->text(xml: $object, path: $company . 'statutaireNaam"]');
		if ($number === '') {
			$number = $this->text(xml: $object, path: $branch . 'vestigingsNummer"]');
			$name   = $this->text(xml: $object, path: $branch . 'handelsnaam"]');
		}

		if ($number === '') {
			return [];
		}

		return array_filter(['type' => 'company', 'sourceId' => $number, 'name' => $name], static fn(string $value): bool => $value !== '');
	}//end initiator()

	/**
	 * A StUF date (Ymd, or a longer tijdstip) as Y-m-d.
	 *
	 * @param string $stuf The StUF value.
	 *
	 * @return string The ISO date, '' when empty or not a date.
	 */
	private static function isoDate(string $stuf): string {
		if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $stuf, $parts) !== 1) {
			return '';
		}

		if (checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return '';
		}

		return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
	}//end isoDate()

	/**
	 * The trimmed text of the first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The context.
	 * @param string           $path The xpath.
	 *
	 * @return string The text, '' when none.
	 */
	private function text(SimpleXMLElement $xml, string $path): string {
		$found = $xml->xpath($path);
		if (is_array($found) === false || $found === []) {
			return '';
		}

		return trim((string)$found[0]);
	}//end text()
}//end class
