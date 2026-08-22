<?php

/**
 * Integriq ZGW Version Negotiation Service.
 *
 * Resolves a caller's declared `fromVersion`/`toVersion` for the
 * zgw-version-translation change. Real ZGW does not standardise an HTTP
 * content-negotiation media-type registry for versioning — production ZGW
 * APIs version via the URL path (e.g. procest's own
 * `/api/zgw/zaken/v1/{resource}`). Since `POST /api/zgw-translate` is a
 * single, resource-path-agnostic endpoint, this service documents and
 * implements its OWN explicit, ASSUMED negotiation convention — see
 * design.md "Version negotiation" for the full precedence rationale.
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Exception\ZgwVersionNotImplementedException;
use OCP\IRequest;

/**
 * Resolves and validates `1.0`/`1.6`/`2.0` version declarations.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */
class ZgwVersionNegotiationService {

	/**
	 * The fleet's current, canonical ZGW shape — the default when no
	 * version signal is present at all (passthrough).
	 *
	 * @var string
	 */
	public const VERSION_CANONICAL = '1.0';

	/**
	 * VNG's incremental ZGW stability line — implemented by this change.
	 *
	 * @var string
	 */
	public const VERSION_STABILITY = '1.6';

	/**
	 * The next-generation ZGW standard placeholder — recognised, but not
	 * yet implemented (no stable OAS exists, see design.md Open Question #1).
	 *
	 * @var string
	 */
	public const VERSION_NEXT_GEN = '2.0';

	/**
	 * Versions this change actually translates.
	 *
	 * @var string[]
	 */
	private const IMPLEMENTED_VERSIONS = [self::VERSION_CANONICAL, self::VERSION_STABILITY];

	/**
	 * Versions recognised as valid but not yet implemented.
	 *
	 * @var string[]
	 */
	private const UNIMPLEMENTED_VERSIONS = [self::VERSION_NEXT_GEN];

	/**
	 * This change's own request-header convention (not a VNG standard —
	 * documented explicitly, see class docblock).
	 *
	 * @var string
	 */
	private const VERSION_HEADER = 'X-ZGW-Version';

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
	public function resolveVersion(IRequest $request, ?string $explicit): string {
		if ($explicit !== null && $explicit !== '') {
			return $explicit;
		}

		$header = trim((string)$request->getHeader(self::VERSION_HEADER));
		if ($header !== '') {
			return $header;
		}

		$accept = (string)$request->getHeader('Accept');
		if (preg_match('/version=([0-9A-Za-z.\-]+)/', $accept, $matches) === 1) {
			return $matches[1];
		}

		return self::VERSION_CANONICAL;
	}//end resolveVersion()

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
	public function assertKnownVersion(string $version): void {
		$known = array_merge(self::IMPLEMENTED_VERSIONS, self::UNIMPLEMENTED_VERSIONS);
		if (in_array($version, $known, true) === false) {
			throw new ZgwUnknownVersionException(
				message: 'Unknown ZGW version "' . $version . '" — recognised versions are: ' . implode(', ', $known) . '.'
			);
		}

	}//end assertKnownVersion()

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
	public function assertImplementedVersion(string $version): void {
		if (in_array($version, self::UNIMPLEMENTED_VERSIONS, true) === true) {
			throw new ZgwVersionNotImplementedException(
				message: 'ZGW version "' . $version . '" is recognised as the next-generation placeholder but has no '
					. 'stable OAS to translate against yet — see design.md Open Question #1. Not a silent omission.'
			);
		}

	}//end assertImplementedVersion()

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
	public function stripUnsupportedExpandHint(array $queryParams): array {
		unset($queryParams['expand']);

		return $queryParams;
	}//end stripUnsupportedExpandHint()
}//end class
