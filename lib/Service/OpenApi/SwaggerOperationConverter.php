<?php

/**
 * Integriq SwaggerOperationConverter.
 *
 * Converts the path items, operations, parameters, request bodies and
 * responses of a Swagger 2.0 document into their OpenAPI 3.x shape.
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

/**
 * Swagger 2.0 path items and their operations, in the OpenAPI 3.x shape.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
class SwaggerOperationConverter {

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
	 * A Swagger 2.0 path item in the OpenAPI 3 shape.
	 *
	 * @param array<string,mixed> $item     The Swagger 2.0 path item.
	 * @param array<int,string>   $consumes The document's default request media types.
	 * @param array<int,string>   $produces The document's default response media types.
	 *
	 * @return array<string,mixed> The OpenAPI 3 path item.
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function pathItemOf(array $item, array $consumes, array $produces): array {
		$converted = [];
		$shared = (array) ($item['parameters'] ?? []);
		if ($shared !== []) {
			$converted['parameters'] = $this->parametersOf(parameters: $shared);
		}

		foreach (OpenApiDocumentNormaliser::METHODS as $method) {
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
	 *
	 * @spec openspec/changes/archive/2026-11-25-gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function parameterOf(array $parameter): array {
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
		$parameters = array_filter($parameters, static fn (mixed $parameter): bool => is_array($parameter) === true);
		foreach ($parameters as $parameter) {
			if (($parameter['in'] ?? '') === 'body') {
				return self::bodyRequestOf(parameter: $parameter, consumes: $consumes);
			}
		}

		return $this->formRequestOf(parameters: $parameters, consumes: $consumes);
	}//end requestBodyOf()

	/**
	 * The OpenAPI 3 request body of a Swagger 2.0 `in: body` parameter.
	 *
	 * @param array<string,mixed> $parameter The body parameter.
	 * @param array<int,string>   $consumes  The request media types.
	 *
	 * @return array<string,mixed> The request body.
	 */
	private static function bodyRequestOf(array $parameter, array $consumes): array {
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
	}//end bodyRequestOf()

	/**
	 * The OpenAPI 3 request body of Swagger 2.0 `in: formData` parameters: one object schema.
	 *
	 * @param array<int|string,array<string,mixed>> $parameters The operation's parameters.
	 * @param array<int,string>                     $consumes   The request media types.
	 *
	 * @return array<string,mixed>|null The request body, null without form parameters.
	 */
	private function formRequestOf(array $parameters, array $consumes): ?array {
		$form = ['type' => 'object', 'properties' => []];
		$formRequired = [];
		foreach ($parameters as $parameter) {
			if (($parameter['in'] ?? '') !== 'formData') {
				continue;
			}

			$name = (string) ($parameter['name'] ?? '');
			$form['properties'][$name] = ($this->parameterOf(parameter: $parameter)['schema'] ?? ['type' => 'string']);
			if (($parameter['required'] ?? false) === true) {
				$formRequired[] = $name;
			}
		}

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
	}//end formRequestOf()

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
