<?php

/**
 * Integriq case-system part upload.
 *
 * One upload of a file in parts: the file and the lock the Documenten API handed out for it.
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
 * The file and its lock while its parts are sent.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
final class PartUpload {

	/**
	 * Constructor.
	 *
	 * @param DocumentFile $file The file.
	 * @param string       $lock The lock from the create answer.
	 */
	public function __construct(
		public readonly DocumentFile $file,
		public readonly string $lock,
	) {

	}//end __construct()
}//end class
