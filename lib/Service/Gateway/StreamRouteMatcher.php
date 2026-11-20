<?php

/**
 * Integriq StreamRouteMatcher
 *
 * Whether a connection's path or topic falls under a stream endpoint's route.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Gateway;

/**
 * Matches a connection target against a stream endpoint's `streamRoute`.
 *
 * A WebSocket or gRPC route is a path: equal (a trailing slash aside), or a
 * prefix when it ends in `*`. An MQTT route is a topic filter with `+` (one
 * level) and `#` (the rest, last only), matched level by level as a broker
 * does. Nothing here reads a request or a store.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */
final class StreamRouteMatcher {

	/**
	 * Whether a connection target is under a route.
	 *
	 * @param string $protocol `websocket`, `grpc` or `mqtt`.
	 * @param string $route    The endpoint's `streamRoute`.
	 * @param string $target   The path the client asked for, or the MQTT topic.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
	 */
	public function matches(string $protocol, string $route, string $target): bool {
		$route = trim($route);
		if ($route === '') {
			return false;
		}

		if ($protocol === 'mqtt') {
			return self::topicMatches(filter: $route, topic: trim($target));
		}

		return self::pathMatches(route: $route, path: self::pathOf(target: $target));

	}//end matches()

	/**
	 * The path of a target that may carry a query string.
	 *
	 * @param string $target The target.
	 *
	 * @return string
	 */
	private static function pathOf(string $target): string {
		$path = (string)(parse_url(trim($target), PHP_URL_PATH) ?? '');
		return '/' . ltrim($path, '/');

	}//end pathOf()

	/**
	 * Path equality, or prefix for a route ending in `*`.
	 *
	 * @param string $route The route.
	 * @param string $path  The path.
	 *
	 * @return bool
	 */
	private static function pathMatches(string $route, string $path): bool {
		$route = '/' . ltrim($route, '/');
		if (str_ends_with($route, '*') === true) {
			return str_starts_with($path, substr($route, 0, -1));
		}

		return rtrim($route, '/') === rtrim($path, '/');

	}//end pathMatches()

	/**
	 * MQTT topic-filter matching.
	 *
	 * @param string $filter The topic filter.
	 * @param string $topic  The topic.
	 *
	 * @return bool
	 */
	private static function topicMatches(string $filter, string $topic): bool {
		if ($topic === '' || str_contains($topic, '+') === true || str_contains($topic, '#') === true) {
			return false;
		}

		$filterLevels = explode('/', $filter);
		$topicLevels  = explode('/', $topic);
		foreach ($filterLevels as $index => $level) {
			if ($level === '#') {
				return $index === (count($filterLevels) - 1);
			}

			if (array_key_exists($index, $topicLevels) === false) {
				return false;
			}

			if ($level !== '+' && $level !== $topicLevels[$index]) {
				return false;
			}
		}

		return count($filterLevels) === count($topicLevels);

	}//end topicMatches()
}//end class
