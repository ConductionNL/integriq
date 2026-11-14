<?php

/**
 * Integriq ProtectedResourceMetadata
 *
 * RFC 9728 protected-resource metadata for token-protected endpoints
 * (access-oauth-and-token-validation, REQ-TOKV-004): where a client gets a
 * token for an endpoint, and the `WWW-Authenticate` challenge a 401 from that
 * endpoint carries so a client finds the document by itself.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use Throwable;

/**
 * Builds the metadata document and the 401 challenge.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 */
class ProtectedResourceMetadata {

	/**
	 * The authentication rule types that require a bearer token.
	 */
	public const TOKEN_TYPES = ['jwt', 'jwt-zgw'];

	/**
	 * Where the metadata documents live, under the app.
	 */
	public const WELL_KNOWN = '/.well-known/oauth-protected-resource/';

	/**
	 * The integriq register.
	 */
	private const REGISTER = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService OpenRegister object service (endpoints and rules).
	 * @param IURLGenerator    $urlGenerator  Builds the absolute addresses.
	 * @param IssuerKeyService $issuerKey     Integriq's own issuer, the authorization server.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IssuerKeyService $issuerKey,
	) {
	}//end __construct()

	/**
	 * Whether an authentication rule's configuration requires a bearer token.
	 *
	 * @param array<string, mixed> $authentication The rule's `configuration.authentication`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public static function requiresToken(array $authentication): bool {
		return in_array((string) ($authentication['type'] ?? ''), self::TOKEN_TYPES, true);
	}//end requiresToken()

	/**
	 * The metadata address of an endpoint path.
	 *
	 * @param string $endpointPath The endpoint's path, as on the endpoint.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function metadataUrl(string $endpointPath): string {
		return $this->urlGenerator->getAbsoluteURL('/apps/integriq' . self::WELL_KNOWN . trim($endpointPath, '/'));
	}//end metadataUrl()

	/**
	 * The `WWW-Authenticate` value of a 401 from a token-protected endpoint.
	 *
	 * @param string $endpointPath The endpoint's path.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function challenge(string $endpointPath): string {
		return 'Bearer resource_metadata="' . $this->metadataUrl(endpointPath: $endpointPath) . '"';
	}//end challenge()

	/**
	 * The metadata of the endpoint with this path, or null when it is not token-protected or does not exist.
	 *
	 * @param string $endpointPath The endpoint's path.
	 *
	 * @return array<string, mixed>|null resource, authorization_servers, scopes_supported and bearer_methods_supported.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
	 */
	public function forPath(string $endpointPath): ?array {
		$path = trim($endpointPath, '/');
		if ($path === '') {
			return null;
		}

		$authentications = [];
		foreach ($this->endpointsAt(path: $path) as $endpoint) {
			foreach ((array) ($endpoint['rules'] ?? []) as $ruleId) {
				$authentication = $this->authenticationOf(ruleId: (string) $ruleId);
				if ($authentication !== null && self::requiresToken(authentication: $authentication) === true) {
					$authentications[] = $authentication;
				}
			}
		}

		if ($authentications === []) {
			return null;
		}

		return [
			'resource'                 => $this->urlGenerator->getAbsoluteURL('/apps/integriq/api/endpoint/' . $path),
			'authorization_servers'    => [$this->issuerKey->issuerUrl()],
			'scopes_supported'         => self::scopesOf(authentications: $authentications),
			'bearer_methods_supported' => ['header'],
		];
	}//end forPath()

	/**
	 * Every scope the rules require, for any method.
	 *
	 * @param array<int, array<string, mixed>> $authentications The token-requiring authentication configurations.
	 *
	 * @return array<int, string>
	 */
	private static function scopesOf(array $authentications): array {
		$scopes = [];
		foreach ($authentications as $authentication) {
			$required = (array) ($authentication['requiredScopes'] ?? []);
			array_walk_recursive(
				$required,
				static function (mixed $scope) use (&$scopes): void {
					if (is_string($scope) === true && $scope !== '') {
						$scopes[$scope] = true;
					}
				}
			);
		}

		$names = array_keys($scopes);
		sort($names);

		return $names;
	}//end scopesOf()

	/**
	 * The endpoints whose path is this one.
	 *
	 * @param string $path The path, without slashes around it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function endpointsAt(string $path): array {
		try {
			$found = $this->objectService->setRegister(self::REGISTER)->setSchema('endpoint')->findAll(
				config: ['filters' => ['endpoint' => $path], 'limit' => 20],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return [];
		}

		$endpoints = [];
		foreach ($found as $object) {
			if ($object instanceof ObjectEntity && trim((string) ($object->getObject()['endpoint'] ?? ''), '/') === $path) {
				$endpoints[] = $object->getObject();
			}
		}

		return $endpoints;
	}//end endpointsAt()

	/**
	 * The authentication configuration of an authentication rule, or null for any other rule.
	 *
	 * Read without rendering, like the rule pipeline does, and only the type and the scopes are used.
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return array<string, mixed>|null
	 */
	private function authenticationOf(string $ruleId): ?array {
		try {
			$rule = $this->objectService->find(id: $ruleId, register: self::REGISTER, schema: 'rule', _rbac: false, _multitenancy: false, _render: false);
		} catch (Throwable $e) {
			return null;
		}

		if (($rule instanceof ObjectEntity) === false || ($rule->getObject()['type'] ?? null) !== 'authentication') {
			return null;
		}

		$authentication = ($rule->getObject()['configuration']['authentication'] ?? null);
		if (is_array($authentication) === false) {
			return null;
		}

		return ['type' => ($authentication['type'] ?? ''), 'requiredScopes' => ($authentication['requiredScopes'] ?? [])];
	}//end authenticationOf()
}//end class
