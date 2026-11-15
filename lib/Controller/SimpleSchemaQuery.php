<?php

/**
 * Integriq SimpleSchemaQuery: one GET on a simple endpoint, resolved to its
 * target, path parameters and query parameters.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

namespace OCA\Integriq\Controller;

/**
 * The request the simple fast path answers.
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
final class SimpleSchemaQuery {
	/**
	 * The register/schema the endpoint targets.
	 *
	 * @var SimpleSchemaTarget
	 */
	public SimpleSchemaTarget $target;

	/**
	 * The request path.
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * The parameters read from the path by the endpoint pattern.
	 *
	 * @var array<string,string>
	 */
	public array $pathParams = [];

	/**
	 * The query parameters, without the internal _path.
	 *
	 * @var array<string,mixed>
	 */
	public array $parameters = [];

	/**
	 * Whether the path names one object: an id that is the last path parameter.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function addressesSingleObject(): bool {
		return isset($this->pathParams['id']) === true && $this->pathParams['id'] === end($this->pathParams);

	}//end addressesSingleObject()
}//end class
