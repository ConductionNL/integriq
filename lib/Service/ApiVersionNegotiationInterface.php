<?php

/**
 * Contract of the ZgwVersionNegotiationService.
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

use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Exception\ZgwVersionNotImplementedException;
use OCP\IRequest;

/**
 * Public operations of {@see ZgwVersionNegotiationService} that other components call.
 */
interface ApiVersionNegotiationInterface {
	/**
	 * Resolve one of `fromVersion`/`toVersion` from an explicit value, else
	 * the request's `X-ZGW-Version` header, else the `Accept` header's
	 * `version=` parameter, else {@see VERSION_CANONICAL}.
	 *
	 * @param IRequest $request The current request (for header fallback).
	 * @param string|null $explicit An explicit value (e.g. a POST body field), if any.
	 *
	 * @return string The resolved version string.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#scenario-no-version-signal-at-all-is-a-full-passthrough
	 * @spec openspec/specs/zgw-version-translation/spec.md#scenario-an-explicit-body-field-takes-precedence-over-headers
	 */
	public function resolveVersion(IRequest $request, ?string $explicit): string;

	/**
	 * Assert `$version` is a recognised value — implemented or not.
	 *
	 * @param string $version The version to check.
	 *
	 * @return void
	 *
	 * @throws ZgwUnknownVersionException When `$version` is not `1.0`, `1.6`, or `2.0`.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#scenario-an-unknown-version-is-rejected-before-any-translator-runs
	 */
	public function assertKnownVersion(string $version): void;

	/**
	 * Assert `$version` is implemented by this change (not just recognised).
	 *
	 * @param string $version The version to check.
	 *
	 * @return void
	 *
	 * @throws ZgwVersionNotImplementedException When `$version` is a recognised
	 *                                           placeholder (currently only `2.0`)
	 *                                           with no implemented translator yet.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#scenario-the-next-generation-placeholder-version-is-recognised-but-not-implemented
	 */
	public function assertImplementedVersion(string $version): void;

	/**
	 * Strip an unresolvable `expand` query hint before forwarding.
	 *
	 * A verified real ZGW 1.5.x addition (VNG `zaken-api` CHANGELOG 1.5.0:
	 * list-call `expand` support). Resolving an expansion means querying
	 * additional related resources — out of scope for a pure payload
	 * translator (see design.md Open Question #3). This method documents
	 * and implements the lossy strip explicitly rather than silently
	 * ignoring the concern.
	 *
	 * @param array<string, mixed> $queryParams The inbound query parameter set.
	 *
	 * @return array<string, mixed> The same set with `expand` removed, if present.
	 *
	 * @spec openspec/specs/zgw-version-translation/spec.md#scenario-an-unresolvable-expand-hint-is-stripped-not-silently-honoured
	 */
	public function stripUnsupportedExpandHint(array $queryParams): array;
}
