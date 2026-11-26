<?php

/**
 * Integriq OpenApiDocumentNormaliser.
 *
 * Reads a vendor's OpenAPI 3.0/3.1 or Swagger 2.0 document and answers it in
 * the OpenAPI 3.x shape, so the import and publish services read one shape.
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
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

/**
 * A vendor document is not integriq's configuration envelope (decision D1 of this change): it
 * is read here, on its own terms, and never trusted further than its shape.
 * Swagger 2.0 is converted to the OpenAPI 3.0 shape on read: host, basePath
 * and schemes become servers, securityDefinitions become
 * components.securitySchemes, definitions become components.schemas, body and
 * formData parameters become a requestBody, and response schemas move under
 * content.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class OpenApiDocumentNormaliser {

	/**
	 * The HTTP methods an OpenAPI path item can carry operations under.
	 *
	 * @var array<int,string>
	 */
	public const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

	/**
	 * Swagger 2.0 OAuth2 flow names and their OpenAPI 3 names.
	 *
	 * @var array<string,string>
	 */
	private const OAUTH_FLOWS = [
		'application' => 'clientCredentials',
		'accessCode' => 'authorizationCode',
		'implicit' => 'implicit',
		'password' => 'password',
	];

	/**
	 * Constructor.
	 *
	 * @param SwaggerOperationConverter $operations Converts Swagger 2.0 path items and operations.
	 */
	public function __construct(
		private readonly SwaggerOperationConverter $operations = new SwaggerOperationConverter(),
	) {
	}//end __construct()

	/**
	 * Decode a document given as JSON or YAML text.
	 *
	 * @param string $text The document text.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws InvalidArgumentException When the text is neither a JSON nor a YAML object.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function decode(string $text): array {
		$decoded = json_decode($text, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		try {
			$decoded = (new Parser())->parse($text);
		} catch (ParseException $exception) {
			throw new InvalidArgumentException('The document is neither JSON nor YAML: '.$exception->getMessage());
		}

		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('The document is not an object.');
		}

		return $decoded;
	}//end decode()

	/**
	 * The version a document declares: `2.0`, `3.0.x` or `3.1.x`.
	 *
	 * @param array<string,mixed> $document The decoded document.
	 *
	 * @return string The declared version.
	 *
	 * @throws InvalidArgumentException When the document is not OpenAPI 3.0/3.1 or Swagger 2.0.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function versionOf(array $document): string {
		if ((string) ($document['swagger'] ?? '') === '2.0') {
			return '2.0';
		}

		$version = (string) ($document['openapi'] ?? '');
		if (preg_match('/^3\.[01]\.\d+/', $version) === 1) {
			return $version;
		}

		throw new InvalidArgumentException('The document is not an OpenAPI 3.0, 3.1 or Swagger 2.0 document.');
	}//end versionOf()

	/**
	 * The document in the OpenAPI 3.x shape; a Swagger 2.0 document is converted.
	 *
	 * @param array<string,mixed> $document The decoded document.
	 *
	 * @return array<string,mixed> The document as OpenAPI 3.x.
	 *
	 * @throws InvalidArgumentException When the document is not OpenAPI 3.0/3.1 or Swagger 2.0.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function normalise(array $document): array {
		if ($this->versionOf(document: $document) !== '2.0') {
			if (is_array($document['paths'] ?? null) === false) {
				$document['paths'] = [];
			}

			return $document;
		}

		return $this->fromSwagger(swagger: $document);
	}//end normalise()

	/**
	 * Convert a Swagger 2.0 document to the OpenAPI 3.0 shape.
	 *
	 * @param array<string,mixed> $swagger The Swagger 2.0 document.
	 *
	 * @return array<string,mixed> The OpenAPI 3.0 document.
	 */
	private function fromSwagger(array $swagger): array {
		$swagger = self::rewriteRefs(value: $swagger);
		$consumes = self::stringList(value: ($swagger['consumes'] ?? ['application/json']));
		$produces = self::stringList(value: ($swagger['produces'] ?? ['application/json']));

		$document = [
			'openapi' => '3.0.3',
			'info' => (array) ($swagger['info'] ?? ['title' => '', 'version' => '']),
			'servers' => $this->serversOf(swagger: $swagger),
			'paths' => [],
		];

		foreach (['tags', 'security', 'externalDocs'] as $key) {
			if (isset($swagger[$key]) === true) {
				$document[$key] = $swagger[$key];
			}
		}

		$components = $this->componentsOf(swagger: $swagger);
		if ($components !== []) {
			$document['components'] = $components;
		}

		$document['paths'] = $this->pathsOf(swagger: $swagger, consumes: $consumes, produces: $produces);

		return $document;
	}//end fromSwagger()

	/**
	 * The OpenAPI 3 components of a Swagger 2.0 document: schemas, security schemes and parameters.
	 *
	 * @param array<string,mixed> $swagger The Swagger 2.0 document.
	 *
	 * @return array<string,mixed> The components, empty when the document has none.
	 */
	private function componentsOf(array $swagger): array {
		$components = [];
		if (is_array($swagger['definitions'] ?? null) === true) {
			$components['schemas'] = $swagger['definitions'];
		}

		if (is_array($swagger['securityDefinitions'] ?? null) === true) {
			$components['securitySchemes'] = array_map(
				fn (mixed $scheme): array => $this->securitySchemeOf(scheme: (array) $scheme),
				$swagger['securityDefinitions']
			);
		}

		if (is_array($swagger['parameters'] ?? null) === true) {
			$components['parameters'] = array_map(
				fn (mixed $parameter): array => $this->operations->parameterOf(parameter: (array) $parameter),
				array_filter(
					$swagger['parameters'],
					static fn (mixed $parameter): bool => is_array($parameter) === true && in_array(($parameter['in'] ?? ''), ['body', 'formData'], true) === false
				)
			);
		}

		return $components;
	}//end componentsOf()

	/**
	 * The OpenAPI 3 paths of a Swagger 2.0 document.
	 *
	 * @param array<string,mixed> $swagger  The Swagger 2.0 document.
	 * @param array<int,string>   $consumes The request media types.
	 * @param array<int,string>   $produces The response media types.
	 *
	 * @return array<string,mixed> The path items by path.
	 */
	private function pathsOf(array $swagger, array $consumes, array $produces): array {
		$paths = [];
		foreach ((array) ($swagger['paths'] ?? []) as $path => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$paths[$path] = $this->operations->pathItemOf(item: $item, consumes: $consumes, produces: $produces);
		}

		return $paths;
	}//end pathsOf()

	/**
	 * The OpenAPI 3 servers of a Swagger 2.0 document: one per scheme, from host and basePath.
	 *
	 * @param array<string,mixed> $swagger The Swagger 2.0 document.
	 *
	 * @return array<int,array{url:string}> The servers.
	 */
	private function serversOf(array $swagger): array {
		$basePath = '/'.ltrim((string) ($swagger['basePath'] ?? ''), '/');
		$host = (string) ($swagger['host'] ?? '');
		if ($host === '') {
			return [['url' => rtrim($basePath, '/')]];
		}

		$servers = [];
		foreach (self::stringList(value: ($swagger['schemes'] ?? ['https'])) as $scheme) {
			$servers[] = ['url' => rtrim($scheme.'://'.$host.$basePath, '/')];
		}

		if ($servers === []) {
			$servers[] = ['url' => rtrim('https://'.$host.$basePath, '/')];
		}

		return $servers;
	}//end serversOf()

	/**
	 * A Swagger 2.0 security definition as an OpenAPI 3 security scheme.
	 *
	 * @param array<string,mixed> $scheme The Swagger 2.0 security definition.
	 *
	 * @return array<string,mixed> The OpenAPI 3 security scheme.
	 */
	private function securitySchemeOf(array $scheme): array {
		$type = (string) ($scheme['type'] ?? '');
		$converted = ['type' => $type];
		if ($type === 'basic') {
			$converted = ['type' => 'http', 'scheme' => 'basic'];
		}

		if ($type === 'apiKey') {
			$converted['name'] = (string) ($scheme['name'] ?? '');
			$converted['in'] = (string) ($scheme['in'] ?? 'header');
		}

		if ($type === 'oauth2') {
			$flow = (self::OAUTH_FLOWS[(string) ($scheme['flow'] ?? '')] ?? 'clientCredentials');
			$converted['flows'] = [
				$flow => array_filter(
					[
						'authorizationUrl' => ($scheme['authorizationUrl'] ?? null),
						'tokenUrl' => ($scheme['tokenUrl'] ?? null),
						'scopes' => (array) ($scheme['scopes'] ?? []),
					],
					static fn (mixed $value): bool => $value !== null
				),
			];
		}

		if (isset($scheme['description']) === true) {
			$converted['description'] = (string) $scheme['description'];
		}

		return $converted;
	}//end securitySchemeOf()

	/**
	 * Rewrite Swagger 2.0 `$ref`s to their OpenAPI 3 components.
	 *
	 * @param mixed $value Any part of the document.
	 *
	 * @return mixed The part with its references rewritten.
	 */
	private static function rewriteRefs(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === '$ref' && is_string($item) === true) {
				$value[$key] = str_replace(
					['#/definitions/', '#/parameters/', '#/responses/'],
					['#/components/schemas/', '#/components/parameters/', '#/components/responses/'],
					$item
				);
				continue;
			}

			$value[$key] = self::rewriteRefs(value: $item);
		}

		return $value;
	}//end rewriteRefs()

	/**
	 * The strings of a list; anything else is dropped.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int,string> The strings.
	 */
	private static function stringList(mixed $value): array {
		return array_values(array_filter((array) $value, static fn (mixed $item): bool => is_string($item) === true && $item !== ''));
	}//end stringList()
}//end class
