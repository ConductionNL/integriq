<?php

/**
 * The API paths of Integriq that speak a protocol defined elsewhere.
 *
 * @category  Middleware
 * @package   OCA\Integriq\Middleware\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Middleware\Support;

/**
 * Recognises the API paths whose request, response and error formats a
 * standard or a peer instance defines, so the app's own API conventions
 * (versioned paths, problem details, the `API-Version` header) leave them alone.
 *
 * Paths are relative to the app root (`/api/...`); a full request path such as
 * `/index.php/apps/integriq/api/health` is accepted too.
 *
 * @category Middleware
 * @package  OCA\Integriq\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 */
final class StandardProtocolPaths {

	/**
	 * The app root that precedes every app-relative path in a request path.
	 *
	 * @var string
	 */
	private const APP_ROOT = '/apps/integriq';

	/**
	 * One pattern per protocol, matched against the app-relative path.
	 *
	 * @var array<string,non-empty-string>
	 */
	public const PATTERNS = [
		'SCIM 2.0'                                                              => '#^/api/scim/#',
		'OpenID4VCI token and credential'                                       => '#^/api/eudi/(?:token|credential)$#',
		'LTI 1.3'                                                               => '#^/api/lti/(?:token$|platform/|[^/]+/(?:login|launch|ags/|nrps/))#',
		'Identity provider broker (OIDC, SAML)'                                 => '#^/api/idp/#',
		'PSD2 callback'                                                         => '#^/api/psd2/callback$#',
		'Payment provider webhooks'                                             => '#^/api/payments/webhook$#',
		'FSC'                                                                   => '#^/api/fsc/#',
		'Objecten API'                                                          => '#^/api/v2/#',
		'ZGW Notificaties callbacks'                                            => '#^/api/notificaties/callback/#',
		'StUF-ZKN'                                                              => '#^/api/stuf-zkn/#',
		'DSO STAM'                                                              => '#^/api/dso/stam/#',
		'iStandaarden (iWMO, iJW)'                                              => '#^/api/iwmo-ijw/#',
		'Education and absence standards (ROD, Verzuimloket, UWLR, Edu-V, OSO)' => '#^/api/(?:rod|verzuimloket|uwlr-eduv|oso)/#',
		'Peppol inbound'                                                        => '#^/api/peppol/inbound$#',
		'NotifyNL inbound'                                                      => '#^/api/notifynl/inbound$#',
		'Open Formulieren submissions'                                          => '#^/api/open-formulieren/submissions$#',
		'Inbound intake channels'                                               => '#^/api/intake/channels/[^/]+/inbound$#',
		'CloudEvents receivers'                                                 => '#^/api/cloudevents/sources/[^/]+$#',
		'Telephony (CTI) events'                                                => '#^/api/cti/[^/]+/events$#',
		'Inbound verdicts'                                                      => '#^/api/verdicts/inbound$#',
		'Proxied endpoints'                                                     => '#^/api/endpoint/#',
		'Health check'                                                          => '#^/api/health$#',
		'Prometheus metrics'                                                    => '#^/api/metrics$#',
	];

	/**
	 * Whether a path speaks a protocol defined outside the app.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a standard protocol path.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function matches(string $path): bool {
		$relative = self::appRelative(path: $path);
		foreach (self::PATTERNS as $pattern) {
			if (preg_match($pattern, $relative) === 1) {
				return true;
			}
		}

		return false;
	}//end matches()

	/**
	 * The path relative to the app root, leading slash included.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return string The app-relative path.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function appRelative(string $path): string {
		$candidate = '/'.ltrim($path, '/');
		$rootAt    = strpos($candidate, self::APP_ROOT.'/');
		if ($rootAt === false) {
			return $candidate;
		}

		return substr($candidate, $rootAt + strlen(self::APP_ROOT));
	}//end appRelative()
}//end class
