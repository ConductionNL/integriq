<?php

/**
 * Every declared route must survive registration.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude Mechanical invariant of appinfo/routes.php, not a product requirement.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud names a route after its controller, its action and its `postfix`,
 * and after nothing else. `OC\AppFramework\Routing\RouteParser::processRoute()`
 * builds `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
 * and `RouteCollection::add()` OVERWRITES an entry that already carries that
 * name.
 *
 * Neither the URL nor the verb is part of the name. So two entries that point
 * at the same controller action and declare no `postfix` are one route, and the
 * last one declared is the one that survives. Nothing warns, and `routes.php`
 * still reads as though both are there.
 *
 * 🔴 THIS APP LOST TWO OF 261 ROUTES THAT WAY. Measured with Nextcloud's own
 * `RouteParser` over this file: declared=261 registered=259.
 *
 *   - `GET /api/lti/{deployment}/login` was overwritten by the `POST` beside
 *     it. LTI 1.3 third-party login initiation is specified for both verbs and
 *     the Platform picks; a Platform that picks GET got a 405 from an endpoint
 *     this app advertises.
 *   - `GET /` was overwritten by the SPA catch-all `GET /{path}`. A comment in
 *     the file described that as intended last-wins behaviour, which is how a
 *     lost route reads once someone has explained it to themselves.
 *
 * 🔑 Unit tests cannot see this. They call the controller action directly, and
 * an action with a full test suite and no reachable route answers exactly like
 * one that works. So the assertion below is on the registration KEY of each
 * entry, not on the file parsing or on the array being non-empty.
 */
class RouteNameUniquenessTest extends TestCase {

	/**
	 * Read the declared route entries.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The route file.
	 */
	private function routeFile(): array {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');

		return $routes;
	}

	/**
	 * The key Nextcloud registers a route under, minus the app name.
	 *
	 * @param array<string, mixed> $route One entry from the route file.
	 *
	 * @return string The registration key.
	 */
	private function registrationKey(array $route): string {
		return strtolower($route['name'] . ($route['postfix'] ?? ''));
	}

	/**
	 * No two entries may register under the same key.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRouteRegistersUnderItsOwnName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			$seen = [];

			foreach (($file[$section] ?? []) as $entry) {
				$key = $this->registrationKey($entry);

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					sprintf(
						"Two '%s' entries register as '%s', so Nextcloud keeps only the last one.\n"
						. "  kept:        %s %s\n"
						. "  OVERWRITTEN: %s %s\n"
						. "Give each entry its own 'postfix'.",
						$section,
						$key,
						$entry['verb'] ?? 'GET',
						$entry['url'] ?? '?',
						$seen[$key]['verb'] ?? 'GET',
						$seen[$key]['url'] ?? '?'
					)
				);

				$seen[$key] = $entry;
			}
		}

	}//end testEveryDeclaredRouteRegistersUnderItsOwnName()

	/**
	 * Name the two routes that were lost, by URL and verb.
	 *
	 * Counting is not enough: a later edit can keep the count and lose these
	 * two again. `integriq.ui.dashboard` stays on the catch-all deliberately,
	 * because info.xml navigation and `Flow\SynchronizationLogActions` both
	 * resolve that name and the latter passes a `path` parameter.
	 *
	 * @return void
	 */
	public function testTheTwoRoutesThatWereLostAreRoutedAgain(): void {
		$entries = $this->routeFile()['routes'];

		$byKey = [];
		foreach ($entries as $entry) {
			$byKey[$this->registrationKey($entry)] = ($entry['verb'] ?? 'GET') . ' ' . $entry['url'];
		}

		$this->assertSame(
			'GET /api/lti/{deployment}/login',
			($byKey['lti#loginget'] ?? null),
			'the GET half of LTI login initiation must keep its own route name'
		);
		$this->assertSame(
			'POST /api/lti/{deployment}/login',
			($byKey['lti#login'] ?? null),
			'the POST half of LTI login initiation must stay reachable'
		);
		$this->assertSame(
			'GET /',
			($byKey['ui#dashboardindex'] ?? null),
			'the dashboard index route must keep its own route name'
		);
		$this->assertSame(
			'GET /{path}',
			($byKey['ui#dashboard'] ?? null),
			'the SPA catch-all must keep the name info.xml and Flow resolve'
		);

	}//end testTheTwoRoutesThatWereLostAreRoutedAgain()

}//end class
