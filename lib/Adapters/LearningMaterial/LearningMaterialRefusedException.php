<?php

/**
 * Integriq learning material refusal.
 *
 * A learning material request integriq will not carry out: an unknown or
 * unavailable source, a switched-off connection, an unknown operation, or a
 * source that did not answer.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use RuntimeException;

/**
 * A refused learning material request.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
final class LearningMaterialRefusedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $refusalCode The machine code, such as `source-disabled`.
	 * @param array<string,mixed> $details The reason and any further fields for the consumer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function __construct(
		public readonly string $refusalCode,
		public readonly array $details,
	) {
		parent::__construct(message: (string)($details['reason'] ?? $refusalCode));
	}//end __construct()

	/**
	 * The refusal as the event carries it.
	 *
	 * @return array<string,mixed> `code`, `reason` and the further fields.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function toArray(): array {
		return ['code' => $this->refusalCode, 'reason' => $this->getMessage()] + $this->details;
	}//end toArray()
}//end class
