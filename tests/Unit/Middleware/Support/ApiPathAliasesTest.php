<?php

/**
 * Unit tests for the `/api/v1/` aliases of the API routes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Middleware\Support;

use OCA\Integriq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every unversioned API route is also served under `/api/v1/`, the old paths
 * keep working, and no alias takes a request meant for another route.
 *
 * @covers \OCA\Integriq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Integriq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesTest extends TestCase {

	/**
	 * Paths and their successor, null when the path is not deprecated.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'api path'         => ['/api/things/7/parts', '/api/v1/things/7/parts'],
			'request path'     => ['/index.php/apps/integriq/api/things/7/parts', '/index.php/apps/integriq/api/v1/things/7/parts'],
			'already v1'       => ['/api/v1/things/7/parts', null],
			'versioned family' => ['/api/zgw/zaken/v1/zaken', null],
			'protocol'         => ['/api/scim/v2/Users', null],
			'page'             => ['/apps/integriq/things', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor.
	 *
	 * @param string      $path      The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAPath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
		$this->assertSame(expected: $successor !== null, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testTheSuccessorOfAPath()

	/**
	 * Aliases come right after the routes already declared under `/api/v1/`, and
	 * the declared routes stay exactly as they were.
	 *
	 * @return void
	 */
	public function testAliasesComeAfterTheDeclaredVersionedRoutes(): void {
		$original = [
			'routes' => [
				['name' => 'things#versioned', 'url' => '/api/v1/other/x/y', 'verb' => 'GET'],
				['name' => 'things#index', 'url' => '/api/things/{id}/parts', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
				['name' => 'things#update', 'url' => 'api/things/all/parts', 'verb' => 'PUT', 'postfix' => 'put'],
				['name' => 'other#legacy', 'url' => '/api/other/x/y', 'verb' => 'GET'],
				['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
			],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $original);

		$this->assertSame(
			expected: [
				$original['routes'][0],
				['name' => 'things#index', 'url' => '/api/v1/things/{id}/parts', 'verb' => 'GET', 'requirements' => ['id' => '\d+'], 'postfix' => 'V1'],
				['name' => 'things#update', 'url' => '/api/v1/things/all/parts', 'verb' => 'PUT', 'postfix' => 'putV1'],
			],
			actual: array_slice($declared['routes'], 0, 3)
		);
		$this->assertSame(expected: array_slice($original['routes'], 1), actual: array_slice($declared['routes'], 3));
	}//end testAliasesComeAfterTheDeclaredVersionedRoutes()

	/**
	 * The API fallback gets no alias: it already answers the versioned paths no route does.
	 *
	 * @return void
	 */
	public function testTheApiFallbackHasNoAlias(): void {
		$fallback = ['name' => 'apiFallback#notFound', 'url' => '/api/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']];
		$slug     = ['name' => 'things#show', 'url' => '/api/{slug}', 'verb' => 'GET'];

		$this->assertTrue(condition: ApiPathAliases::isApiFallback(route: $fallback));
		$this->assertFalse(condition: ApiPathAliases::isApiFallback(route: $slug));
		$this->assertSame(
			expected: ['/api/v1/{slug}', '/api/{path}', '/api/{slug}'],
			actual: array_column(ApiPathAliases::withVersionedAliases(routes: ['routes' => [$fallback, $slug]])['routes'], 'url')
		);
	}//end testTheApiFallbackHasNoAlias()

	/**
	 * A standard protocol path and a path with a version of its own keep an
	 * `/api/v1/` alias, but are not deprecated.
	 *
	 * @return void
	 */
	public function testProtocolPathsKeepTheirAliasWithoutDeprecation(): void {
		$routes = [
			'routes' => [
				['name' => 'scim#users', 'url' => '/api/scim/v2/Users', 'verb' => 'GET'],
				['name' => 'objects#index', 'url' => '/api/v2/objects', 'verb' => 'GET'],
			],
		];

		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/api/scim/v2/Users'));
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/api/v2/objects'));
		$this->assertSame(
			expected: [
				['name' => 'scim#users', 'url' => '/api/v1/scim/v2/Users', 'verb' => 'GET', 'postfix' => 'V1'],
				['name' => 'objects#index', 'url' => '/api/v1/v2/objects', 'verb' => 'GET', 'postfix' => 'V1'],
				$routes['routes'][0],
				$routes['routes'][1],
			],
			actual: ApiPathAliases::withVersionedAliases(routes: $routes)['routes']
		);
	}//end testProtocolPathsKeepTheirAliasWithoutDeprecation()

	/**
	 * Only API paths not already under `/api/v1/` are aliased.
	 *
	 * @return void
	 */
	public function testWhichPathsAreAliased(): void {
		$this->assertTrue(condition: ApiPathAliases::isAliased(path: '/api/settings'));
		$this->assertTrue(condition: ApiPathAliases::isAliased(path: 'api/health'));
		$this->assertFalse(condition: ApiPathAliases::isAliased(path: '/api/v1/settings'));
		$this->assertFalse(condition: ApiPathAliases::isAliased(path: '/settings'));
	}//end testWhichPathsAreAliased()

	/**
	 * Every deprecated route of the shipped routes.php has a `/api/v1/` twin with
	 * the same controller, requirements and defaults, and a request on the twin's
	 * path reaches it, or an alias that already answered the original path first.
	 *
	 * @return void
	 */
	public function testEveryDeprecatedRouteHasAReachableTwin(): void {
		$routes = self::shippedRoutes();
		$names  = [];
		foreach ($routes as $route) {
			$names[] = strtolower($route['name'].($route['postfix'] ?? ''));
		}

		$twins = 0;
		foreach ($routes as $route) {
			$url = self::url(route: $route);
			if (ApiPathAliases::isDeprecated(path: $url) === false || ApiPathAliases::isApiFallback(route: $route) === true) {
				continue;
			}

			$path   = (string) ApiPathAliases::successorOf(path: $url);
			$twin   = self::find(routes: $routes, verb: $route['verb'], url: $path, name: $route['name']);
			$sample = self::sample(route: $route);
			$this->assertNotNull(actual: $twin, message: $route['verb'].' '.$url.' has no /api/v1/ twin');
			$this->assertSame(expected: ($route['requirements'] ?? null), actual: ($twin['requirements'] ?? null));
			$this->assertSame(expected: ($route['defaults'] ?? null), actual: ($twin['defaults'] ?? null));
			if ($sample !== null) {
				$first = self::firstMatch(routes: $routes, verb: $route['verb'], path: (string) ApiPathAliases::successorOf(path: $sample));
				$this->assertTrue(
					condition: self::isAlias(route: $first),
					message: $route['verb'].' '.$path.' is answered by '.($first['name'] ?? 'no route').', not by the twin of '.$route['name']
				);
			}

			$twins++;
		}

		$this->assertGreaterThan(expected: 0, actual: $twins);
		$this->assertSame(expected: count($names), actual: count(array_unique($names)), message: 'a route name is registered twice');
	}//end testEveryDeprecatedRouteHasAReachableTwin()

	/**
	 * No alias answers a request meant for a route the app declares under `/api/v1/`.
	 *
	 * @return void
	 */
	public function testNoAliasTakesARequestMeantForADeclaredVersionedRoute(): void {
		$routes = self::shippedRoutes();
		$taken  = [];
		foreach ($routes as $route) {
			$sample = self::sample(route: $route);
			if (str_starts_with(self::url(route: $route), '/api/v1/') === false || self::isAlias(route: $route) === true || $sample === null) {
				continue;
			}

			$first = self::firstMatch(routes: $routes, verb: $route['verb'], path: $sample);
			if (self::isAlias(route: $first) === true) {
				$taken[] = $route['verb'].' '.$sample.' is meant for '.$route['name'].' but the alias of '.$first['name'].' answers it';
			}
		}

		$this->assertSame(expected: [], actual: $taken);
	}//end testNoAliasTakesARequestMeantForADeclaredVersionedRoute()

	/**
	 * The route registered for a verb and url, preferring the one with the given name.
	 *
	 * @param array<int,array<string,mixed>> $routes The routes.
	 * @param string                         $verb   The verb.
	 * @param string                         $url    The url.
	 * @param string                         $name   The preferred route name.
	 *
	 * @return array<string,mixed>|null The route, or null when none is registered there.
	 */
	private static function find(array $routes, string $verb, string $url, string $name): ?array {
		$found = null;
		foreach ($routes as $route) {
			if ($route['verb'] === $verb && self::url(route: $route) === $url) {
				if ($route['name'] === $name) {
					return $route;
				}

				$found = ($found ?? $route);
			}
		}

		return $found;
	}//end find()

	/**
	 * The first route, in declaration order, that answers a request.
	 *
	 * @param array<int,array<string,mixed>> $routes The routes.
	 * @param string                         $verb   The verb.
	 * @param string                         $path   The request path.
	 *
	 * @return array<string,mixed> The route, or an empty array when none answers.
	 */
	private static function firstMatch(array $routes, string $verb, string $path): array {
		foreach ($routes as $route) {
			if ($route['verb'] === $verb && preg_match(self::pattern(route: $route), $path) === 1) {
				return $route;
			}
		}

		return [];
	}//end firstMatch()

	/**
	 * The routes the app declares, with the aliases.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function shippedRoutes(): array {
		$declared = require dirname(__DIR__, 4).'/appinfo/routes.php';

		return $declared['routes'];
	}//end shippedRoutes()

	/**
	 * The url of a route with one leading slash.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string The url.
	 */
	private static function url(array $route): string {
		return '/'.ltrim((string) $route['url'], '/');
	}//end url()

	/**
	 * Whether a route is an alias added by ApiPathAliases.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return bool True for an alias.
	 */
	private static function isAlias(array $route): bool {
		return $route !== []
			&& str_ends_with((string) ($route['postfix'] ?? ''), ApiPathAliases::POSTFIX) === true
			&& str_starts_with(self::url(route: $route), '/api/v1/') === true;
	}//end isAlias()

	/**
	 * The regular expression a route's url compiles to, as Symfony routing matches it.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string The pattern.
	 */
	private static function pattern(array $route): string {
		$requirements = ($route['requirements'] ?? []);
		$defaults     = ($route['defaults'] ?? []);
		$pattern      = '';
		foreach ((array) preg_split('#(/?\{\w+\})#', self::url(route: $route), -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
			if (preg_match('#^(/?)\{(\w+)\}$#', (string) $part, $match) !== 1) {
				$pattern .= preg_quote((string) $part, '#');
				continue;
			}

			$pattern .= '(?:'.preg_quote($match[1], '#').'(?:'.($requirements[$match[2]] ?? '[^/]+').'))';
			if (array_key_exists($match[2], $defaults) === true) {
				$pattern .= '?';
			}
		}

		return '#^'.$pattern.'$#';
	}//end pattern()

	/**
	 * A request path that the route matches, or null when no sample value fits its requirements.
	 *
	 * @param array<string,mixed> $route The route.
	 *
	 * @return string|null The path.
	 */
	private static function sample(array $route): ?string {
		$path = self::url(route: $route);
		foreach (($route['requirements'] ?? []) as $name => $requirement) {
			foreach (['sample', '1', 'a-b', 'a.b', 'a/b'] as $value) {
				if (preg_match('#^(?:'.$requirement.')$#', $value) === 1) {
					$path = str_replace('{'.$name.'}', $value, $path);
					break;
				}
			}
		}

		$path = (string) preg_replace('#\{\w+\}#', 'sample', $path);

		if (preg_match(self::pattern(route: $route), $path) !== 1) {
			return null;
		}

		return $path;
	}//end sample()
}//end class
