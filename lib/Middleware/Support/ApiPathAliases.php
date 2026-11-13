<?php

/**
 * The versioned `/api/v1/` aliases of Integriq's API routes.
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
 * Serves every API route under `/api/v1/` as well as at its unversioned path,
 * which keeps working and is deprecated.
 *
 * `/api/<path>` is also served as `/api/v1/<path>`, with the same controller,
 * requirements and defaults. Routes that already carry a version
 * (`/api/v1/...`, `/api/zgw/zaken/v1/...`) and standard protocol paths
 * ({@see StandardProtocolPaths}) get no alias and are not deprecated.
 *
 * @category Middleware
 * @package  OCA\Integriq\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/api-contract/spec.md
 */
final class ApiPathAliases {

	/**
	 * The prefix of the versioned paths.
	 *
	 * @var string
	 */
	public const VERSION_PREFIX = '/api/v1';

	/**
	 * The route-name postfix of an alias.
	 *
	 * @var string
	 */
	public const POSTFIX = 'V1';

	/**
	 * The date the unversioned paths were deprecated (2026-11-12), as an RFC 9745 `Deprecation` value.
	 *
	 * @var string
	 */
	public const DEPRECATED_SINCE = '@1794441600';

	/**
	 * Unversioned paths that keep no successor, because a route the app already
	 * declares under `/api/v1/` answers their versioned form with something else.
	 *
	 * @var array<string,non-empty-string>
	 */
	public const WITHOUT_SUCCESSOR = [
	];

	/**
	 * Paths whose versioned form has a new name, as a pattern over the
	 * unversioned path and its replacement, which follows the kebab-case rule.
	 *
	 * @var array<non-empty-string,string>
	 */
	public const RENAMED = [
	];

	/**
	 * The pattern of a path that already names a version.
	 *
	 * @var string
	 */
	private const VERSIONED = '#^/api/(?:[a-z0-9-]+/)*v[0-9]{1,3}(?:/|$)#i';

