<?php

/**
 * Integriq OpenApiImportService.
 *
 * Previews and imports a vendor's OpenAPI or Swagger document as one source
 * and one endpoint per chosen operation.
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
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The preview writes nothing; the import writes through the same source and
 * endpoint handlers the configuration import uses, so an imported source looks
 * exactly like a hand-made one (decisions D1 and D2). No secret is read from the
 * document: a security scheme becomes a credential placeholder naming what the
 * administrator must fill through the credential broker.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class OpenApiImportService {

	/**
	 * How many endpoints one import batch writes.
	 *
	 * @var integer
	 */
	public const BATCH_SIZE = 50;

	/**
	 * The largest document a URL import reads, in bytes.
	 *
	 * @var integer
	 */
	public const MAX_DOCUMENT_BYTES = 10485760;

	/**
	 * The keys a stored security scheme keeps; anything else (vendor extensions, examples) is dropped.
	 *
	 * @var array<int,string>
	 */
	private const SCHEME_KEYS = ['type', 'description', 'name', 'in', 'scheme', 'bearerFormat', 'flows', 'openIdConnectUrl'];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService            $objects       OpenRegister's object service, to read existing endpoints.
	 * @param SourceHandler              $sources       Writes the source.
	 * @param EndpointHandler            $endpoints     Writes the endpoints.
	 * @param OpenApiDocumentNormaliser  $normaliser    Reads the document.
	 * @param EgressGuardInterface       $egressGuard   Refuses a URL on an internal address (ADR-067).
	 * @param IClientService             $clientService The HTTP client for a URL import.
	 * @param LoggerInterface            $logger        The logger.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
		private readonly SourceHandler $sources,
		private readonly EndpointHandler $endpoints,
		private readonly OpenApiDocumentNormaliser $normaliser,
		private readonly EgressGuardInterface $egressGuard,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch a document from a URL, through the egress guard.
	 *
	 * Redirects are not followed, so a public URL cannot bounce the fetch onto an internal address.
	 *
	 * @param string $url The document URL.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws EgressRefusedException When the URL is on an internal address or not https.
	 * @throws InvalidArgumentException When the fetch fails or the answer is not a document.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function fetch(string $url): array {
		$this->egressGuard->assertAllowed(url: $url);

		try {
			$response = $this->clientService->newClient()->get(
				$url,
				[
					'allow_redirects' => false,
					'timeout' => 30,
					'http_errors' => false,
					'headers' => ['Accept' => 'application/json, application/yaml, text/yaml'],
				]
			);
		} catch (Throwable $exception) {
			throw new InvalidArgumentException('The document could not be fetched: '.$exception->getMessage());
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new InvalidArgumentException('The document URL answered HTTP '.$status.'.');
		}

		$body = (string) $response->getBody();
		if (strlen($body) > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidArgumentException('The document is larger than '.self::MAX_DOCUMENT_BYTES.' bytes.');
		}

		return $this->normaliser->decode(text: $body);
	}//end fetch()

	/**
	 * What an import of the document would create. Writes nothing.
	 *
	 * @param array<string,mixed> $document    The decoded vendor document.
	 * @param string              $prefix      The gateway path prefix, e.g. `vendor`.
	 * @param int|null            $serverIndex The chosen server; the first when null.
	 * @param int                 $offset      The first operation listed, for paging a large document.
	 * @param int|null            $limit       How many operations are listed; all when null.
	 *
	 * @return array<string,mixed> The source, the operations with their collision flag, the credential placeholders.
	 *
	 * @throws InvalidArgumentException When the document or the prefix cannot be imported.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function preview(array $document, string $prefix, ?int $serverIndex = null, int $offset = 0, ?int $limit = null): array {
		$version = $this->normaliser->versionOf(document: $document);
		$normalised = $this->normaliser->normalise(document: $document);
		$prefix = self::cleanPrefix(prefix: $prefix);

		$servers = $this->serversOf(document: $normalised);
		$serverIndex = ($serverIndex ?? 0);
		if ($servers !== [] && isset($servers[$serverIndex]) === false) {
			throw new InvalidArgumentException('The document has no server '.$serverIndex.'.');
		}

		$existing = $this->existingRoutes();
		$operations = [];
		foreach ($this->operationsOf(document: $normalised, prefix: $prefix) as $operation) {
			$shape = $operation['method'].' '.self::shape(segments: $operation['endpointArray']);
			$operation['collides'] = isset($existing[$shape]);
			$operation['collidesWith'] = ($existing[$shape] ?? null);
			$operations[] = $operation;
		}

		$location = '';
		if ($servers !== []) {
			$location = $servers[$serverIndex]['url'];
		}

		return [
			'openapi' => $version,
			'title' => (string) ($normalised['info']['title'] ?? ''),
			'servers' => $servers,
			'serverIndex' => $serverIndex,
			'source' => [
				'name' => (string) ($normalised['info']['title'] ?? $prefix),
				'location' => $location,
				'type' => 'api',
			],
			'total' => count($operations),
			'operations' => array_slice($operations, max(0, $offset), $limit),
			'credentials' => $this->credentialPlaceholders(document: $normalised),
		];
	}//end preview()

	/**
	 * Create one source and one endpoint per chosen operation.
	 *
	 * A chosen operation whose path collides with an existing endpoint is
	 * skipped, never written over. Endpoints are written in batches of
	 * {@see self::BATCH_SIZE}; each operation gets its own outcome, so one
	 * failure does not hide the others.
	 *
	 * @param array<string,mixed> $document     The decoded vendor document.
	 * @param string              $prefix       The gateway path prefix.
	 * @param array<int,string>   $operationKeys The chosen operations, by the key the preview gave them.
	 * @param int|null            $serverIndex  The chosen server; the first when null.
	 * @param string|null         $name         The source name; the document title when null or empty.
	 *
	 * @return array<string,mixed> The source, the per-operation outcomes and their counts.
	 *
	 * @throws InvalidArgumentException When nothing can be imported.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function import(array $document, string $prefix, array $operationKeys, ?int $serverIndex = null, ?string $name = null): array {
		$preview = $this->preview(document: $document, prefix: $prefix, serverIndex: $serverIndex);
		$location = (string) $preview['source']['location'];
		if (preg_match('#^https?://[^/]#i', $location) !== 1) {
			throw new InvalidArgumentException('The chosen server has no absolute http(s) URL to use as the source location.');
		}

		$chosen = array_values(array_unique(array_map('strval', $operationKeys)));
		if ($chosen === []) {
			throw new InvalidArgumentException('Choose at least one operation to import.');
		}

		$byKey = [];
		foreach ($preview['operations'] as $operation) {
			$byKey[$operation['key']] = $operation;
		}

		$outcomes = [];
		$selected = [];
		foreach ($chosen as $key) {
			if (isset($byKey[$key]) === false) {
				$outcomes[] = ['key' => $key, 'outcome' => 'unknown', 'message' => 'The document has no such operation.'];
				continue;
			}

			if ($byKey[$key]['collides'] === true) {
				$outcomes[] = [
					'key' => $key,
					'outcome' => 'skipped',
					'message' => 'An endpoint already serves '.$byKey[$key]['method'].' /'.$byKey[$key]['gatewayPath'].'.',
				];
				continue;
			}

			$selected[] = $byKey[$key];
		}

		if ($selected === []) {
			return ['source' => null, 'endpoints' => $outcomes] + self::counts(outcomes: $outcomes);
		}

		$source = $this->createSource(document: $document, preview: $preview, name: $name);

		foreach (array_chunk($selected, self::BATCH_SIZE) as $batch => $operations) {
			foreach ($operations as $operation) {
				$outcomes[] = $this->createEndpoint(operation: $operation, sourceUuid: $source['uuid'], batch: ($batch + 1));
			}
		}

		return ['source' => $source, 'endpoints' => $outcomes] + self::counts(outcomes: $outcomes);
	}//end import()

	/**
	 * Write the source: location from the chosen server, the placeholders, and the document kept.
	 *
	 * @param array<string,mixed> $document The vendor document, as given.
	 * @param array<string,mixed> $preview  The preview of the import.
	 * @param string|null         $name     The source name, or null for the document title.
	 *
	 * @return array{uuid:string,name:string,location:string,credentials:array<int,array<string,mixed>>} The created source.
	 *
	 * @throws InvalidArgumentException When the source is not written.
	 */
	private function createSource(array $document, array $preview, ?string $name): array {
		$name = trim((string) $name);
		if ($name === '') {
			$name = $preview['source']['name'];
		}

		if ($name === '') {
			$name = 'Imported API';
		}

		$configuration = [];
		if ($preview['credentials'] !== []) {
			// The broker reference is left empty: the source refuses to call
			// until the administrator names a credential (an empty
			// credentialRef is a configuration error at call time), so it
			// visibly asks for one instead of calling unauthenticated.
			$configuration['authentication'] = ['credentialRef' => ['credentialName' => '']];
			$configuration['credentialPlaceholders'] = $preview['credentials'];
		}

		$data = [
			'name' => $name,
			'description' => (string) ($document['info']['description'] ?? ''),
			'location' => $preview['source']['location'],
			'type' => 'api',
			'isEnabled' => true,
			'configuration' => $configuration,
			'openApiDocument' => self::withoutSecretShapes(document: $document),
		];

		try {
			$entity = $this->sources->import(data: $data, mappings: []);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: OpenAPI import could not create its source: '.$exception->getMessage());
			throw new InvalidArgumentException('The source could not be created: '.$exception->getMessage());
		}

		$uuid = '';
		if ($entity instanceof ObjectEntity) {
			$uuid = (string) $entity->getUuid();
		}

		return ['uuid' => $uuid, 'name' => $name, 'location' => $data['location'], 'credentials' => $preview['credentials']];
	}//end createSource()

	/**
	 * Write one endpoint and report its outcome.
	 *
	 * @param array<string,mixed> $operation  The previewed operation.
	 * @param string              $sourceUuid The new source.
	 * @param int                 $batch      The batch number, from 1.
	 *
	 * @return array<string,mixed> The outcome: created with the endpoint uuid, or failed with the reason.
	 */
	private function createEndpoint(array $operation, string $sourceUuid, int $batch): array {
		$data = [
			'name' => $operation['name'],
			'description' => $operation['summary'],
			// `endpoint` is the gateway route, prefix included: the routing
			// listener derives endpointArray and endpointRegex from it on
			// every save. The vendor's own path goes in `upstreamPath`, which
			// the proxy renders instead of `endpoint` when it is set.
			'endpoint' => $operation['gatewayPath'],
			'upstreamPath' => $operation['endpoint'],
			'endpointArray' => $operation['endpointArray'],
			'endpointRegex' => self::regexOf(segments: $operation['endpointArray']),
			'method' => $operation['method'],
			'targetType' => 'api',
			'targetId' => $sourceUuid,
		];

		try {
			$entity = $this->endpoints->import(data: $data, mappings: []);
		} catch (Throwable $exception) {
			$this->logger->warning('integriq: OpenAPI import could not create endpoint '.$operation['key'].': '.$exception->getMessage());

			return ['key' => $operation['key'], 'outcome' => 'failed', 'batch' => $batch, 'message' => $exception->getMessage()];
		}

		$uuid = null;
		if ($entity instanceof ObjectEntity) {
			$uuid = $entity->getUuid();
		}

		return ['key' => $operation['key'], 'outcome' => 'created', 'batch' => $batch, 'uuid' => $uuid];
	}//end createEndpoint()

	/**
	 * The document's servers, with variables filled from their defaults.
	 *
	 * @param array<string,mixed> $document The OpenAPI 3.x document.
	 *
	 * @return array<int,array{index:int,url:string,description:string}> The servers.
	 */
	private function serversOf(array $document): array {
		$servers = [];
		foreach (array_values((array) ($document['servers'] ?? [])) as $index => $server) {
			if (is_array($server) === false || is_string($server['url'] ?? null) === false) {
				continue;
			}

			$url = $server['url'];
			foreach ((array) ($server['variables'] ?? []) as $variable => $definition) {
				$url = str_replace('{'.$variable.'}', (string) (((array) $definition)['default'] ?? ''), $url);
			}

			$servers[] = ['index' => $index, 'url' => rtrim($url, '/'), 'description' => (string) ($server['description'] ?? '')];
		}

		return $servers;
	}//end serversOf()

	/**
	 * Every operation of the document, with the endpoint it would become.
	 *
	 * @param array<string,mixed> $document The OpenAPI 3.x document.
	 * @param string              $prefix   The cleaned gateway prefix.
	 *
	 * @return array<int,array<string,mixed>> The operations.
	 */
	private function operationsOf(array $document, string $prefix): array {
		$operations = [];
		foreach ((array) ($document['paths'] ?? []) as $path => $item) {
			if (is_array($item) === false) {
				continue;
			}

			foreach (OpenApiDocumentNormaliser::METHODS as $method) {
				if (is_array($item[$method] ?? null) === false) {
					continue;
				}

				$operations[] = $this->operationOf(path: (string) $path, method: $method, operation: $item[$method], prefix: $prefix);
			}
		}

		return $operations;
	}//end operationsOf()

	/**
	 * One operation and the endpoint it would become.
	 *
	 * @param string              $path      The operation's path, e.g. `/pets/{petId}`.
	 * @param string              $method    The lower-case method.
	 * @param array<string,mixed> $operation The operation.
	 * @param string              $prefix    The cleaned gateway prefix.
	 *
	 * @return array<string,mixed> The operation.
	 */
	private function operationOf(string $path, string $method, array $operation, string $prefix): array {
		$method = strtoupper($method);
		$operationId = trim((string) ($operation['operationId'] ?? ''));
		$key = $operationId;
		if ($key === '') {
			$key = $method.' '.$path;
		}

		$upstream = self::twigPlaceholders(path: ltrim($path, '/'));
		$gatewayPath = ltrim($prefix.'/'.$upstream, '/');

		return [
			'key' => $key,
			'operationId' => $operationId,
			'name' => $key,
			'summary' => (string) ($operation['summary'] ?? ($operation['description'] ?? '')),
			'method' => $method,
			'path' => $path,
			'gatewayPath' => $gatewayPath,
			'endpoint' => $upstream,
			'endpointArray' => explode('/', $gatewayPath),
			'deprecated' => (($operation['deprecated'] ?? false) === true),
		];
	}//end operationOf()

	/**
	 * Every existing endpoint's route, by `METHOD shape`, naming the endpoint.
	 *
	 * @return array<string,string> The routes.
	 */
	private function existingRoutes(): array {
		try {
			$matches = $this->objects->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'endpoint']],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			throw new InvalidArgumentException('The existing endpoints could not be read to check for collisions: '.$exception->getMessage());
		}

		$rows = ($matches['results'] ?? $matches);
		$routes = [];
		foreach ((array) $rows as $row) {
			$data = $row;
			if ($row instanceof ObjectEntity) {
				$data = $row->getObject();
			}

			if (is_array($data) === false) {
				continue;
			}

			$segments = array_values(array_filter((array) ($data['endpointArray'] ?? []), static fn (mixed $part): bool => is_scalar($part) === true));
			if ($segments === []) {
				$segments = explode('/', ltrim((string) ($data['endpoint'] ?? ''), '/'));
			}

			$shape = strtoupper((string) ($data['method'] ?? '')).' '.self::shape(segments: $segments);
			$routes[$shape] = (string) ($data['name'] ?? ($data['uuid'] ?? ''));
		}//end foreach

		return $routes;
	}//end existingRoutes()

	/**
	 * One credential placeholder per security scheme: what the administrator must fill. No value is read.
	 *
	 * @param array<string,mixed> $document The OpenAPI 3.x document.
	 *
	 * @return array<int,array<string,mixed>> The placeholders.
	 */
	private function credentialPlaceholders(array $document): array {
		$placeholders = [];
		foreach ((array) ($document['components']['securitySchemes'] ?? []) as $schemeName => $scheme) {
			if (is_array($scheme) === false) {
				continue;
			}

			$type = (string) ($scheme['type'] ?? '');
			$placeholder = ['scheme' => (string) $schemeName, 'type' => $type, 'credentialRef' => null, 'supported' => true];
			switch ($type) {
				case 'apiKey':
					$placeholder['in'] = (string) ($scheme['in'] ?? 'header');
					$placeholder['name'] = (string) ($scheme['name'] ?? '');
					$placeholder['required'] = ['apiKey'];
					break;
				case 'http':
					$placeholder['httpScheme'] = strtolower((string) ($scheme['scheme'] ?? 'bearer'));
					$placeholder['required'] = ['token'];
					if ($placeholder['httpScheme'] === 'basic') {
						$placeholder['required'] = ['username', 'password'];
					}
					break;
				case 'oauth2':
					$flow = (array) ($scheme['flows']['clientCredentials'] ?? []);
					$placeholder['flow'] = 'clientCredentials';
					$placeholder['tokenUrl'] = (string) ($flow['tokenUrl'] ?? '');
					$placeholder['scopes'] = array_keys((array) ($flow['scopes'] ?? []));
					$placeholder['required'] = ['clientId', 'clientSecret'];
					$placeholder['supported'] = ($flow !== []);
					break;
				default:
					$placeholder['required'] = [];
					$placeholder['supported'] = false;
			}//end switch

			$placeholders[] = $placeholder;
		}//end foreach

		return $placeholders;
	}//end credentialPlaceholders()

	/**
	 * The document as kept on the source: security schemes stripped to their shape.
	 *
	 * A scheme only names where a credential goes; a vendor extension or
	 * example on it could carry a value, so only the known keys are kept.
	 *
	 * @param array<string,mixed> $document The vendor document.
	 *
	 * @return array<string,mixed> The document to keep.
	 */
	private static function withoutSecretShapes(array $document): array {
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

	/**
	 * A cleaned gateway prefix: no outer slashes, safe path segments only.
	 *
	 * @param string $prefix The prefix as given.
	 *
	 * @return string The cleaned prefix.
	 *
	 * @throws InvalidArgumentException When the prefix is empty or holds anything but letters, digits, `-`, `_`, `.` and `/`.
	 */
	private static function cleanPrefix(string $prefix): string {
		$prefix = trim($prefix, " \t/");
		if (preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $prefix) !== 1) {
			throw new InvalidArgumentException('The prefix must be one or more path segments of letters, digits, "-", "_" or ".".');
		}

		return $prefix;
	}//end cleanPrefix()

	/**
	 * A path with `{name}` placeholders written the way endpoints spell them, `{{name}}`.
	 *
	 * @param string $path The OpenAPI path.
	 *
	 * @return string The endpoint path.
	 */
	private static function twigPlaceholders(string $path): string {
		return (string) preg_replace('#(?<!\{)\{([A-Za-z0-9_.-]+)\}(?!\})#', '{{$1}}', $path);
	}//end twigPlaceholders()

	/**
	 * A route's shape: lower-case segments, every placeholder the same.
	 *
	 * @param array<int,mixed> $segments The route's segments.
	 *
	 * @return string The shape.
	 */
	private static function shape(array $segments): string {
		$parts = [];
		foreach ($segments as $segment) {
			$segment = (string) $segment;
			if ($segment === '') {
				continue;
			}

			if (preg_match('#^\{\{?\s*[^}]+\s*\}?\}$#', $segment) === 1) {
				$segment = '{}';
			}

			$parts[] = strtolower($segment);
		}

		return implode('/', $parts);
	}//end shape()

	/**
	 * The dispatch regex of a gateway route, as the endpoint router builds it.
	 *
	 * @param array<int,string> $segments The route's segments.
	 *
	 * @return string The regex.
	 */
	private static function regexOf(array $segments): string {
		$parts = [];
		foreach ($segments as $segment) {
			if (preg_match('#^\{\{[^}]+\}\}$#', $segment) === 1) {
				$parts[] = '([^/]+)';
				continue;
			}

			$parts[] = preg_quote($segment, '#');
		}

		return '#^'.implode('/', $parts).'$#';
	}//end regexOf()

	/**
	 * The outcome counts.
	 *
	 * @param array<int,array<string,mixed>> $outcomes The per-operation outcomes.
	 *
	 * @return array{created:int,skipped:int,failed:int,unknown:int} The counts.
	 */
	private static function counts(array $outcomes): array {
		$counts = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'unknown' => 0];
		foreach ($outcomes as $outcome) {
			$counts[$outcome['outcome']]++;
		}

		return $counts;
	}//end counts()
}//end class
