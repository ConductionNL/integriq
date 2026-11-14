<?php

/**
 * Integriq OpenApiPublishService.
 *
 * Builds the OpenAPI 3.1 description of integriq's endpoints, for the whole
 * gateway and for one API product.
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
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * The document is generated on request from the endpoint definitions, never
 * stored (decision D3). Only the gateway path is published: a source's location
 * never appears, not even inside a schema copied from a vendor document.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OpenApiPublishService {

	/**
	 * The OpenAPI version of the published document.
	 *
	 * @var string
	 */
	public const OPENAPI_VERSION = '3.1.0';

	/**
	 * The component kinds a vendor operation may reference.
	 *
	 * @var array<int,string>
	 */
	private const COMPONENT_KINDS = ['schemas', 'parameters', 'responses', 'requestBodies', 'headers', 'examples'];

	/**
	 * The rule authentication types and the security scheme each publishes as.
	 *
	 * @var array<string,array{name:string,scheme:array<string,string>}>
	 */
	private const AUTH_SCHEMES = [
		'jwt' => ['name' => 'bearerJwt', 'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
		'jwt-zgw' => ['name' => 'bearerJwt', 'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
		'basic' => ['name' => 'basicAuth', 'scheme' => ['type' => 'http', 'scheme' => 'basic']],
		'oauth' => [
			'name' => 'oauthBearer',
			'scheme' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An OAuth 2.0 access token.'],
		],
	];

	/**
	 * The sources read while building one document, by uuid.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $sources = [];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService           $objects      OpenRegister's object service.
	 * @param OpenApiDocumentNormaliser $normaliser   Reads a source's stored vendor document.
	 * @param EndpointTargetResolver    $targets      Resolves a register/schema endpoint target.
	 * @param SchemaMapper              $schemaMapper OpenRegister's schema definitions (ADR-022).
	 * @param LoggerInterface           $logger       The logger.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
		private readonly OpenApiDocumentNormaliser $normaliser,
		private readonly EndpointTargetResolver $targets,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The document of every endpoint in a public, active API product.
	 *
	 * @param string $serverUrl The absolute URL endpoints are served under.
	 * @param string $version   The document version, the installed app version.
	 *
	 * @return array<string,mixed> The OpenAPI 3.1 document.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
	 */
	public function gatewayDocument(string $serverUrl, string $version): array {
		$endpointIds = [];
		foreach ($this->rows(schema: 'api_product') as $product) {
			$data = $product->getObject();
			if (($data['visibility'] ?? 'public') !== 'public' || ($data['status'] ?? 'active') !== 'active') {
				continue;
			}

			$endpointIds = array_merge($endpointIds, self::stringList(value: ($data['endpoints'] ?? [])));
		}

		return $this->build(
			info: ['title' => 'Integriq gateway', 'version' => $version, 'description' => 'The endpoints of every public API product.'],
			endpointIds: $endpointIds,
			serverUrl: $serverUrl,
			deprecated: false
		);
	}//end gatewayDocument()

	/**
	 * The document of one API product version.
	 *
	 * @param ObjectEntity $product   The api_product row.
	 * @param string       $serverUrl The absolute URL endpoints are served under.
	 *
	 * @return array<string,mixed> The OpenAPI 3.1 document.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
	 */
	public function productDocument(ObjectEntity $product, string $serverUrl): array {
		$data = $product->getObject();
		$deprecated = (($data['status'] ?? 'active') === 'deprecated');
		$info = [
			'title' => (string) ($data['name'] ?? ($data['productSlug'] ?? '')),
			'version' => (string) ($data['version'] ?? '1.0.0'),
		];
		if (is_string($data['description'] ?? null) === true && $data['description'] !== '') {
			$info['description'] = $data['description'];
		}

		$document = $this->build(
			info: $info,
			endpointIds: self::stringList(value: ($data['endpoints'] ?? [])),
			serverUrl: $serverUrl,
			deprecated: $deprecated
		);
		if ($deprecated === true && is_string($data['sunsetDate'] ?? null) === true && $data['sunsetDate'] !== '') {
			$document['info']['x-sunset'] = $data['sunsetDate'];
		}

		return $document;
	}//end productDocument()

	/**
	 * Build a document from a list of endpoint uuids.
	 *
	 * @param array<string,string> $info        The document's info object.
	 * @param array<int,string>    $endpointIds The endpoints, by uuid or slug.
	 * @param string               $serverUrl   The absolute URL endpoints are served under.
	 * @param bool                 $deprecated  Whether every operation is deprecated.
	 *
	 * @return array<string,mixed> The document.
	 */
	private function build(array $info, array $endpointIds, string $serverUrl, bool $deprecated): array {
		$this->sources = [];
		$document = [
			'openapi' => self::OPENAPI_VERSION,
			'info' => $info,
			'servers' => [['url' => rtrim($serverUrl, '/')]],
			'paths' => [],
			'components' => [],
		];

		$wanted = array_flip(array_unique($endpointIds));
		$rules = $this->rulesById();
		foreach ($this->rows(schema: 'endpoint') as $endpoint) {
			$data = $endpoint->getObject();
			if (isset($wanted[(string) $endpoint->getUuid()]) === false && isset($wanted[(string) ($data['slug'] ?? '')]) === false) {
				continue;
			}

			$this->addEndpoint(document: $document, endpoint: $endpoint, rules: $rules, deprecated: $deprecated);
		}

		ksort($document['paths']);
		if ($document['paths'] === []) {
			$document['paths'] = new stdClass();
		}

		$document['components'] = array_filter($document['components'], static fn (mixed $kind): bool => $kind !== []);
		if ($document['components'] === []) {
			unset($document['components']);
		}

		return $this->withoutLocations(value: $document);
	}//end build()

	/**
	 * Add one endpoint's operation.
	 *
	 * @param array<string,mixed>                $document   The document being built.
	 * @param ObjectEntity                       $endpoint   The endpoint row.
	 * @param array<string,array<string,mixed>> $rules      Every rule, by uuid and slug.
	 * @param bool                               $deprecated Whether the operation is deprecated.
	 *
	 * @return void
	 */
	private function addEndpoint(array &$document, ObjectEntity $endpoint, array $rules, bool $deprecated): void {
		$data = $endpoint->getObject();
		$segments = array_values(
			array_filter((array) ($data['endpointArray'] ?? []), static fn (mixed $part): bool => is_scalar($part) === true && (string) $part !== '')
		);
		if ($segments === []) {
			$segments = array_values(array_filter(explode('/', (string) ($data['endpoint'] ?? '')), static fn (string $part): bool => $part !== ''));
		}

		$method = strtolower((string) ($data['method'] ?? ''));
		if ($segments === [] || in_array($method, OpenApiDocumentNormaliser::METHODS, true) === false) {
			return;
		}

		$pathParameters = [];
		foreach ($segments as $index => $segment) {
			if (preg_match('#^\{\{?\s*([^}\s]+)\s*\}?\}$#', (string) $segment, $match) === 1) {
				$segments[$index] = '{'.$match[1].'}';
				$pathParameters[] = $match[1];
			}
		}

		$path = '/'.implode('/', $segments);
		$operation = $this->operationFor(document: $document, data: $data, pathParameters: $pathParameters);
		$operation['operationId'] = (string) ($data['slug'] ?? $endpoint->getUuid());
		if ($operation['operationId'] === '') {
			$operation['operationId'] = (string) $endpoint->getUuid();
		}

		if (($operation['summary'] ?? '') === '' && is_string($data['name'] ?? null) === true) {
			$operation['summary'] = $data['name'];
		}

		$security = $this->securityFor(document: $document, ruleIds: self::stringList(value: ($data['rules'] ?? [])), rules: $rules);
		if ($security !== []) {
			$operation['security'] = $security;
		}

		if ($deprecated === true) {
			$operation['deprecated'] = true;
		}

		$document['paths'][$path][$method] = $operation;
	}//end addEndpoint()

	/**
	 * The operation an endpoint publishes, by its target type.
	 *
	 * @param array<string,mixed> $document       The document being built, for its components.
	 * @param array<string,mixed> $data           The endpoint's data.
	 * @param array<int,string>   $pathParameters The gateway path's parameter names.
	 *
	 * @return array<string,mixed> The operation.
	 */
	private function operationFor(array &$document, array $data, array $pathParameters): array {
		$operation = null;
		$targetType = (string) ($data['targetType'] ?? '');
		if ($targetType === 'api') {
			$operation = $this->importedOperation(document: $document, data: $data);
		}

		if ($targetType === 'register/schema') {
			$operation = $this->registerOperation(document: $document, data: $data);
		}

		if ($operation === null) {
			$operation = [
				'responses' => [
					'200' => [
						'description' => 'The answer of the endpoint.',
						'content' => ['application/json' => ['schema' => ['type' => 'object', 'description' => 'Not described: this endpoint carries no schema.']]],
					],
				],
			];
		}

		if (is_string($data['description'] ?? null) === true && $data['description'] !== '' && isset($operation['description']) === false) {
			$operation['description'] = $data['description'];
		}

		return $this->withPathParameters(operation: $operation, names: $pathParameters);
	}//end operationFor()

	/**
	 * The operation of an imported endpoint, taken from the vendor document kept on its source.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $data     The endpoint's data.
	 *
	 * @return array<string,mixed>|null The operation, or null when the source keeps no document describing it.
	 */
	private function importedOperation(array &$document, array $data): ?array {
		$sourceId = (string) ($data['targetId'] ?? '');
		$vendor = $this->vendorDocument(sourceId: $sourceId);
		if ($vendor === null) {
			return null;
		}

		$method = strtolower((string) ($data['method'] ?? ''));
		// An imported endpoint keeps the vendor path in `upstreamPath`; its
		// `endpoint` carries the gateway prefix the vendor document lacks.
		$wanted = self::shape(path: EndpointService::upstreamTemplate(endpointData: $data));
		foreach ((array) ($vendor['paths'] ?? []) as $path => $item) {
			if (is_array($item) === false || is_array($item[$method] ?? null) === false || self::shape(path: (string) $path) !== $wanted) {
				continue;
			}

			$prefix = self::componentPrefix(sourceId: $sourceId);
			$operation = $item[$method];
			$shared = array_values(array_filter((array) ($item['parameters'] ?? []), 'is_array'));
			if ($shared !== []) {
				$operation['parameters'] = array_merge($shared, (array) ($operation['parameters'] ?? []));
			}

			unset($operation['servers'], $operation['security'], $operation['operationId'], $operation['callbacks']);
			$this->copyComponents(document: $document, vendor: $vendor, prefix: $prefix);

			return self::prefixRefs(value: $operation, prefix: $prefix);
		}

		return null;
	}//end importedOperation()

	/**
	 * The operation of a register/schema endpoint, its schema read from OpenRegister.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $data     The endpoint's data.
	 *
	 * @return array<string,mixed>|null The operation, or null when the schema cannot be read.
	 */
	private function registerOperation(array &$document, array $data): ?array {
		try {
			[, $schemaId] = $this->targets->resolve(targetId: (string) ($data['targetId'] ?? ''));
			$schema = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->info('integriq: no OpenRegister schema for published endpoint '.($data['name'] ?? '').': '.$exception->getMessage());
			return null;
		}

		$name = 'register.'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($schema->getSlug() ?? $schemaId));
		$definition = ['type' => 'object', 'title' => (string) $schema->getTitle(), 'properties' => (object) $schema->getProperties()];
		$required = array_values(array_filter((array) $schema->getRequired(), 'is_string'));
		if ($required !== []) {
			$definition['required'] = $required;
		}

		$document['components']['schemas'][$name] = $definition;
		$reference = ['$ref' => '#/components/schemas/'.$name];

		$method = strtoupper((string) ($data['method'] ?? ''));
		$segments = (array) ($data['endpointArray'] ?? []);
		$last = (string) end($segments);
		$single = (str_contains($last, '{') === true || $method !== 'GET');
		$answer = $reference;
		if ($single === false) {
			$answer = ['type' => 'object', 'properties' => ['results' => ['type' => 'array', 'items' => $reference], 'total' => ['type' => 'integer']]];
		}

		$operation = ['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $answer]]]]];
		if (in_array($method, ['POST', 'PUT', 'PATCH'], true) === true) {
			$operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $reference]]];
		}

		return $operation;
	}//end registerOperation()

	/**
	 * The security requirements an endpoint's authentication rules publish.
	 *
	 * @param array<string,mixed>                $document The document being built, for its security schemes.
	 * @param array<int,string>                  $ruleIds  The endpoint's rules.
	 * @param array<string,array<string,mixed>> $rules    Every rule, by uuid and slug.
	 *
	 * @return array<int,array<string,array<int,string>>> The requirements, any one of which suffices.
	 */
	private function securityFor(array &$document, array $ruleIds, array $rules): array {
		$security = [];
		foreach ($ruleIds as $ruleId) {
			$rule = ($rules[$ruleId] ?? null);
			if ($rule === null || ($rule['type'] ?? '') !== 'authentication') {
				continue;
			}

			$authentication = (array) ($rule['configuration']['authentication'] ?? []);
			$type = (string) ($authentication['type'] ?? '');
			$published = (self::AUTH_SCHEMES[$type] ?? null);
			if ($type === 'apikey') {
				$header = (string) ($authentication['header'] ?? 'Authorization');
				$published = [
					'name' => 'apiKey.'.preg_replace('/[^A-Za-z0-9._-]/', '-', $header),
					'scheme' => ['type' => 'apiKey', 'in' => 'header', 'name' => $header],
				];
			}

			if ($published === null) {
				continue;
			}

			$document['components']['securitySchemes'][$published['name']] = $published['scheme'];
			$security[] = [$published['name'] => []];
		}//end foreach

		return array_values(array_unique($security, SORT_REGULAR));
	}//end securityFor()

	/**
	 * The operation with a parameter for every gateway path placeholder, and none for a placeholder it lacks.
	 *
	 * @param array<string,mixed> $operation The operation.
	 * @param array<int,string>   $names     The gateway path's parameter names.
	 *
	 * @return array<string,mixed> The operation.
	 */
	private function withPathParameters(array $operation, array $names): array {
		$parameters = [];
		$declared = [];
		foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
			if (is_array($parameter) === false) {
				continue;
			}

			if (($parameter['in'] ?? '') === 'path') {
				if (in_array(($parameter['name'] ?? ''), $names, true) === false || isset($declared[$parameter['name']]) === true) {
					continue;
				}

				$declared[$parameter['name']] = true;
			}

			$parameters[] = $parameter;
		}

		foreach ($names as $name) {
			if (isset($declared[$name]) === false) {
				$parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
			}
		}

		unset($operation['parameters']);
		if ($parameters !== []) {
			$operation['parameters'] = $parameters;
		}

		return $operation;
	}//end withPathParameters()

	/**
	 * The vendor document a source keeps, in the OpenAPI 3.x shape.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return array<string,mixed>|null The document, or null when there is none.
	 */
	private function vendorDocument(string $sourceId): ?array {
		$source = $this->source(sourceId: $sourceId);
		$stored = ($source['openApiDocument'] ?? null);
		if (is_string($stored) === true && $stored !== '') {
			$stored = json_decode($stored, true);
		}

		if (is_array($stored) === false || $stored === []) {
			return null;
		}

		try {
			return $this->normaliser->normalise(document: $stored);
		} catch (Throwable $exception) {
			$this->logger->info('integriq: the OpenAPI document of source '.$sourceId.' cannot be read: '.$exception->getMessage());
			return null;
		}
	}//end vendorDocument()

	/**
	 * A source's data, read once per document.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return array<string,mixed>|null The data, or null when it cannot be read.
	 */
	private function source(string $sourceId): ?array {
		if ($sourceId === '') {
			return null;
		}

		if (array_key_exists($sourceId, $this->sources) === false) {
			$this->sources[$sourceId] = null;
			try {
				$source = $this->objects->find(id: $sourceId, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
				if ($source instanceof ObjectEntity) {
					$this->sources[$sourceId] = $source->getObject();
				}
			} catch (Throwable $exception) {
				$this->logger->info('integriq: source '.$sourceId.' of a published endpoint cannot be read: '.$exception->getMessage());
			}
		}

		return $this->sources[$sourceId];
	}//end source()

	/**
	 * Copy a vendor document's components into the published document, under the source's prefix.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $vendor   The vendor document.
	 * @param string              $prefix   The source's component prefix.
	 *
	 * @return void
	 */
	private function copyComponents(array &$document, array $vendor, string $prefix): void {
		foreach (self::COMPONENT_KINDS as $kind) {
			foreach ((array) ($vendor['components'][$kind] ?? []) as $name => $component) {
				$document['components'][$kind][$prefix.$name] = self::prefixRefs(value: $component, prefix: $prefix);
			}
		}
	}//end copyComponents()

	/**
	 * Every row of an integriq schema, read in system context.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,ObjectEntity> The rows.
	 */
	private function rows(string $schema): array {
		try {
			$matches = $this->objects->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => $schema]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: failed to read '.$schema.' rows for the OpenAPI document: '.$exception->getMessage());
			return [];
		}

		return array_values(array_filter((array) ($matches['results'] ?? $matches), static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end rows()

	/**
	 * Every rule's data, by uuid and by slug.
	 *
	 * @return array<string,array<string,mixed>> The rules.
	 */
	private function rulesById(): array {
		$rules = [];
		foreach ($this->rows(schema: 'rule') as $rule) {
			$data = $rule->getObject();
			$rules[(string) $rule->getUuid()] = $data;
			if (is_string($data['slug'] ?? null) === true && $data['slug'] !== '') {
				$rules[$data['slug']] = $data;
			}
		}

		return $rules;
	}//end rulesById()

	/**
	 * The document with every source location removed from every string in it.
	 *
	 * A belt to the braces of never copying servers: a vendor description or
	 * example that names the upstream address must not publish it either.
	 *
	 * @param mixed $value Any part of the document.
	 *
	 * @return mixed The part without source locations.
	 */
	private function withoutLocations(mixed $value): mixed {
		$locations = [];
		foreach ($this->sources as $source) {
			$location = rtrim((string) ($source['location'] ?? ''), '/');
			if ($location !== '') {
				$locations[] = $location;
				$locations[] = (string) preg_replace('#^https?://#i', '', $location);
			}
		}

		$locations = array_values(array_filter(array_unique($locations), static fn (string $location): bool => $location !== ''));
		if ($locations === []) {
			return $value;
		}

		return self::scrub(value: $value, locations: $locations);
	}//end withoutLocations()

	/**
	 * Remove the given strings from every string in a value.
	 *
	 * @param mixed             $value     The value.
	 * @param array<int,string> $locations The strings to remove.
	 *
	 * @return mixed The value.
	 */
	private static function scrub(mixed $value, array $locations): mixed {
		if (is_string($value) === true) {
			return str_ireplace($locations, '', $value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		$scrubbed = [];
		foreach ($value as $key => $item) {
			$scrubbed[$key] = self::scrub(value: $item, locations: $locations);
		}

		return $scrubbed;
	}//end scrub()

	/**
	 * Point a vendor component reference at its prefixed copy.
	 *
	 * @param mixed  $value  Any part of a vendor document.
	 * @param string $prefix The source's component prefix.
	 *
	 * @return mixed The part with its references prefixed.
	 */
	private static function prefixRefs(mixed $value, string $prefix): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if ($key === '$ref' && is_string($item) === true) {
				$value[$key] = (string) preg_replace('#^\#/components/([A-Za-z]+)/#', '#/components/$1/'.$prefix, $item);
				continue;
			}

			$value[$key] = self::prefixRefs(value: $item, prefix: $prefix);
		}

		return $value;
	}//end prefixRefs()

	/**
	 * The component prefix of a source's copied components.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return string The prefix.
	 */
	private static function componentPrefix(string $sourceId): string {
		return 'source-'.substr((string) preg_replace('/[^A-Za-z0-9]/', '', $sourceId), 0, 8).'.';
	}//end componentPrefix()

	/**
	 * A path's shape: no outer slashes, every placeholder the same.
	 *
	 * @param string $path The path.
	 *
	 * @return string The shape.
	 */
	private static function shape(string $path): string {
		return strtolower((string) preg_replace('#\{\{?\s*[^}]+\s*\}?\}#', '{}', trim($path, '/')));
	}//end shape()

	/**
	 * The non-empty strings of a list.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int,string> The strings.
	 */
	private static function stringList(mixed $value): array {
		return array_values(array_filter((array) $value, static fn (mixed $item): bool => is_string($item) === true && $item !== ''));
	}//end stringList()
}//end class
