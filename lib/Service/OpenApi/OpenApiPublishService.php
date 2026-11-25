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

use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
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
	 * The operation of an endpoint whose target describes none.
	 */
	private const UNDESCRIBED_OPERATION = [
		'responses' => [
			'200' => [
				'description' => 'The answer of the endpoint.',
				'content' => ['application/json' => ['schema' => ['type' => 'object', 'description' => 'Not described: this endpoint carries no schema.']]],
			],
		],
	];

	/**
	 * The OpenAPI version of the published document.
	 *
	 * @var string
	 */
	public const OPENAPI_VERSION = '3.1.0';

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
	 * Reads a source's stored vendor document in the OpenAPI 3.x shape.
	 *
	 * @var OpenApiDocumentNormaliser
	 */
	private readonly OpenApiDocumentNormaliser $normaliser;

	/**
	 * Copies an imported endpoint's vendor operation and its components.
	 *
	 * @var VendorOperationCopy
	 */
	private readonly VendorOperationCopy $vendorOperations;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService           $objects      OpenRegister's object service.
	 * @param EndpointTargetResolver    $targets      Resolves a register/schema endpoint target.
	 * @param SchemaMapper              $schemaMapper OpenRegister's schema definitions (ADR-022).
	 * @param LoggerInterface           $logger       The logger.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
		private readonly EndpointTargetResolver $targets,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
		$this->normaliser       = new OpenApiDocumentNormaliser();
		$this->vendorOperations = new VendorOperationCopy();
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
		$document = $this->build(
			info: self::productInfo(data: $data),
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
	 * The info object of a product's document: its name, version and, when it has one, its description.
	 *
	 * @param array<string,mixed> $data The product's data.
	 *
	 * @return array<string,string> The info object.
	 */
	private static function productInfo(array $data): array {
		$description = ($data['description'] ?? null);
		if (is_string($description) === false || $description === '') {
			$description = null;
		}

		return array_filter(
			[
				'title' => (string) ($data['name'] ?? ($data['productSlug'] ?? '')),
				'version' => (string) ($data['version'] ?? '1.0.0'),
				'description' => $description,
			],
			static fn (mixed $value): bool => $value !== null
		);
	}//end productInfo()

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
		$segments = self::segmentsOf(data: $data);
		$method = strtolower((string) ($data['method'] ?? ''));
		if ($segments === [] || in_array($method, OpenApiDocumentNormaliser::METHODS, true) === false) {
			return;
		}

		[$path, $pathParameters] = self::templatedPath(segments: $segments, trailingSlash: self::endsWithSlash(data: $data));
		$operation = $this->operationFor(document: $document, data: $data, pathParameters: $pathParameters);
		$operation = self::named(operation: $operation, data: $data, uuid: (string) $endpoint->getUuid());

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
	 * The route segments of an endpoint: its endpointArray, else its endpoint path split on slashes.
	 *
	 * @param array<string,mixed> $data The endpoint's data.
	 *
	 * @return array<int,string|int|float|bool> The non-empty segments.
	 */
	private static function segmentsOf(array $data): array {
		$segments = array_values(
			array_filter((array) ($data['endpointArray'] ?? []), static fn (mixed $part): bool => is_scalar($part) === true && (string) $part !== '')
		);
		if ($segments === []) {
			$segments = array_values(array_filter(explode('/', (string) ($data['endpoint'] ?? '')), static fn (string $part): bool => $part !== ''));
		}

		return $segments;
	}//end segmentsOf()

	/**
	 * Whether the endpoint is routed with a trailing slash.
	 *
	 * The gateway's route regex is built from the endpoint path as written, so `x/besluiten/`
	 * is served only at `x/besluiten/`. The published path must say so, or the description
	 * names a URI the gateway does not answer and `/core/no-trailing-slash` can never fire.
	 *
	 * @param array<string,mixed> $data The endpoint's data.
	 *
	 * @return bool True when the endpoint path, or its endpointArray, ends in an empty segment.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002
	 */
	private static function endsWithSlash(array $data): bool {
		$endpoint = (string) ($data['endpoint'] ?? '');
		if (trim($endpoint, '/') !== '') {
			return str_ends_with($endpoint, '/');
		}

		$array = array_values((array) ($data['endpointArray'] ?? []));

		return count($array) > 1 && $array[(count($array) - 1)] === '';
	}//end endsWithSlash()

	/**
	 * The OpenAPI path of route segments, with `{x}` and `{{x}}` placeholders as path parameters.
	 *
	 * @param array<int,string|int|float|bool> $segments      The route segments.
	 * @param bool                             $trailingSlash Whether the route ends in a slash.
	 *
	 * @return array{0:string,1:array<int,string>} The path and its parameter names.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002
	 */
	private static function templatedPath(array $segments, bool $trailingSlash=false): array {
		$pathParameters = [];
		foreach ($segments as $index => $segment) {
			if (preg_match('#^\{\{?\s*([^}\s]+)\s*\}?\}$#', (string) $segment, $match) === 1) {
				$segments[$index] = '{'.$match[1].'}';
				$pathParameters[] = $match[1];
			}
		}

		$path = '/'.implode('/', $segments);
		if ($trailingSlash === true) {
			$path .= '/';
		}

		return [$path, $pathParameters];
	}//end templatedPath()

	/**
	 * The operation with its operationId (slug, else uuid) and, when it has none, the endpoint name as summary.
	 *
	 * @param array<string,mixed> $operation The operation.
	 * @param array<string,mixed> $data      The endpoint's data.
	 * @param string              $uuid      The endpoint's uuid.
	 *
	 * @return array<string,mixed> The named operation.
	 */
	private static function named(array $operation, array $data, string $uuid): array {
		$operation['operationId'] = (string) ($data['slug'] ?? $uuid);
		if ($operation['operationId'] === '') {
			$operation['operationId'] = $uuid;
		}

		if (($operation['summary'] ?? '') === '' && is_string($data['name'] ?? null) === true) {
			$operation['summary'] = $data['name'];
		}

		return $operation;
	}//end named()

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
		$operation = ($this->targetOperation(document: $document, data: $data) ?? self::UNDESCRIBED_OPERATION);
		if (is_string($data['description'] ?? null) === true && $data['description'] !== '' && isset($operation['description']) === false) {
			$operation['description'] = $data['description'];
		}

		return $this->withPathParameters(operation: $operation, names: $pathParameters);
	}//end operationFor()

	/**
	 * The operation the endpoint's target describes: the vendor document's for `api`, the schema's for `register/schema`.
	 *
	 * @param array<string,mixed> $document The document being built.
	 * @param array<string,mixed> $data     The endpoint's data.
	 *
	 * @return array<string,mixed>|null The operation, or null when the target describes none.
	 */
	private function targetOperation(array &$document, array $data): ?array {
		return match ((string) ($data['targetType'] ?? '')) {
			'api' => $this->importedOperation(document: $document, data: $data),
			'register/schema' => $this->registerOperation(document: $document, data: $data),
			default => null,
		};
	}//end targetOperation()

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
		$answer = self::registerAnswer(data: $data, method: $method, reference: $reference);

		$operation = ['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $answer]]]]];
		if (in_array($method, ['POST', 'PUT', 'PATCH'], true) === true) {
			$operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $reference]]];
		}

		return $operation;
	}//end registerOperation()

	/**
	 * The response schema of a register/schema endpoint: one object, or a page of them for a collection GET.
	 *
	 * @param array<string,mixed>  $data      The endpoint's data.
	 * @param string               $method    The upper-case HTTP method.
	 * @param array<string,string> $reference The reference to the object schema.
	 *
	 * @return array<string,mixed> The response schema.
	 */
	private static function registerAnswer(array $data, string $method, array $reference): array {
		$segments = (array) ($data['endpointArray'] ?? []);
		$last = (string) end($segments);
		if (str_contains($last, '{') === true || $method !== 'GET') {
			return $reference;
		}

		return ['type' => 'object', 'properties' => ['results' => ['type' => 'array', 'items' => $reference], 'total' => ['type' => 'integer']]];
	}//end registerAnswer()

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

			$published = self::publishedScheme(authentication: (array) ($rule['configuration']['authentication'] ?? []));
			if ($published === null) {
				continue;
			}

			$document['components']['securitySchemes'][$published['name']] = $published['scheme'];
			$security[] = [$published['name'] => []];
		}//end foreach

		return array_values(array_unique($security, SORT_REGULAR));
	}//end securityFor()

	/**
	 * The security scheme an authentication rule publishes, by name.
	 *
	 * @param array<string,mixed> $authentication The rule's authentication configuration.
	 *
	 * @return array{name:string,scheme:array<string,mixed>}|null The scheme, or null when its type publishes none.
	 */
	private static function publishedScheme(array $authentication): ?array {
		$type = (string) ($authentication['type'] ?? '');
		if ($type !== 'apikey') {
			return (self::AUTH_SCHEMES[$type] ?? null);
		}

		$header = (string) ($authentication['header'] ?? 'Authorization');

		return [
			'name' => 'apiKey.'.preg_replace('/[^A-Za-z0-9._-]/', '-', $header),
			'scheme' => ['type' => 'apiKey', 'in' => 'header', 'name' => $header],
		];
	}//end publishedScheme()

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
				if (self::isDroppedPathParameter(parameter: $parameter, names: $names, declared: $declared) === true) {
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
	 * Whether a declared path parameter is dropped: not in the published path, or already declared.
	 *
	 * @param array<string,mixed> $parameter The path parameter.
	 * @param array<int,string>   $names     The published path's parameter names.
	 * @param array<string,bool>  $declared  The path parameters kept so far.
	 *
	 * @return bool True when it is dropped.
	 */
	private static function isDroppedPathParameter(array $parameter, array $names, array $declared): bool {
		if (in_array(($parameter['name'] ?? ''), $names, true) === false) {
			return true;
		}

		return isset($declared[$parameter['name']]);
	}//end isDroppedPathParameter()

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
		$vendor   = $this->vendorDocument(sourceId: $sourceId);
		if ($vendor === null) {
			return null;
		}

		return $this->vendorOperations->copy(document: $document, data: $data, vendor: $vendor, sourceId: $sourceId);
	}//end importedOperation()

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
				$source = $this->objects->find(
					id: $sourceId,
					options: new ObjectFindOptions(register: 'integriq', schema: 'source'),
					_rbac: false,
					_multitenancy: false
				);
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
