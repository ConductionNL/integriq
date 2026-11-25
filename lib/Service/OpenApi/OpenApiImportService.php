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
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
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
	 * Reads the version and the OpenAPI 3.x shape of a document.
	 *
	 * @var OpenApiDocumentNormaliser
	 */
	private readonly OpenApiDocumentNormaliser $normaliser;

	/**
	 * Names the credentials a document's security schemes need, without secrets.
	 *
	 * @var OpenApiCredentialShapes
	 */
	private readonly OpenApiCredentialShapes $credentials;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objects   Reads the endpoints already served.
	 * @param SourceHandler   $sources   Creates the imported source.
	 * @param EndpointHandler $endpoints Creates the imported endpoints.
	 * @param LoggerInterface $logger    Logs a source or endpoint that could not be created.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
		private readonly SourceHandler $sources,
		private readonly EndpointHandler $endpoints,
		private readonly LoggerInterface $logger,
	) {
		$this->normaliser  = new OpenApiDocumentNormaliser();
		$this->credentials = new OpenApiCredentialShapes();
	}//end __construct()

	/**
	 * What an import of the document would create. Writes nothing.
	 *
	 * @param array<string,mixed> $document    The decoded vendor document.
	 * @param string              $prefix      The gateway path prefix, e.g. `vendor`.
	 * @param int|null            $serverIndex The chosen server; the first when null.
	 * @param OpenApiPreviewPage  $page        Which operations are listed, for paging a large document.
	 *
	 * @return array<string,mixed> The source, the operations with their collision flag, the credential placeholders.
	 *
	 * @throws InvalidArgumentException When the document or the prefix cannot be imported.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function preview(array $document, string $prefix, ?int $serverIndex = null, OpenApiPreviewPage $page = new OpenApiPreviewPage()): array {
		$version = $this->normaliser->versionOf(document: $document);
		$normalised = $this->normaliser->normalise(document: $document);
		$prefix = self::cleanPrefix(prefix: $prefix);

		$servers = $this->serversOf(document: $normalised);
		$serverIndex = ($serverIndex ?? 0);
		if ($servers !== [] && isset($servers[$serverIndex]) === false) {
			throw new InvalidArgumentException('The document has no server '.$serverIndex.'.');
		}

		$operations = $this->flaggedOperations(document: $normalised, prefix: $prefix);
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
			'operations' => array_slice($operations, max(0, $page->offset), $page->limit),
			'credentials' => $this->credentials->credentialPlaceholders(document: $normalised),
		];
	}//end preview()

	/**
	 * The document's operations, each flagged when an existing endpoint already serves its route.
	 *
	 * @param array<string,mixed> $document The OpenAPI 3.x document.
	 * @param string              $prefix   The cleaned gateway path prefix.
	 *
	 * @return array<int,array<string,mixed>> The operations with `collides` and `collidesWith`.
	 */
	private function flaggedOperations(array $document, string $prefix): array {
		$existing = $this->existingRoutes();
		$operations = [];
		foreach ($this->operationsOf(document: $document, prefix: $prefix) as $operation) {
			$shape = $operation['method'].' '.self::shape(segments: $operation['endpointArray']);
			$operation['collides'] = isset($existing[$shape]);
			$operation['collidesWith'] = ($existing[$shape] ?? null);
			$operations[] = $operation;
		}

		return $operations;
	}//end flaggedOperations()

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
	 * @param OpenApiSourceChoice $source       The chosen server and source name.
	 *
	 * @return array<string,mixed> The source, the per-operation outcomes and their counts.
	 *
	 * @throws InvalidArgumentException When nothing can be imported.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function import(array $document, string $prefix, array $operationKeys, OpenApiSourceChoice $source = new OpenApiSourceChoice()): array {
		$preview = $this->preview(document: $document, prefix: $prefix, serverIndex: $source->serverIndex);
		$chosen = self::chosenKeys(location: (string) $preview['source']['location'], operationKeys: $operationKeys);

		[$selected, $outcomes] = self::selectOperations(operations: $preview['operations'], chosen: $chosen);
		if ($selected === []) {
			return ['source' => null, 'endpoints' => $outcomes] + self::counts(outcomes: $outcomes);
		}

		$created = $this->createSource(document: $document, preview: $preview, name: $source->name);
		foreach ($this->createEndpoints(operations: $selected, sourceUuid: $created['uuid']) as $outcome) {
			$outcomes[] = $outcome;
		}

		return ['source' => $created, 'endpoints' => $outcomes] + self::counts(outcomes: $outcomes);
	}//end import()

	/**
	 * The distinct chosen operation keys, refused without an absolute source location or without any key.
	 *
	 * @param string            $location      The source location of the chosen server.
	 * @param array<int,string> $operationKeys The chosen operations.
	 *
	 * @return array<int,string> The keys.
	 *
	 * @throws InvalidArgumentException When nothing can be imported.
	 */
	private static function chosenKeys(string $location, array $operationKeys): array {
		if (preg_match('#^https?://[^/]#i', $location) !== 1) {
			throw new InvalidArgumentException('The chosen server has no absolute http(s) URL to use as the source location.');
		}

		$chosen = array_values(array_unique(array_map('strval', $operationKeys)));
		if ($chosen === []) {
			throw new InvalidArgumentException('Choose at least one operation to import.');
		}

		return $chosen;
	}//end chosenKeys()

	/**
	 * Split the chosen operations into those to import and the outcomes of those that are not.
	 *
	 * @param array<int,array<string,mixed>> $operations The previewed operations.
	 * @param array<int,string>              $chosen     The chosen operation keys.
	 *
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>} The operations to import, and the unknown or skipped outcomes.
	 */
	private static function selectOperations(array $operations, array $chosen): array {
		$byKey = [];
		foreach ($operations as $operation) {
			$byKey[$operation['key']] = $operation;
		}

		$outcomes = [];
		$selected = [];
		foreach ($chosen as $key) {
			$refusal = self::refusalOf(key: $key, operation: ($byKey[$key] ?? null));
			if ($refusal !== null) {
				$outcomes[] = $refusal;
				continue;
			}

			$selected[] = $byKey[$key];
		}

		return [$selected, $outcomes];
	}//end selectOperations()

	/**
	 * The outcome of a chosen operation that is not imported: unknown, or skipped because its route is taken.
	 *
	 * @param string                   $key       The chosen operation key.
	 * @param array<string,mixed>|null $operation The previewed operation, null when the document has none by that key.
	 *
	 * @return array<string,string>|null The outcome, null when the operation is imported.
	 */
	private static function refusalOf(string $key, ?array $operation): ?array {
		if ($operation === null) {
			return ['key' => $key, 'outcome' => 'unknown', 'message' => 'The document has no such operation.'];
		}

		if ($operation['collides'] === true) {
			return [
				'key' => $key,
				'outcome' => 'skipped',
				'message' => 'An endpoint already serves '.$operation['method'].' /'.$operation['gatewayPath'].'.',
			];
		}

		return null;
	}//end refusalOf()

	/**
	 * Create the endpoints of the selected operations, in batches.
	 *
	 * @param array<int,array<string,mixed>> $operations The operations to import.
	 * @param string                         $sourceUuid The source they proxy to.
	 *
	 * @return array<int,array<string,mixed>> The per-operation outcomes.
	 */
	private function createEndpoints(array $operations, string $sourceUuid): array {
		$outcomes = [];
		foreach (array_chunk($operations, self::BATCH_SIZE) as $batch => $chunk) {
			foreach ($chunk as $operation) {
				$outcomes[] = $this->createEndpoint(operation: $operation, sourceUuid: $sourceUuid, batch: ($batch + 1));
			}
		}

		return $outcomes;
	}//end createEndpoints()

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
		$name = self::sourceName(name: $name, preview: $preview);
		$configuration = self::sourceConfiguration(credentials: $preview['credentials']);

		$data = [
			'name' => $name,
			'description' => (string) ($document['info']['description'] ?? ''),
			'location' => $preview['source']['location'],
			'type' => 'api',
			// A source whose document declares security schemes stays disabled until the
			// administrator names a credential: the call engine refuses a disabled source,
			// so it asks for one instead of calling unauthenticated.
			'isEnabled' => $preview['credentials'] === [],
			'configuration' => $configuration,
			'openApiDocument' => $this->credentials->withoutSecretShapes(document: $document),
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
	 * The source name: the one given, else the document title, else a generic name.
	 *
	 * @param string|null         $name    The name given.
	 * @param array<string,mixed> $preview The preview of the import.
	 *
	 * @return string The name.
	 */
	private static function sourceName(?string $name, array $preview): string {
		$name = trim((string) $name);
		if ($name === '') {
			$name = $preview['source']['name'];
		}

		if ($name === '') {
			$name = 'Imported API';
		}

		return $name;
	}//end sourceName()

	/**
	 * The source configuration for the document's credential placeholders.
	 *
	 * @param array<int,array<string,mixed>> $credentials The credential placeholders.
	 *
	 * @return array<string,mixed> The configuration, empty without placeholders.
	 */
	private static function sourceConfiguration(array $credentials): array {
		if ($credentials === []) {
			return [];
		}

		// No broker reference is written: the source schema refuses an empty
		// `credentialRef` (it needs one non-empty key), so the import failed on
		// every document with a security scheme. The placeholders say which
		// credential to name; createSource() leaves the source disabled until then.
		return ['credentialPlaceholders' => $credentials];
	}//end sourceConfiguration()

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

			$routes[self::routeShape(data: $data)] = (string) ($data['name'] ?? ($data['uuid'] ?? ''));
		}

		return $routes;
	}//end existingRoutes()

	/**
	 * The route shape of an existing endpoint: its method and its path with every parameter alike.
	 *
	 * @param array<string,mixed> $data The endpoint's data.
	 *
	 * @return string The shape.
	 */
	private static function routeShape(array $data): string {
		$segments = array_values(array_filter((array) ($data['endpointArray'] ?? []), static fn (mixed $part): bool => is_scalar($part) === true));
		if ($segments === []) {
			$segments = explode('/', ltrim((string) ($data['endpoint'] ?? ''), '/'));
		}

		return strtoupper((string) ($data['method'] ?? '')).' '.self::shape(segments: $segments);
	}//end routeShape()

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
