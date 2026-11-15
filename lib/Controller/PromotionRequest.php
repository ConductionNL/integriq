<?php

/**
 * Integriq PromotionRequest: the configuration and target a preview or a
 * promotion request names.
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
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * One checked promotion request: the configuration, the target environment
 * and the credential bindings it carries (none unless it sends a list).
 *
 * @SuppressWarnings(PHPMD.LongVariable) -- targetEnvironmentSlug is the service's own parameter name
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 */
final class PromotionRequest {
	/**
	 * The credential bindings the request carries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $credentialBindings = [];

	/**
	 * Constructor.
	 *
	 * @param string $configurationId       The configuration to promote.
	 * @param string $targetEnvironmentSlug The environment to promote to.
	 */
	public function __construct(
		public readonly string $configurationId,
		public readonly string $targetEnvironmentSlug,
	) {

	}//end __construct()
}//end class
