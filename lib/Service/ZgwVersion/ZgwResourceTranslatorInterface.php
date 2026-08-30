<?php

/**
 * Integriq ZGW Resource Translator Interface.
 *
 * Narrow domain seam through which every ZGW resource's `1.0` (the fleet's
 * current procest/OpenRegister shape) ↔ `1.6` (VNG's incremental
 * stability line) payload translation is dispatched. A new resource, or a
 * future next-generation (`2.0`) translator once VNG publishes a stable
 * OAS (see design.md "Open Questions" #1), is added by implementing this
 * interface, never by editing {@see \OCA\Integriq\Service\ZgwVersionTranslationService}
 * or the controller — mirrors `FscConnectivityProviderInterface` /
 * `IwmoIjwProviderInterface` (see design.md "Architecture Overview").
 *
 * Unlike those transport-facing seams, every implementation here is pure
 * (struct in, struct out) — no HTTP, no Twig, no OpenRegister read.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ZgwVersion
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ZgwVersion;

use OCA\Integriq\Exception\ZgwLiteralLeakException;

/**
 * A pure `1.0` ↔ `1.6` payload translator for one ZGW resource.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
interface ZgwResourceTranslatorInterface {
	/**
	 * Stable ZGW resource slug this translator handles (e.g. `zaak`).
	 *
	 * Selected at runtime by {@see \OCA\Integriq\Service\ZgwVersionTranslationService}
	 * via the caller's declared `resource` field.
	 *
	 * @return string The resource slug.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function getResource(): string;

	/**
	 * Translate a `1.0`-shaped payload to `1.6`.
	 *
	 * @param array<string, mixed> $payload The `1.0`-shaped resource payload.
	 *
	 * @return array<string, mixed> The `1.6`-shaped payload.
	 *
	 * @throws ZgwLiteralLeakException When a required field is missing, an enum
	 *                                 value falls outside its documented set, or
	 *                                 an array-required field carries a bare scalar.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function translateToV16(array $payload): array;

	/**
	 * Translate a `1.6`-shaped payload to `1.0`.
	 *
	 * @param array<string, mixed> $payload The `1.6`-shaped resource payload.
	 *
	 * @return array<string, mixed> The `1.0`-shaped payload.
	 *
	 * @throws ZgwLiteralLeakException When a required field is missing, an enum
	 *                                 value falls outside its documented set, or
	 *                                 an array-required field carries a bare scalar.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function translateToV1x(array $payload): array;
}//end interface
