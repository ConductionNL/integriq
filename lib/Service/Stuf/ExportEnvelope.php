<?php

/**
 * Integriq export envelope.
 *
 * The XML envelope the OSO, Basispoort, Entree content, Edu-V and UWLR
 * export translators render: a root element holding `stuurgegevens`
 * (the `kenmerk` first, then any translator-specific headers, then
 * `tijdstipBericht`) followed by a `body` with the required payload fields.
 * The translators keep their own validation and refusals; this class only
 * builds the document.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

use DateTime;
use DOMDocument;

/**
 * Builds one export envelope document.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
final class ExportEnvelope {

	/**
	 * The envelope document.
	 *
	 * @var DOMDocument
	 */
	private readonly DOMDocument $document;

	/**
	 * The root element.
	 *
	 * @var EnvelopeElement
	 */
	private readonly EnvelopeElement $root;

	/**
	 * The `stuurgegevens` element.
	 *
	 * @var EnvelopeElement
	 */
	private readonly EnvelopeElement $stuurgegevens;

	/**
	 * Start an envelope: the root element and its `stuurgegevens` with the `kenmerk`.
	 *
	 * @param string $rootName The root element name.
	 * @param string $kenmerk  The correlation id.
	 */
	public function __construct(string $rootName, string $kenmerk) {
		$this->document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$rootElement = $this->document->createElement($rootName);
		$this->document->appendChild($rootElement);
		$this->root = new EnvelopeElement(document: $this->document, element: $rootElement);

		$this->stuurgegevens = $this->root->addChild(name: 'stuurgegevens');
		$this->stuurgegevens->addText(name: 'kenmerk', value: $kenmerk);

	}//end __construct()

	/**
	 * The `stuurgegevens` element, for translator-specific headers.
	 *
	 * @return EnvelopeElement The `stuurgegevens` element.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function header(): EnvelopeElement {
		return $this->stuurgegevens;

	}//end header()

	/**
	 * Add the `tijdstipBericht` header: the current time, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function stampMessageTime(): void {
		$this->stuurgegevens->addText(name: 'tijdstipBericht', value: (new DateTime())->format('c'));

	}//end stampMessageTime()

	/**
	 * Add the `body` element with one text child per field, in the given order.
	 *
	 * @param array<int, string>   $fields  The field names; each must be present in the payload.
	 * @param array<string, mixed> $payload The payload the values come from.
	 *
	 * @return EnvelopeElement The `body` element, for further children.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function addBody(array $fields, array $payload): EnvelopeElement {
		$body = $this->root->addChild(name: 'body');
		foreach ($fields as $field) {
			$body->addText(name: $field, value: (string)$payload[$field]);
		}

		return $body;

	}//end addBody()

	/**
	 * The rendered XML.
	 *
	 * @return string The envelope XML.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function toXml(): string {
		return (string)$this->document->saveXML();

	}//end toXml()
}//end class
