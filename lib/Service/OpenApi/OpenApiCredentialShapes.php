<?php

/**
 * Integriq OpenApiCredentialShapes.
 *
 * Turns the security schemes of an imported document into credential
 * placeholders, and strips every secret-shaped value from a document before
 * it is stored. No secret is ever read from a document.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

/**
 * The credential placeholders of an imported document's security schemes.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
class OpenApiCredentialShapes {

	/**
	 * The keys a stored security scheme keeps; anything else (vendor extensions, examples) is dropped.
	 *
	 * @var array<int,string>
	 */
	private const SCHEME_KEYS = ['type', 'description', 'name', 'in', 'scheme', 'bearerFormat', 'flows', 'openIdConnectUrl'];

	/**
	 * One credential placeholder per security scheme: what the administrator must fill. No value is read.
	 *
	 * @param array<string,mixed> $document The OpenAPI 3.x document.
	 *
	 * @return array<int,array<string,mixed>> The placeholders.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function credentialPlaceholders(array $document): array {
		$placeholders = [];
		foreach ((array) ($document['components']['securitySchemes'] ?? []) as $schemeName => $scheme) {
			if (is_array($scheme) === false) {
				continue;
			}

			$placeholders[] = self::placeholderOf(schemeName: (string) $schemeName, scheme: $scheme);
		}//end foreach

		return $placeholders;
	}//end credentialPlaceholders()

	/**
	 * The credential placeholder of one security scheme.
	 *
	 * @param string              $schemeName The scheme's name.
	 * @param array<string,mixed> $scheme     The security scheme.
	 *
	 * @return array<string,mixed> The placeholder.
	 */
	private static function placeholderOf(string $schemeName, array $scheme): array {
		$type = (string) ($scheme['type'] ?? '');
		$placeholder = ['scheme' => $schemeName, 'type' => $type, 'credentialRef' => null, 'supported' => true];

		return match ($type) {
			'apiKey' => $placeholder + self::apiKeyPlaceholder(scheme: $scheme),
			'http' => $placeholder + self::httpPlaceholder(scheme: $scheme),
			'oauth2' => array_merge($placeholder, self::oauth2Placeholder(scheme: $scheme)),
			default => array_merge($placeholder, ['required' => [], 'supported' => false]),
		};
	}//end placeholderOf()

	/**
	 * What an `apiKey` scheme's placeholder adds: where the key goes and that it is required.
	 *
	 * @param array<string,mixed> $scheme The security scheme.
	 *
	 * @return array<string,mixed> The added fields.
	 */
	private static function apiKeyPlaceholder(array $scheme): array {
		return [
			'in' => (string) ($scheme['in'] ?? 'header'),
			'name' => (string) ($scheme['name'] ?? ''),
			'required' => ['apiKey'],
		];
	}//end apiKeyPlaceholder()

	/**
	 * What an `http` scheme's placeholder adds: the scheme and the fields it requires.
	 *
	 * @param array<string,mixed> $scheme The security scheme.
	 *
	 * @return array<string,mixed> The added fields.
	 */
	private static function httpPlaceholder(array $scheme): array {
		$httpScheme = strtolower((string) ($scheme['scheme'] ?? 'bearer'));
		$required = ['token'];
		if ($httpScheme === 'basic') {
			$required = ['username', 'password'];
		}

		return ['httpScheme' => $httpScheme, 'required' => $required];
	}//end httpPlaceholder()

	/**
	 * What an `oauth2` scheme's placeholder adds: its client-credentials flow, supported only when present.
	 *
	 * @param array<string,mixed> $scheme The security scheme.
	 *
	 * @return array<string,mixed> The added fields.
	 */
	private static function oauth2Placeholder(array $scheme): array {
		$flow = (array) ($scheme['flows']['clientCredentials'] ?? []);

		return [
			'flow' => 'clientCredentials',
			'tokenUrl' => (string) ($flow['tokenUrl'] ?? ''),
			'scopes' => array_keys((array) ($flow['scopes'] ?? [])),
			'required' => ['clientId', 'clientSecret'],
			'supported' => ($flow !== []),
		];
	}//end oauth2Placeholder()

	/**
	 * The document as kept on the source: security schemes stripped to their shape.
	 *
	 * A scheme only names where a credential goes; a vendor extension or
	 * example on it could carry a value, so only the known keys are kept.
	 *
	 * @param array<string,mixed> $document The vendor document.
	 *
	 * @return array<string,mixed> The document to keep.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function withoutSecretShapes(array $document): array {
		foreach (['securityDefinitions'] as $key) {
			if (is_array($document[$key] ?? null) === true) {
				$document[$key] = array_map(static fn (mixed $scheme): array => self::schemeShape(scheme: (array) $scheme, swagger: true), $document[$key]);
			}
		}

		if (is_array($document['components']['securitySchemes'] ?? null) === true) {
			$document['components']['securitySchemes'] = array_map(
				static fn (mixed $scheme): array => self::schemeShape(scheme: (array) $scheme, swagger: false),
				$document['components']['securitySchemes']
			);
		}

		return $document;
	}//end withoutSecretShapes()

	/**
	 * A security scheme with only its known keys.
	 *
	 * @param array<string,mixed> $scheme  The scheme.
	 * @param bool                $swagger Whether it is a Swagger 2.0 security definition.
	 *
	 * @return array<string,mixed> The scheme's shape.
	 */
	private static function schemeShape(array $scheme, bool $swagger): array {
		$keys = self::SCHEME_KEYS;
		if ($swagger === true) {
			$keys = array_merge($keys, ['flow', 'authorizationUrl', 'tokenUrl', 'scopes']);
		}

		return array_intersect_key($scheme, array_flip($keys));
	}//end schemeShape()
}//end class
