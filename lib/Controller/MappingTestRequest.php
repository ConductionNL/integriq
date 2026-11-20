<?php

/**
 * Integriq MappingTestRequest: one mapping test, with the schema it is
 * validated against.
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
 * @spec openspec/specs/mapping-and-search/spec.md
 */

namespace OCA\Integriq\Controller;

/**
 * The request parameters of a mapping test, OpenRegister's object service
 * and the schema the result is validated against (false for none).
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
final class MappingTestRequest {
	/**
	 * The request parameters: inputObject, mapping, schema, validation.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * OpenRegister's object service, null when it is not installed.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectService|null
	 */
	public ?\OCA\OpenRegister\Service\ObjectService $openRegisters = null;

	/**
	 * The schema entity to validate against, or false for none.
	 *
	 * @var object|false
	 */
	public object|false $schema = false;

	/**
	 * Whether the result is validated: a schema was resolved and validation asked.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/mapping-and-search/spec.md
	 */
	public function validates(): bool {
		return $this->schema !== false && empty($this->data['validation']) === false;

	}//end validates()
}//end class
