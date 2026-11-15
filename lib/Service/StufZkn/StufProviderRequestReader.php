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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufProviderRequestReader {

	/**
	 * Constructor.
	 *
	 * @param StufProviderOperationMapper $mapper Maps a write message onto a generic case operation.
	 */
	public function __construct(
		private readonly StufProviderOperationMapper $mapper = new StufProviderOperationMapper(),
	) {

	}//end __construct()

	/**
	 * Build the typed event for one message.
	 *
	 * @param SimpleXMLElement $message          The StUF message element.
	 * @param string           $sourceId         The source id.
	 * @param string           $referentienummer The request's referentienummer.
	 *
	 * @return StufProviderQueryEvent The event.
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function event(SimpleXMLElement $message, string $sourceId, string $referentienummer): StufProviderQueryEvent {
		$run  = new ProviderMessageRun(message: $message, sourceId: $sourceId, referentienummer: $referentienummer);
		$type = $message->getName();
		if ($type === 'zakLv01') {
			return $this->caseQuery(run: $run);
		}

		if ($type === 'npsLv01') {
			return $this->personQuery(run: $run);
		}

		$mutation = $this->mutation(message: $message);
		if ($type === 'edcLk01') {
			return $this->documentMutation(run: $run, mutation: $mutation);
		}

		return $this->caseMutation(run: $run, mutation: $mutation);
	}//end event()

	/**
	 * The case query a zakLv01 asks: its gelijk criteria.
	 *
	 * @param ProviderMessageRun $run The message.
	 *
	 * @return StufCaseQueryEvent The event.
	 */
	private function caseQuery(ProviderMessageRun $run): StufCaseQueryEvent {
		return new StufCaseQueryEvent(
			sourceId: $run->sourceId,
			referentienummer: $run->referentienummer,
			criteria: $this->leaves(element: $this->first(xml: $run->message, path: './*[local-name()="gelijk"]'))
		);
	}//end caseQuery()

	/**
	 * The person query an npsLv01 asks: the BSN it names.
	 *
	 * @param ProviderMessageRun $run The message.
	 *
	 * @return StufPersonQueryEvent The event.
	 */
	private function personQuery(ProviderMessageRun $run): StufPersonQueryEvent {
		$bsn = $this->text(xml: $run->message, path: './*[local-name()="gelijk"]/*[local-name()="inp.bsn" or local-name()="bsn"]');
		return new StufPersonQueryEvent(sourceId: $run->sourceId, referentienummer: $run->referentienummer, bsn: $bsn);
	}//end personQuery()

	/**
	 * What a kennisgeving changes: its current object, its fields and its mutatiesoort.
	 *
	 * A W kennisgeving sends the old object and then the new one: the last one holds the current values.
	 *
	 * @param SimpleXMLElement $message The kennisgeving.
	 *
	 * @return ProviderMutation The mutation.
	 */
	private function mutation(SimpleXMLElement $message): ProviderMutation {
		$object       = $this->mapper->currentObject(message: $message);
		$fields       = $this->leaves(element: $object);
		$mutatiesoort = $this->text(xml: $message, path: './*[local-name()="parameters"]/*[local-name()="mutatiesoort"]');
		if ($mutatiesoort === '' && $object !== null) {
			$mutatiesoort = $this->attribute(element: $object, name: 'verwerkingssoort');
		}

		return new ProviderMutation(object: $object, fields: $fields, mutatiesoort: $mutatiesoort);
	}//end mutation()

	/**
	 * The document mutation an edcLk01 sends, with the case it belongs to and without its content.
	 *
	 * @param ProviderMessageRun $run      The message.
	 * @param ProviderMutation   $mutation What it changes.
	 *
	 * @return StufDocumentMutationEvent The event.
	 */
	private function documentMutation(ProviderMessageRun $run, ProviderMutation $mutation): StufDocumentMutationEvent {
		$fields = $mutation->fields;
		$fields['zaakidentificatie'] = $this->relatedText(object: $mutation->object, relation: 'isRelevantVoor', element: 'identificatie');
		unset($fields['inhoud']);
		return new StufDocumentMutationEvent(
			sourceId: $run->sourceId,
			referentienummer: $run->referentienummer,
			mutatiesoort: $mutation->mutatiesoort,
			fields: array_filter($fields, static fn(string $value): bool => $value !== ''),
			operation: $this->mapper->documentOperation(message: $run->message, mutatiesoort: $mutation->mutatiesoort)
		);
	}//end documentMutation()

	/**
	 * The case mutation a zakLk01 sends, with its zaaktype.
	 *
	 * @param ProviderMessageRun $run      The message.
	 * @param ProviderMutation   $mutation What it changes.
	 *
	 * @return StufCaseMutationEvent The event.
	 */
	private function caseMutation(ProviderMessageRun $run, ProviderMutation $mutation): StufCaseMutationEvent {
		$fields = $mutation->fields;
		$fields['zaaktypeCode']         = $this->relatedText(object: $mutation->object, relation: 'isVan', element: 'code');
		$fields['zaaktypeOmschrijving'] = $this->relatedText(object: $mutation->object, relation: 'isVan', element: 'omschrijving');
		return new StufCaseMutationEvent(
			sourceId: $run->sourceId,
			referentienummer: $run->referentienummer,
			mutatiesoort: $mutation->mutatiesoort,
			fields: array_filter($fields, static fn(string $value): bool => $value !== ''),
			operation: $this->mapper->caseOperation(message: $run->message, mutatiesoort: $mutation->mutatiesoort)
		);
	}//end caseMutation()

	/**
	 * The first element inside the SOAP Body.
	 *
	 * @param SimpleXMLElement $root The envelope.
	 *
	 * @return SimpleXMLElement|null The StUF message element.
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
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
	 * The text of one element of an object's related entity.
	 *
	 * @param SimpleXMLElement|null $object   The object.
	 * @param string                $relation The relation element (isVan, isRelevantVoor).
	 * @param string                $element  The element inside its gerelateerde.
	 *
	 * @return string The text, '' when absent.
	 */
	private function relatedText(?SimpleXMLElement $object, string $relation, string $element): string {
		if ($object === null) {
			return '';
		}

		return $this->text(
			xml: $object,
			path: './*[local-name()="' . $relation . '"]/*[local-name()="gerelateerde"]/*[local-name()="' . $element . '"]'
		);
	}//end relatedText()

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
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function text(SimpleXMLElement $xml, string $path): string {
		$found = $this->first(xml: $xml, path: $path);
		if ($found === null) {
			return '';
		}

		return trim((string)$found);
	}//end text()
}//end class
