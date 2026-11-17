<?php

/**
 * Contract of the LearningMaterialService.
 *
 * @category Contract
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

/**
 * Public operations of {@see LearningMaterialService} that other components call.
 */
interface LearningMaterialServiceInterface {
	/**
	 * Carry out one request.
	 *
	 * @param array<string,mixed> $request `operation`, `source` and the operation's fields.
	 *
	 * @return array<string,mixed>|null The answer; null when `find` found nothing.
	 *
	 * @throws LearningMaterialRefusedException When the request is refused or the source did not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function handle(array $request): ?array;
}
