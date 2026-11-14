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
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A vendor document is not integriq's configuration envelope (decision D1 of this change): it
 * is read here, on its own terms, and never trusted further than its shape.
 * Swagger 2.0 is converted to the OpenAPI 3.0 shape on read: host, basePath
 * and schemes become servers, securityDefinitions become
 * components.securitySchemes, definitions become components.schemas, body and
 * formData parameters become a requestBody, and response schemas move under
 * content.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
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
	 * The parameter keys Swagger 2.0 keeps on the parameter that OpenAPI 3 keeps in its schema.
	 *
	 * @var array<int,string>
	 */
	private const SCHEMA_KEYS = [
		'type',
		'format',
		'items',
		'enum',
		'default',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
		'minLength',
		'maxLength',
		'pattern',
		'minItems',
		'maxItems',
		'uniqueItems',
		'multipleOf',
	];

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
	 * Decode a document given as JSON or YAML text.
	 *
	 * @param string $text The document text.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws InvalidArgumentException When the text is neither a JSON nor a YAML object.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function decode(string $text): array {
		$decoded = json_decode($text, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		try {
			$decoded = Yaml::parse($text);
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
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
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
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
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
				fn (mixed $parameter): array => $this->parameterOf(parameter: (array) $parameter),
				array_filter(
					$swagger['parameters'],
					static fn (mixed $p): bool => is_array($p) === true && in_array(($p['in'] ?? ''), ['body', 'formData'], true) === false
				)
			);
		}

		if ($components !== []) {
			$document['components'] = $components;
		}

		foreach ((array) ($swagger['paths'] ?? []) as $path => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$document['paths'][$path] = $this->pathItemOf(item: $item, consumes: $consumes, produces: $produces);
		}

		return $document;
	}//end fromSwagger()

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
	 * A Swagger 2.0 path item in the OpenAPI 3 shape.
	 *
	 * @param array<string,mixed> $item     The Swagger 2.0 path item.
	 * @param array<int,string>   $consumes The document's default request media types.
	 * @param array<int,string>   $produces The document's default response media types.
	 *
	 * @return array<string,mixed> The OpenAPI 3 path item.
	 */
	private function pathItemOf(array $item, array $consumes, array $produces): array {
		$converted = [];
		$shared = (array) ($item['parameters'] ?? []);
		if ($shared !== []) {
			$converted['parameters'] = $this->parametersOf(parameters: $shared);
		}

		foreach (self::METHODS as $method) {
			if (is_array($item[$method] ?? null) === false) {
				continue;
			}

			$converted[$method] = $this->operationOf(operation: $item[$method], shared: $shared, consumes: $consumes, produces: $produces);
		}

		return $converted;
	}//end pathItemOf()

	/**
	 * A Swagger 2.0 operation in the OpenAPI 3 shape.
	 *
	 * @param array<string,mixed>            $operation The Swagger 2.0 operation.
	 * @param array<int,array<string,mixed>> $shared    The path item's parameters, for its body parameter.
	 * @param array<int,string>              $consumes  The document's default request media types.
	 * @param array<int,string>              $produces  The document's default response media types.
	 *
	 * @return array<string,mixed> The OpenAPI 3 operation.
	 */
	private function operationOf(array $operation, array $shared, array $consumes, array $produces): array {
		$consumes = self::stringList(value: ($operation['consumes'] ?? $consumes));
		$produces = self::stringList(value: ($operation['produces'] ?? $produces));
		$parameters = (array) ($operation['parameters'] ?? []);

		$converted = $operation;
		unset($converted['consumes'], $converted['produces'], $converted['parameters'], $converted['schemes']);

		$plain = $this->parametersOf(parameters: $parameters);
		if ($plain !== []) {
			$converted['parameters'] = $plain;
		}

		$requestBody = $this->requestBodyOf(parameters: array_merge($shared, $parameters), consumes: $consumes);
		if ($requestBody !== null) {
			$converted['requestBody'] = $requestBody;
		}

		$responses = [];
		foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
			$responses[(string) $status] = $this->responseOf(response: (array) $response, produces: $produces);
		}

		$converted['responses'] = $responses;
		if ($responses === []) {
			$converted['responses'] = ['default' => ['description' => 'Response']];
		}

		return $converted;
	}//end operationOf()

	/**
	 * The non-body parameters, each with its type moved into a schema.
	 *
	 * @param array<int,mixed> $parameters The Swagger 2.0 parameters.
	 *
	 * @return array<int,array<string,mixed>> The OpenAPI 3 parameters.
	 */
	private function parametersOf(array $parameters): array {
		$converted = [];
		foreach ($parameters as $parameter) {
			if (is_array($parameter) === false || in_array(($parameter['in'] ?? ''), ['body', 'formData'], true) === true) {
				continue;
			}

			$converted[] = $this->parameterOf(parameter: $parameter);
		}

		return $converted;
	}//end parametersOf()

	/**
	 * One Swagger 2.0 parameter in the OpenAPI 3 shape.
	 *
	 * @param array<string,mixed> $parameter The Swagger 2.0 parameter.
	 *
	 * @return array<string,mixed> The OpenAPI 3 parameter.
	 */
	private function parameterOf(array $parameter): array {
		if (isset($parameter['$ref']) === true) {
			return $parameter;
		}

		$schema = [];
		foreach (self::SCHEMA_KEYS as $key) {
			if (array_key_exists($key, $parameter) === true) {
				$schema[$key] = $parameter[$key];
				unset($parameter[$key]);
			}
		}

		unset($parameter['collectionFormat'], $parameter['allowEmptyValue']);
		if ($schema !== []) {
			$parameter['schema'] = $schema;
		}

		return $parameter;
	}//end parameterOf()

	/**
	 * The request body a Swagger 2.0 body or formData parameter describes.
	 *
	 * @param array<int,mixed>  $parameters The operation's and path item's parameters.
	 * @param array<int,string> $consumes   The request media types.
	 *
	 * @return array<string,mixed>|null The request body, or null when there is none.
	 */
	private function requestBodyOf(array $parameters, array $consumes): ?array {
		$form = ['type' => 'object', 'properties' => []];
		$formRequired = [];
		foreach ($parameters as $parameter) {
			if (is_array($parameter) === false) {
				continue;
			}

			if (($parameter['in'] ?? '') === 'body') {
				$content = [];
				foreach ($consumes as $mediaType) {
					$content[$mediaType] = ['schema' => (array) ($parameter['schema'] ?? [])];
				}

				return array_filter(
					[
						'description' => ($parameter['description'] ?? null),
						'required' => (bool) ($parameter['required'] ?? false),
						'content' => $content,
					],
					static fn (mixed $value): bool => $value !== null
				);
			}

			if (($parameter['in'] ?? '') === 'formData') {
				$name = (string) ($parameter['name'] ?? '');
				$form['properties'][$name] = ($this->parameterOf(parameter: $parameter)['schema'] ?? ['type' => 'string']);
				if (($parameter['required'] ?? false) === true) {
					$formRequired[] = $name;
				}
			}
		}//end foreach

		if ($form['properties'] === []) {
			return null;
		}

		if ($formRequired !== []) {
			$form['required'] = $formRequired;
		}

		$mediaType = 'application/x-www-form-urlencoded';
		if (in_array('multipart/form-data', $consumes, true) === true) {
			$mediaType = 'multipart/form-data';
		}

		return ['content' => [$mediaType => ['schema' => $form]]];
	}//end requestBodyOf()

	/**
	 * A Swagger 2.0 response in the OpenAPI 3 shape: its schema under content.
	 *
	 * @param array<string,mixed> $response The Swagger 2.0 response.
	 * @param array<int,string>   $produces The response media types.
	 *
	 * @return array<string,mixed> The OpenAPI 3 response.
	 */
	private function responseOf(array $response, array $produces): array {
		if (isset($response['$ref']) === true) {
			return $response;
		}

		$converted = ['description' => (string) ($response['description'] ?? '')];
		if (isset($response['schema']) === true) {
			$converted['content'] = [];
			foreach ($produces as $mediaType) {
				$converted['content'][$mediaType] = ['schema' => (array) $response['schema']];
			}
		}

		if (is_array($response['headers'] ?? null) === true) {
			$converted['headers'] = array_map(
				static fn (mixed $header): array => ['schema' => (array) $header],
				$response['headers']
			);
		}

		return $converted;
	}//end responseOf()

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
