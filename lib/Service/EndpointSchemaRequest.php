<?php

/**
 * The state of one schema endpoint request while EndpointService answers it.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Carries the endpoint, the amended request and the values derived from them,
 * so each per-method handler of EndpointService::handleSchemaRequest() names
 * the request instead of every value it may need.
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointSchemaRequest {

	/**
	 * The mapper bound to the endpoint's target register and schema.
	 *
	 * @var mixed
	 */
	public mixed $mapper = null;

	/**
	 * The request parameters after input mapping, with the path id added and internal keys removed.
	 *
	 * @var array
	 */
	public array $parameters = [];

	/**
	 * The path parameters, keyed by the endpoint's placeholder names.
	 *
	 * @var array
	 */
	public array $pathParams = [];

	/**
	 * Whether the flow token's amended response is set to the original one.
	 *
	 * @var bool
	 */
	public bool $amendResponse = true;

	/**
	 * Constructor.
	 *
	 * @param array $endpointData The endpoint's object data.
	 * @param array $request      The flow token's amended request.
	 */
	public function __construct(
		public readonly array $endpointData,
		public readonly array $request,
	) {
	}//end __construct()
}//end class
