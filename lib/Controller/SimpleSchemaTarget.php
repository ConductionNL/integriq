<?php

/**
 * Integriq SimpleSchemaTarget: the register/schema pair a simple endpoint
 * names in its targetId.
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
 * The endpoint name and targetId ("register/schema", e.g. "20/111") of an
 * endpoint served by the simple fast path.
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
final class SimpleSchemaTarget {
	/**
	 * The endpoint path the target belongs to, as configured.
	 *
	 * @var mixed
	 */
	public mixed $endpoint = '';

	/**
	 * The configured targetId, as configured.
	 *
	 * @var mixed
	 */
	public mixed $targetId = '';

	/**
	 * Read the target from an endpoint's object data.
	 *
	 * @param array $endpointData The endpoint object data.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public static function fromEndpoint(array $endpointData): self {
		$target = new self();
		$target->endpoint = ($endpointData['endpoint'] ?? '');
		$target->targetId = ($endpointData['targetId'] ?? '');

		return $target;

	}//end fromEndpoint()

	/**
	 * The targetId split on its slash.
	 *
	 * @return array The parts.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function parts(): array {
		return explode('/', $this->targetId);

	}//end parts()

	/**
	 * Whether the targetId is exactly two numeric parts.
	 *
	 * @return boolean True for a "register/schema" pair.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function isWellFormed(): bool {
		$parts = $this->parts();

		return count($parts) === 2 && is_numeric($parts[0]) === true && is_numeric($parts[1]) === true;

	}//end isWellFormed()

	/**
	 * The register id.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function register(): int {
		return (int) $this->parts()[0];

	}//end register()

	/**
	 * The schema id.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function schema(): int {
		return (int) $this->parts()[1];

	}//end schema()

	/**
	 * The endpoint, register and schema, as the fast path logs them.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function logContext(): array {
		return [
			'endpoint' => $this->endpoint,
			'register' => $this->register(),
			'schema'   => $this->schema(),
		];

	}//end logContext()
}//end class
