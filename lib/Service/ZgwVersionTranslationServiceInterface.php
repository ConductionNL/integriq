<?php

/**
 * Contract of the ZgwVersionTranslationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ZgwUnknownResourceException;
use OCA\Integriq\Exception\ZgwVersionTranslationException;

/**
 * Public operations of {@see ZgwVersionTranslationService} that other components call.
 */
interface ZgwVersionTranslationServiceInterface {
	/**
	 * The resource slugs this service can translate.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
	 */
	public function getSupportedResources(): array;

	/**
	 * Translate one resource payload from `$fromVersion` to `$toVersion`.
	 *
	 * @param string $resource The ZGW resource slug.
	 * @param string $fromVersion The source version.
	 * @param string $toVersion The target version.
	 * @param array<string, mixed> $payload The payload to translate.
	 *
	 * @return array<string, mixed> The translated (or, on passthrough, unchanged) payload.
	 *
	 * @throws ZgwUnknownResourceException When `$resource` has no registered translator.
	 * @throws ZgwVersionTranslationException When negotiation or translation fails
	 *                                        (a `status: failed` log record IS persisted first).
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw-version-translation-log-req-004
	 */
	public function translate(string $resource, string $fromVersion, string $toVersion, array $payload): array;
}
