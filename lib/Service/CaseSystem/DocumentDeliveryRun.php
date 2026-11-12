<?php

/**
 * Integriq document delivery run.
 *
 * One delivery of a document to a ZGW case system while ZgwDocumentDelivery
 * runs it: the delivery's metadata, its settings (documentenSource,
 * zakenSource, zaakUrl, inline) and the file's bytes. Never leaves
 * ZgwDocumentDelivery.
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

/**
 * One document being delivered.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
final class DocumentDeliveryRun {

	/**
	 * The file's bytes.
	 *
	 * @var string
	 */
	public string $content = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $document The delivery's metadata (DOCUMENT_FIELDS).
	 * @param array<string,mixed> $settings documentenSource, zakenSource, zaakUrl, inline.
	 */
	public function __construct(
		public readonly array $document,
		public readonly array $settings,
	) {
	}//end __construct()

	/**
	 * A setting as text; empty when it is not set.
	 *
	 * @param string $name The setting.
	 *
	 * @return string The value.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function setting(string $name): string {
		return (string)($this->settings[$name] ?? '');
	}//end setting()

	/**
	 * Whether the content goes in the create as base64 (a Documenten API 1.0, which has no parts).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function inline(): bool {
		return (($this->settings['inline'] ?? false) === true);
	}//end inline()
}//end class
