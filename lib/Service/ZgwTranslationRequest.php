<?php

/**
 * Integriq ZgwTranslationRequest.
 *
 * One ZGW version translation while ZgwVersionTranslationService runs it: the
 * resource, the payload, and the version it comes from and goes to. Built by
 * the service per translate() call and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One payload to translate between two ZGW versions.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */
final class ZgwTranslationRequest {

	/**
	 * The source version.
	 *
	 * @var string
	 */
	public string $fromVersion = '';

	/**
	 * The target version.
	 *
	 * @var string
	 */
	public string $toVersion = '';

	/**
	 * Constructor.
	 *
	 * @param string $resource The ZGW resource slug.
	 * @param array<string, mixed> $payload The payload to translate.
	 */
	public function __construct(
		public readonly string $resource,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
