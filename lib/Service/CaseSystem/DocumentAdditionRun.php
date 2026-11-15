<?php

/**
 * Integriq document addition run.
 *
 * One add-document operation against a ZGW case system while it runs: the
 * caller's body, the source configuration and the default author, and,
 * as each step settles them, the case, the document name, the
 * informatieobjecttype and the Zaken and Documenten source ids. Never
 * leaves ZgwCaseSystem.
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
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

/**
 * One document being added to a case.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */
final class DocumentAdditionRun {

	/**
	 * The case address or number.
	 *
	 * @var string
	 */
	public string $case = '';

	/**
	 * The document name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The informatieobjecttype url the kind maps to.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * The Zaken source uuid.
	 *
	 * @var string
	 */
	public string $zaken = '';

	/**
	 * The Documenten source uuid.
	 *
	 * @var string
	 */
	public string $documenten = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $body          The body: case, name, kind, content, confidential, ground.
	 * @param array<string,mixed> $configuration The source configuration.
	 * @param string              $sourceName    The default author.
	 */
	public function __construct(
		public readonly array $body,
		public readonly array $configuration,
		public readonly string $sourceName,
	) {

	}//end __construct()
}//end class
