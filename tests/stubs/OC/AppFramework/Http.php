<?php

/**
 * Test stub for Nextcloud's legacy `\OC\AppFramework\Http`.
 *
 * `EndpointService.php` imports the internal `OC\AppFramework\Http` (not the
 * public `OCP\AppFramework\Http`) for its `STATUS_*` constants — in a real
 * Nextcloud install this class extends `\OCP\AppFramework\Http` purely for
 * backward compatibility, contributing no constants/behaviour of its own. It
 * is a private `\OC\` core class, absent from the standalone `nextcloud/ocp`
 * dev-dependency package used by this app's test environment (no full NC
 * install) — pre-existing gap, only surfaced once api-product-gateway's
 * `EndpointServiceTierPolicyTest` became the first suite to actually exercise
 * `EndpointService::enforceInboundRateLimit()`'s 429/403 branches (every
 * prior EndpointServiceTest mocks `AuthorizationService::getResolvedConsumer()`
 * to return null, short-circuiting before any `Http::STATUS_*` constant is
 * read).
 *
 * Guarded by `class_exists()` in tests/bootstrap.php so it never clobbers the
 * real class when running inside a full Nextcloud installation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
 */

namespace OC\AppFramework;

/**
 * Stand-in for NC's `OC\AppFramework\Http`, mirroring the real relationship
 * (a bare subclass contributing no members of its own).
 */
class Http extends \OCP\AppFramework\Http {

}//end class
