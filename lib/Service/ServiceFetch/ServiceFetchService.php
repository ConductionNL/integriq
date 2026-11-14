<?php

/**
 * Integriq Service Fetch Service.
 *
 * Runs a service fetch for another app: loads the fetch by slug, checks the
 * calling app and the inputs, calls the fetch's source through the call
 * engine, applies the output mapping and keeps only the declared outputs.
 * Every failure becomes a named refusal on the event; nothing is thrown to
 * the caller, and the caller never receives the raw answer.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ServiceFetch;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Integriq\Event\ServiceFetchRequestedEvent;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The service fetch command's work (design D3, D4).
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchService {

	/**
	 * The register every object involved lives in.
	 */
	private const REGISTER = 'integriq';

	/**
	 * Default and maximum call time, in milliseconds (design D2).
	 */
	private const DEFAULT_TIMEOUT_MS = 5000;

	/**
	 * The longest call time a fetch may declare, in milliseconds.
	 */
	private const MAX_TIMEOUT_MS = 15000;

	/**
	 * The caller an administrator's test run is logged under on the call log.
	 *
	 * @var string
	 */
	public const TEST_CALLER = 'integriq-admin-test';

	/**
	 * Cached outputs per fetch and input values.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Input checks and template rendering.
	 *
	 * @var ServiceFetchInputs
	 */
	private ServiceFetchInputs $inputs;

	/**
	 * Constructor.
	 *
	 * @param ServiceFetchCatalogue   $catalogue      Reads the fetches and their sources.
	 * @param OrObjectService         $objectService  OpenRegister's object service.
	 * @param CallServiceInterface    $callService    The outbound call engine.
	 * @param MappingServiceInterface $mappingService Runs the body and output mappings.
	 * @param ICacheFactory           $cacheFactory   Builds the output cache.
	 * @param LoggerInterface         $logger         Logger.
	 */
	public function __construct(
		private readonly ServiceFetchCatalogue $catalogue,
		private readonly OrObjectService $objectService,
		private readonly CallServiceInterface $callService,
		private readonly MappingServiceInterface $mappingService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('integriq.service-fetch');
		$this->inputs = new ServiceFetchInputs();
	}//end __construct()

	/**
	 * Answer one ServiceFetchRequestedEvent: outputs or a named refusal.
	 *
	 * @param ServiceFetchRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function handle(ServiceFetchRequestedEvent $event): void {
		try {
			$this->run(event: $event);
		} catch (ServiceFetchRefusal $refusal) {
			$event->refuse(refusal: $refusal->getMessage(), detail: $refusal->detail);
		} catch (Throwable $e) {
			$this->logger->warning(
				'integriq: service fetch failed',
				['app' => $event->getApp(), 'fetch' => $event->getFetch(), 'error' => $e->getMessage()]
			);
			$event->refuse(refusal: ServiceFetchRequestedEvent::SOURCE_UNAVAILABLE);
		}
	}//end handle()

	/**
	 * Run one fetch for its administrator with sample inputs ("Testen", design D5).
	 *
	 * Unlike an app's call it skips the allowed-apps check and the output cache,
	 * and runs a disabled fetch too, so a fetch can be tried before it is
	 * switched on. The answer holds the source's raw answer next to the mapped
	 * outputs; it is for the administrator only and never reaches an app.
	 *
	 * @param string               $slug   The fetch.
	 * @param array<string, mixed> $inputs The sample inputs.
	 *
	 * @return array{found: bool, raw: array{status: int, body: mixed}|null,
	 *     outputs: array<string, mixed>|null, refusal: string|null, detail: string|null}
	 *         `found` is false for an unknown slug; `refusal` names the first
	 *         step that refused, with `raw` set once the source answered.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
	 */
	public function test(string $slug, array $inputs): array {
		$result = ['found' => false, 'raw' => null, 'outputs' => null, 'refusal' => null, 'detail' => null];
		$fetch = $this->catalogue->fetchBySlug(slug: $slug);
		if ($fetch === null) {
			return $result;
		}

		$result['found'] = true;
		try {
			try {
				$accepted = $this->inputs->accepted(declared: $this->catalogue->listOf(value: ($fetch['inputs'] ?? [])), given: $inputs);
				$path = $this->inputs->path(path: (string)($fetch['path'] ?? ''), inputs: $accepted);
				$query = $this->inputs->query(query: (array)($fetch['query'] ?? []), inputs: $accepted);
			} catch (InvalidArgumentException $invalid) {
				throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::INVALID_INPUT, $invalid->getMessage());
			}

			$request = ['path' => $path, 'query' => $query, 'inputs' => $accepted, 'app' => self::TEST_CALLER];
			$result['raw'] = $this->callSource(fetch: $fetch, request: $request);
			$result['outputs'] = $this->outputs(fetch: $fetch, answer: $result['raw']);
		} catch (ServiceFetchRefusal $refusal) {
			$result['refusal'] = $refusal->getMessage();
			$result['detail'] = $refusal->detail;
		}

		return $result;
	}//end test()

	/**
	 * The steps of design D3, throwing a ServiceFetchRefusal at the first refusal.
	 *
	 * @param ServiceFetchRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @throws ServiceFetchRefusal At the first refusal.
	 */
	private function run(ServiceFetchRequestedEvent $event): void {
		$fetch = $this->catalogue->fetchBySlug(slug: $event->getFetch());
		if ($fetch === null || $this->catalogue->isEnabled(fetch: $fetch) === false) {
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::UNKNOWN_FETCH);
		}

		if ($this->catalogue->allows(fetch: $fetch, app: $event->getApp()) === false) {
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::NOT_ALLOWED);
		}

		try {
			$inputs = $this->inputs->accepted(declared: $this->catalogue->listOf(value: ($fetch['inputs'] ?? [])), given: $event->getInputs());
			$path = $this->inputs->path(path: (string)($fetch['path'] ?? ''), inputs: $inputs);
			$query = $this->inputs->query(query: (array)($fetch['query'] ?? []), inputs: $inputs);
		} catch (InvalidArgumentException $invalid) {
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::INVALID_INPUT, $invalid->getMessage());
		}

		$cacheKey = $this->cacheKey(fetch: $fetch, inputs: $inputs);
		$cached = $this->cachedOutputs(fetch: $fetch, key: $cacheKey);
		if ($cached !== null) {
			$event->setOutputs(outputs: $cached['outputs'], fetchedAt: $cached['fetchedAt']);
			return;
		}

		$answer = $this->callSource(fetch: $fetch, request: ['path' => $path, 'query' => $query, 'inputs' => $inputs, 'app' => $event->getApp()]);
		$outputs = $this->outputs(fetch: $fetch, answer: $answer);
		$fetchedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$seconds = (int)($fetch['cacheSeconds'] ?? 0);
		if ($seconds > 0) {
			$this->cache->set($cacheKey, ['outputs' => $outputs, 'fetchedAt' => $fetchedAt], $seconds);
		}

		$event->setOutputs(outputs: $outputs, fetchedAt: $fetchedAt);
	}//end run()

	/**
	 * Call the fetch's source and return its answer as `{status, body}`.
	 *
	 * @param array<string, mixed> $fetch   The fetch.
	 * @param array<string, mixed> $request `path`, `query`, `inputs` and the calling `app`.
	 *
	 * @return array{status: int, body: mixed}
	 *
	 * @throws ServiceFetchRefusal When the source is unavailable or too slow.
	 */
	private function callSource(array $fetch, array $request): array {
		$source = $this->catalogue->source(id: (string)($fetch['source'] ?? ''));
		$method = 'GET';
		if (($fetch['method'] ?? 'GET') === 'POST') {
			$method = 'POST';
		}

		$timeoutMs = max(1, min(self::MAX_TIMEOUT_MS, (int)($fetch['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS)));
		$config = ['timeout' => ($timeoutMs / 1000)];
		if ($request['query'] !== []) {
			$config['query'] = $request['query'];
		}

		$bodyMapping = (string)($fetch['bodyMapping'] ?? '');
		if ($method === 'POST' && $bodyMapping !== '') {
			$config['json'] = $this->mappingService->executeMapping(mapping: $bodyMapping, input: $request['inputs']);
		}

		try {
			$callLog = $this->callService->call(source: $source, endpoint: $request['path'], method: $method, config: $config);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: service fetch call failed', ['fetch' => ($fetch['slug'] ?? ''), 'error' => $e->getMessage()]);
			$refusal = ServiceFetchRequestedEvent::SOURCE_UNAVAILABLE;
			if (preg_match('/timed out|timeout|cURL error 28/i', $e->getMessage()) === 1) {
				$refusal = ServiceFetchRequestedEvent::TIMEOUT;
			}

			throw new ServiceFetchRefusal($refusal);
		}

		$this->markCallLog(callLog: $callLog, fetch: $fetch, request: $request);

		$response = (array)($callLog->getObject()['response'] ?? []);
		$status = (int)($response['statusCode'] ?? 0);
		if ($status === 0 || $status === 429 || $status >= 500) {
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::SOURCE_UNAVAILABLE);
		}

		return ['status' => $status, 'body' => $this->decodedBody(response: $response)];
	}//end callSource()

	/**
	 * Apply the output mapping and keep only the declared outputs.
	 *
	 * @param array<string, mixed>            $fetch  The fetch.
	 * @param array{status: int, body: mixed} $answer The source's answer.
	 *
	 * @return array<string, mixed> The declared outputs the mapping wrote.
	 *
	 * @throws ServiceFetchRefusal When the mapping fails or leaves a required output out.
	 */
	private function outputs(array $fetch, array $answer): array {
		try {
			$mapped = $this->mappingService->executeMapping(mapping: (string)($fetch['outputMapping'] ?? ''), input: $answer);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: service fetch mapping failed', ['fetch' => ($fetch['slug'] ?? ''), 'error' => $e->getMessage()]);
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::MAPPING_FAILED);
		}

		$outputs = [];
		foreach ($this->catalogue->listOf(value: ($fetch['outputs'] ?? [])) as $declared) {
			$name = (string)($declared['name'] ?? '');
			if ($name === '') {
				continue;
			}

			if (array_key_exists($name, $mapped) === false) {
				if (($declared['required'] ?? false) === true) {
					throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::MAPPING_FAILED, $name);
				}

				continue;
			}

			$outputs[$name] = $mapped[$name];
		}

		return $outputs;
	}//end outputs()

	/**
	 * Put the calling app and the fetch on the call's log line, and redact
	 * every input value from it (design D4). Best-effort: a failure is logged.
	 *
	 * @param ObjectEntity         $callLog The call log the engine returned.
	 * @param array<string, mixed> $fetch   The fetch.
	 * @param array<string, mixed> $request `inputs` and the calling `app`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	private function markCallLog(ObjectEntity $callLog, array $fetch, array $request): void {
		$uuid = $callLog->getUuid();
		if ($uuid === null || $uuid === '') {
			return;
		}

		try {
			$stored = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: 'call_log', _rbac: false, _multitenancy: false);
			$row = $stored->getObject();
			$row['context'] = ['app' => (string)$request['app'], 'fetch' => (string)($fetch['slug'] ?? '')];
			$row = (new ServiceFetchRedactor())->redact(row: $row, values: $this->valuesToRedact(inputs: $request['inputs']));

			$this->objectService->saveObject(
				object: $row,
				register: self::REGISTER,
				schema: 'call_log',
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: could not mark the service fetch call log', ['fetch' => ($fetch['slug'] ?? ''), 'error' => $e->getMessage()]);
		}
	}//end markCallLog()

	/**
	 * The input values to redact from the log line, as text, longest first.
	 *
	 * @param array<string, mixed> $inputs The accepted inputs.
	 *
	 * @return list<string>
	 */
	private function valuesToRedact(array $inputs): array {
		$values = [];
		foreach ($inputs as $value) {
			$text = $this->inputs->text(value: $value);
			if ($text !== '' && is_bool($value) === false) {
				$values[] = $text;
			}
		}

		usort($values, static fn (string $a, string $b): int => (strlen($b) <=> strlen($a)));

		return array_values(array_unique($values));
	}//end valuesToRedact()

	/**
	 * The response body: decoded from base64 when it was logged so, then from JSON when it is JSON.
	 *
	 * @param array<string, mixed> $response The logged response.
	 *
	 * @return mixed
	 */
	private function decodedBody(array $response): mixed {
		$body = ($response['body'] ?? null);
		if (is_string($body) === false) {
			return $body;
		}

		if (($response['encoding'] ?? '') === 'base64') {
			$body = (string)base64_decode($body, true);
		}

		$decoded = json_decode($body, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return $body;
	}//end decodedBody()

	/**
	 * Cached outputs for this fetch and these inputs, when the fetch caches.
	 *
	 * @param array<string, mixed> $fetch The fetch.
	 * @param string               $key   The cache key.
	 *
	 * @return array{outputs: array<string, mixed>, fetchedAt: string}|null
	 */
	private function cachedOutputs(array $fetch, string $key): ?array {
		if ((int)($fetch['cacheSeconds'] ?? 0) <= 0) {
			return null;
		}

		$cached = $this->cache->get($key);
		if (is_array($cached) === false || is_array($cached['outputs'] ?? null) === false) {
			return null;
		}

		return ['outputs' => $cached['outputs'], 'fetchedAt' => (string)($cached['fetchedAt'] ?? '')];
	}//end cachedOutputs()

	/**
	 * The cache key of a fetch and its input values.
	 *
	 * @param array<string, mixed> $fetch  The fetch.
	 * @param array<string, mixed> $inputs The accepted inputs.
	 *
	 * @return string
	 */
	private function cacheKey(array $fetch, array $inputs): string {
		ksort($inputs);

		return 'fetch-' . hash('sha256', (string)($fetch['slug'] ?? '') . "\0" . json_encode($inputs));
	}//end cacheKey()

}//end class
