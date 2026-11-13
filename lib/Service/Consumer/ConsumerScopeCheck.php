<?php

/**
 * Integriq ConsumerScopeCheck.
 *
 * Which scopes an endpoint's authentication rule requires for a method, which
 * scopes the caller holds, and which of the required ones it lacks.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Scopes are opt-in per endpoint: a rule without `requiredScopes` requires
 * none, so it behaves as before. A caller holds the scopes its token's
 * `scope` claim (space-delimited, RFC 8693) or `scp` claim (a list) names;
 * a token without either, or an API key, holds its consumer's stored
 * `scopes`. A caller without a consumer holds none.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */
final class ConsumerScopeCheck {

	/**
	 * The scopes a rule requires for a method.
	 *
	 * `requiredScopes` is either a list (required for every method) or a map
	 * of HTTP method to list; a `*` entry of the map applies to every method
	 * as well.
	 *
	 * @param array<string,mixed> $authentication The rule's `authentication` configuration.
	 * @param string              $method         The request's HTTP method.
	 *
	 * @return array<int,string> The required scopes, without duplicates.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
	 */
	public static function requiredFor(array $authentication, string $method): array {
		$required = ($authentication['requiredScopes'] ?? []);
		if (is_array($required) === false || $required === []) {
			return [];
		}

		if (array_is_list($required) === true) {
			return self::strings(values: $required);
		}

		$scopes = [];
		$method = strtoupper($method);
		foreach ($required as $key => $list) {
			$key = strtoupper((string)$key);
			if (($key === $method || $key === '*') && is_array($list) === true) {
				$scopes = array_merge($scopes, self::strings(values: $list));
			}
		}

		return array_values(array_unique($scopes));
	}//end requiredFor()

	/**
	 * The scopes a caller holds.
	 *
	 * @param ObjectEntity|null        $consumer    The consumer the call authenticated as, if any.
	 * @param array<string,mixed>|null $tokenClaims The claims of the caller's JWT, or null for a call without one.
	 *
	 * @return array<int,string> The held scopes.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
	 */
	public static function heldBy(?ObjectEntity $consumer, ?array $tokenClaims): array {
		if ($tokenClaims !== null) {
			if (is_string($tokenClaims['scope'] ?? null) === true) {
				return self::strings(values: preg_split('/\s+/', trim($tokenClaims['scope'])));
			}

			if (is_array($tokenClaims['scp'] ?? null) === true) {
				return self::strings(values: $tokenClaims['scp']);
			}
		}

		if ($consumer === null) {
			return [];
		}

		$stored = ($consumer->getObject()['scopes'] ?? []);
		if (is_array($stored) === false) {
			return [];
		}

		return self::strings(values: $stored);
	}//end heldBy()

	/**
	 * The required scopes the caller lacks, in the order they are required.
	 *
	 * @param array<int,string> $required The required scopes.
	 * @param array<int,string> $held     The held scopes.
	 *
	 * @return array<int,string> The missing scopes; empty when the call may go on.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
	 */
	public static function missing(array $required, array $held): array {
		return array_values(array_diff($required, $held));
	}//end missing()

	/**
	 * The non-empty strings of a list, once each.
	 *
	 * @param array<mixed>|false $values The values.
	 *
	 * @return array<int,string> The strings.
	 */
	private static function strings(array|false $values): array {
		if ($values === false) {
			return [];
		}

		$strings = [];
		foreach ($values as $value) {
			if (is_string($value) === true && trim($value) !== '') {
				$strings[] = trim($value);
			}
		}

		return array_values(array_unique($strings));
	}//end strings()
}//end class
