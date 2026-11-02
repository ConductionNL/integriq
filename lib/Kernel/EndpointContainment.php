<?php

/**
 * Whether an endpoint stays inside its Source location.
 *
 * @category Kernel
 * @package  OCA\Integriq\Kernel
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

namespace OCA\Integriq\Kernel;

/**
 * The one translation-free rule set that decides whether an endpoint escapes
 * its Source location. The flow guard and the call engine both ask it, so the
 * two can never judge an endpoint differently.
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */
final class EndpointContainment {

	/**
	 * Escape code: the endpoint carries a control character or newline.
	 *
	 * @var string
	 */
	public const ESCAPE_CONTROL_CHARACTER = 'control-character';

	/**
	 * Escape code: the endpoint is scheme-relative (`//host`, `\\host`).
	 *
	 * @var string
	 */
	public const ESCAPE_SCHEME_RELATIVE = 'scheme-relative';

	/**
	 * Escape code: the endpoint is an absolute URL (it carries a scheme).
	 *
	 * @var string
	 */
	public const ESCAPE_ABSOLUTE_URL = 'absolute-url';

	/**
	 * Escape code: the endpoint percent-decodes to a scheme-relative reference.
	 *
	 * @var string
	 */
	public const ESCAPE_DECODED_SCHEME_RELATIVE = 'decoded-scheme-relative';

	/**
	 * Escape code: the endpoint leaves its Source location via `../`.
	 *
	 * @var string
	 */
	public const ESCAPE_PATH_TRAVERSAL = 'path-traversal';

	/**
	 * Classify how an endpoint escapes its Source location.
	 *
	 * @param string $endpoint The endpoint value (literal or rendered).
	 *
	 * @return string|null One of the ESCAPE_* codes, or null when contained.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function escapeCode(string $endpoint): ?string {
		// A control character or a newline would let an endpoint smuggle a
		// second request line or an extra header past the HTTP client.
		if (preg_match('/[\x00-\x1F\x7F]/', $endpoint) === 1) {
			return self::ESCAPE_CONTROL_CHARACTER;
		}

		if (str_starts_with($endpoint, '//') === true || str_starts_with($endpoint, '\\\\') === true) {
			return self::ESCAPE_SCHEME_RELATIVE;
		}

		if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $endpoint) === 1) {
			return self::ESCAPE_ABSOLUTE_URL;
		}

		$decoded = strtolower(rawurldecode($endpoint));
		if ($decoded !== strtolower($endpoint)) {
			// Re-run the host-ish checks over the percent-decoded form so an
			// encoded `%2f%2f` or `%2e%2e%2f` cannot slip through.
			if (str_starts_with($decoded, '//') === true) {
				return self::ESCAPE_DECODED_SCHEME_RELATIVE;
			}
		}

		if (self::traverses(decoded: $decoded) === true) {
			return self::ESCAPE_PATH_TRAVERSAL;
		}

		return null;
	}//end escapeCode()

	/**
	 * Whether a lower-cased, percent-decoded endpoint climbs out via `..`.
	 *
	 * @param string $decoded The decoded endpoint.
	 *
	 * @return bool True when a `..` segment leaves the Source location.
	 */
	private static function traverses(string $decoded): bool {
		return $decoded === '..'
			|| str_starts_with($decoded, '../') === true
			|| str_contains($decoded, '/../') === true
			|| str_ends_with($decoded, '/..') === true;

	}//end traverses()
}//end class
