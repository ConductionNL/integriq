<?php

/**
 * Integriq document generation vendor call.
 *
 * One brokered call to a document generation vendor: the HTTP method, the
 * path relative to the source's base URL, and the JSON body when there is one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DocumentGeneration
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
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DocumentGeneration;

/**
 * The values of one vendor call.
 *
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003
 */
final class VendorCall {

	/**
	 * Constructor.
	 *
	 * @param string     $method The HTTP method.
	 * @param string     $path   The path, relative to the base URL.
	 * @param array|null $body   The request body, when there is one.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly ?array $body=null,
	) {

	}//end __construct()
}//end class