	/**
	 * Whether a path is an unversioned API path, deprecated in favour of its `/api/v1/` alias.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a deprecated unversioned API path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardProtocolPaths is a pure lookup, shared with routes.php.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function isDeprecated(string $path): bool {
		$relative = StandardProtocolPaths::appRelative(path: $path);
		if (str_starts_with($relative, '/api/') === false || preg_match(self::VERSIONED, $relative) === 1) {
			return false;
		}

		foreach (self::withoutSuccessor() as $pattern) {
			if (preg_match($pattern, $relative) === 1) {
				return false;
			}
		}

		return StandardProtocolPaths::matches(path: $relative) === false;
	}//end isDeprecated()

	/**
	 * The `/api/v1/` path that replaces a deprecated path, keeping whatever precedes the app root.
	 *
	 * @param string $path A request path, or an app-relative path.
	 *
	 * @return string|null The successor path, or null when the path is not deprecated.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) StandardProtocolPaths is a pure lookup, shared with routes.php.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function successorOf(string $path): ?string {
		if (self::isDeprecated(path: $path) === false) {
			return null;
		}

		$relative = StandardProtocolPaths::appRelative(path: $path);
		$prefix   = substr($path, 0, (strlen($path) - strlen($relative)));

		return $prefix.self::versioned(path: self::renamed(path: $relative));
	}//end successorOf()

	/**
	 * A routes.php declaration with an `/api/v1/` alias of every API route not already under `/api/v1/`.
	 *
	 * Standard protocol paths and paths with a version of their own (`/api/scim/v2/`,
	 * `/api/v2/objects`) get an alias too, as they always had: they are not
	 * deprecated, but their `/api/v1/` URLs keep being served. Alias route names
	 * keep the `V1` postfix they always had.
	 *
	 * Routes match in declaration order. The aliases come right after the last
	 * route the app already declares under `/api/v1/`, so those keep answering
	 * their paths, and before every other route, so an unversioned route with a
	 * placeholder as its first segment (`/api/{slug}/...`) or a catch-all can
	 * never answer a `/api/v1/` request meant for an alias. Among themselves the
	 * aliases keep the order of the routes they copy.
	 *
	 * @param array<string,array<array-key,mixed>> $routes The declaration.
	 *
	 * @return array<string,array<array-key,mixed>> The declaration with the aliases added to `routes`.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function withVersionedAliases(array $routes): array {
		$declared = [];
		$insertAt = 0;
		foreach (array_values($routes['routes'] ?? []) as $index => $route) {
			$declared[self::key(route: $route)] = true;
			if (str_starts_with('/'.ltrim((string) ($route['url'] ?? ''), '/'), self::VERSION_PREFIX.'/') === true) {
				$insertAt = ($index + 1);
			}
		}

		$aliases = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$url = '/'.ltrim((string) ($route['url'] ?? ''), '/');
			if (self::isAliased(path: $url) === false || self::isApiFallback(route: $route) === true) {
				continue;
			}

			$alias = array_merge(
				$route,
				[
					'url'     => self::versioned(path: self::renamed(path: $url)),
					'postfix' => ($route['postfix'] ?? '').self::POSTFIX,
				]
			);
			if (isset($declared[self::key(route: $alias)]) === false) {
				$aliases[] = $alias;
			}
		}

		$original         = array_values($routes['routes'] ?? []);
		$routes['routes'] = array_merge(array_slice($original, 0, $insertAt), $aliases, array_slice($original, $insertAt));

		return $routes;
	}//end withVersionedAliases()

	/**
	 * Whether a route path gets an `/api/v1/` alias: every API path not already under `/api/v1/`.
	 *
	 * @param string $path The route url.
	 *
	 * @return bool True when routes.php serves the path under `/api/v1/` too.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function isAliased(string $path): bool {
		$path = '/'.ltrim($path, '/');

		return str_starts_with($path, '/api/') === true && str_starts_with($path, self::VERSION_PREFIX.'/') === false;
	}//end isAliased()

	/**
	 * Whether a route is the API fallback, a single placeholder after `/api/` that spans segments.
	 *
	 * It answers every API path no other route does, versioned ones included,
	 * so it needs no alias, and an alias placed before the versioned routes
	 * would take their requests.
	 *
	 * @param array<array-key,mixed> $route The route.
	 *
	 * @return bool True for the API fallback.
	 *
	 * @spec openspec/specs/api-contract/spec.md
	 */
	public static function isApiFallback(array $route): bool {
		$url = '/'.ltrim((string) ($route['url'] ?? ''), '/');
		if (preg_match('#^/api/\{(\w+)\}$#', $url, $match) !== 1) {
			return false;
		}

		$requirement = (string) ($route['requirements'][$match[1]] ?? '[^/]+');

		return preg_match('#^(?:'.$requirement.')$#', 'a/b') === 1;
	}//end isApiFallback()

	/**
	 * The current name of a path, which differs from the path only when RENAMED lists it.
	 *
	 * @param string $path An app-relative path.
	 *
	 * @return string The path, renamed where it has a new name.
	 */
	private static function renamed(string $path): string {
		foreach (self::renames() as $pattern => $replacement) {
			$current = preg_replace($pattern, $replacement, $path);
			if (is_string($current) === true && $current !== $path) {
				return $current;
			}
		}

		return $path;
	}//end renamed()

	/**
	 * The renamed paths.
	 *
	 * @return array<non-empty-string,string> The replacements by pattern.
	 */
	private static function renames(): array {
		return self::RENAMED;
	}//end renames()

	/**
	 * The patterns of the unversioned paths that keep no successor.
	 *
	 * @return array<string,non-empty-string> The patterns by reason.
	 */
	private static function withoutSuccessor(): array {
		return self::WITHOUT_SUCCESSOR;
	}//end withoutSuccessor()

	/**
	 * The verb and path a route registers, which two routes cannot share.
	 *
	 * @param array<array-key,mixed> $route The route.
	 *
	 * @return string The verb and the path with one leading slash.
	 */
	private static function key(array $route): string {
		return strtoupper((string) ($route['verb'] ?? 'GET')).' /'.ltrim((string) ($route['url'] ?? ''), '/');
	}//end key()

	/**
	 * The `/api/v1/` form of an unversioned API path.
	 *
	 * @param string $path An app-relative path starting with `/api/`.
	 *
	 * @return string The versioned path.
	 */
	private static function versioned(string $path): string {
		return self::VERSION_PREFIX.substr($path, strlen('/api'));
	}//end versioned()
}//end class
