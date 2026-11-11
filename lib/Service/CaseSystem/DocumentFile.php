<?php

/**
 * Integriq case-system document file.
 *
 * The file a delivery uploads to the Documenten API: the source it goes through, its bytes and its name.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

/**
 * One file on its way to the Documenten API.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
final class DocumentFile {

	/**
	 * Constructor.
	 *
	 * @param string $sourceId The Documenten API source.
	 * @param string $content  The file's bytes.
	 * @param string $filename The file name.
	 */
	public function __construct(
		public readonly string $sourceId,
		public readonly string $content,
		public readonly string $filename,
	) {

	}//end __construct()
}//end class
