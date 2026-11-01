<?php

/**
 * Integriq CaseDocumentDelivery.
 *
 * One document on its way into a case system: the mapped delivery, the case
 * it belongs to, its document type, the file sent inline and the two systems
 * that exchange the message.
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
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Value object for one document delivered to a case.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
final class CaseDocumentDelivery {

	/**
	 * Constructor.
	 *
	 * @param array                                                 $document          The mapped delivery (ZGW field names).
	 * @param string                                                $zaakIdentificatie The case the document belongs to.
	 * @param string                                                $documenttype      The case type's document type description (`dct.omschrijving`).
	 * @param array{content:string,filename:string,mimeType:string} $file              The file, sent inline.
	 * @param array{organisatie:string,applicatie:string}           $zender            This bridge as sender.
	 * @param array{organisatie:string,applicatie?:string}          $ontvanger         The case system.
	 */
	public function __construct(
		public readonly array $document,
		public readonly string $zaakIdentificatie,
		public readonly string $documenttype,
		public readonly array $file,
		public readonly array $zender,
		public readonly array $ontvanger,
	) {

	}//end __construct()
}//end class
