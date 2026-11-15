<?php

/**
 * Integriq SourceCallTarget.
 *
 * What one source-call step calls for one item: the source it names, the
 * rendered endpoint, the method, the statuses it accepts and how it decodes
 * the response body.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Value object for one call a source-call step makes.
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
 */
final class SourceCallTarget {

	/**
	 * Constructor.
	 *
	 * @param string       $reference The authored source reference.
	 * @param ObjectEntity $source    The resolved Source object.
	 * @param string       $endpoint  The rendered endpoint.
	 * @param string       $method    The HTTP method.
	 * @param array        $accepted  Statuses the author opted into.
	 * @param string       $decode    The step's decode mode.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly ObjectEntity $source,
		public readonly string $endpoint,
		public readonly string $method,
		public readonly array $accepted,
		public readonly string $decode,
	) {

	}//end __construct()

	/**
	 * Where the call went, as failure details name it.
	 *
	 * @return array{source: string, endpoint: string, method: string} The source, endpoint and method.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
	 */
	public function where(): array {
		return [
			'source' => $this->reference,
			'endpoint' => $this->endpoint,
			'method' => $this->method,
		];
	}//end where()
}//end class
