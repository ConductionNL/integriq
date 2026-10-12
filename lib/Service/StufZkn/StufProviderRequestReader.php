<?php

/**
 * Integriq StUF Provider Request Reader.
 *
 * Reads the question out of a StUF provider envelope and turns it into the
 * typed event the owning app answers (decision 199). No I/O: XML in, event out.
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
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use SimpleXMLElement;

/**
 * Turns a provider message into its typed event.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufProviderRequestReader {

	/**
	 * Build the typed event for one message.
	 *
	 * @param SimpleXMLElement $message          The StUF message element.
	 * @param string           $sourceId         The source id.
	 * @param string           $referentienummer The request's referentienummer.
	 *
	 * @return StufProviderQueryEvent The event.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function event(SimpleXMLElement $message, string $sourceId, string $referentienummer): StufProviderQueryEvent {
		$type = $message->getName();
		if ($type === 'zakLv01') {
			return new StufCaseQueryEvent(
				sourceId: $sourceId,
				referentienummer: $referentienummer,
				criteria: $this->leaves(element: $this->first(xml: $message, path: './*[local-name()="gelijk"]'))
			);
		}

		if ($type === 'npsLv01') {
			$bsn = $this->text(xml: $message, path: './*[local-name()="gelijk"]/*[local-name()="inp.bsn" or local-name()="bsn"]');
			return new StufPersonQueryEvent(sourceId: $sourceId, referentienummer: $referentienummer, bsn: $bsn);
		}

		$object       = $this->first(xml: $message, path: './*[local-name()="object"]');
		$fields       = $this->leaves(element: $object);
		$mutatiesoort = $this->text(xml: $message, path: './*[local-name()="parameters"]/*[local-name()="mutatiesoort"]');
		if ($mutatiesoort === '' && $object !== null) {
			$mutatiesoort = $this->attribute(element: $object, name: 'verwerkingssoort');
		}

		if ($type === 'edcLk01') {
			$fields['zaakidentificatie'] = $this->text(
				xml: $message,
				path: './*[local-name()="object"]/*[local-name()="isRelevantVoor"]/*[local-name()="gerelateerde"]/*[local-name()="identificatie"]'
			);
			return new StufDocumentMutationEvent(sourceId: $sourceId, referentienummer: $referentienummer, mutatiesoort: $mutatiesoort, fields: $fields);
		}

		$zaaktype = './*[local-name()="object"]/*[local-name()="isVan"]/*[local-name()="gerelateerde"]/*[local-name()="';
		$fields['zaaktypeCode']         = $this->text(xml: $message, path: $zaaktype . 'code"]');
		$fields['zaaktypeOmschrijving'] = $this->text(xml: $message, path: $zaaktype . 'omschrijving"]');
		return new StufCaseMutationEvent(
			sourceId: $sourceId,
			referentienummer: $referentienummer,
			mutatiesoort: $mutatiesoort,
			fields: array_filter($fields, static fn(string $value): bool => $value !== '')
		);
	}//end event()

	/**
	 * The first element inside the SOAP Body.
	 *
	 * @param SimpleXMLElement $root The envelope.
	 *
	 * @return SimpleXMLElement|null The StUF message element.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function messageElement(SimpleXMLElement $root): ?SimpleXMLElement {
		return $this->first(xml: $root, path: '//*[local-name()="Body"]/*[1]');
	}//end messageElement()

	/**
	 * Every leaf child of an element, by local name.
	 *
	 * @param SimpleXMLElement|null $element The parent.
	 *
	 * @return array<string, string> Local name to trimmed text.
	 */
	private function leaves(?SimpleXMLElement $element): array {
		$result = [];
		if ($element === null) {
			return $result;
		}

		$children = $element->xpath('./*[not(*)]');
		if (is_array($children) === false) {
			return $result;
		}

		foreach ($children as $child) {
			$value = trim((string)$child);
			if ($value !== '') {
				$result[$child->getName()] = $value;
			}
		}

		return $result;
	}//end leaves()

	/**
	 * An attribute in any namespace, by local name.
	 *
	 * @param SimpleXMLElement $element The element.
	 * @param string           $name    The attribute's local name.
	 *
	 * @return string The value, '' when absent.
	 */
	private function attribute(SimpleXMLElement $element, string $name): string {
		$found = $element->xpath('./@*[local-name()="' . $name . '"]');
		if (is_array($found) === false || $found === []) {
			return '';
		}

		return trim((string)$found[0]);
	}//end attribute()

	/**
	 * The first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The context.
	 * @param string           $path The xpath.
	 *
	 * @return SimpleXMLElement|null The node.
	 */
	private function first(SimpleXMLElement $xml, string $path): ?SimpleXMLElement {
		$found = $xml->xpath($path);
		if (is_array($found) === false || $found === []) {
			return null;
		}

		return $found[0];
	}//end first()

	/**
	 * The trimmed text of the first node an xpath finds.
	 *
	 * @param SimpleXMLElement $xml  The context.
	 * @param string           $path The xpath.
	 *
	 * @return string The text, '' when none.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function text(SimpleXMLElement $xml, string $path): string {
		$found = $this->first(xml: $xml, path: $path);
		if ($found === null) {
			return '';
		}

		return trim((string)$found);
	}//end text()
}//end class
