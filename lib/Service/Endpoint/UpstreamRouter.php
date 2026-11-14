<?php

/**
 * Integriq UpstreamRouter.
 *
 * Serves a request of an endpoint with several targets: picks the target,
 * calls it, retries an idempotent call once on another target, and records
 * on the call log which target served it and why.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\OrganisationBoundary;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The imperative half of upstream routing (design D2 to D4).
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
 */
class UpstreamRouter {
	use OrganisationBoundary;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService  $objects     Loads the target sources and stamps the call log.
	 * @param CallService      $calls       Makes the upstream call.
	 * @param UpstreamSelector $selector    The selection rules.
	 * @param LoggerInterface  $logger      Logs a call log that could not be stamped.
	 */
	public function __construct(
		private readonly ORObjectService $objects,
		private readonly CallService $calls,
		private readonly UpstreamSelector $selector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the endpoint routes over several targets rather than its one `targetId`.
	 *
	 * @param array<string, mixed> $endpointData The endpoint object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001
	 */
	public function routes(array $endpointData): bool {
		return $this->selector->targetsOf(endpointData: $endpointData) !== [];
	}//end routes()

	/**
	 * Call the target this request routes to, retrying an idempotent call once on another.
	 *
	 * @param UpstreamRequest            $request The request, as the endpoint received it.
	 * @param ExecutionTraceContext|null $trace   The active execution trace.
	 *
	 * @return ObjectEntity|JSONResponse The call log of the call that answered, or a 503 when no target can take it.
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002
	 */
	public function call(UpstreamRequest $request, ?ExecutionTraceContext $trace = null): ObjectEntity|JSONResponse {
		$endpointData = $request->endpoint->getObject();
		$targets = $this->selector->targetsOf(endpointData: $endpointData);
		$choice = $this->selector->candidates(targets: $targets, request: $this->requestData(request: $request, targets: $targets));
		$healthy = $this->healthy(endpoint: $request->endpoint, candidates: $choice['targets']);

		$sticky = null;
		if (($endpointData['sticky'] ?? false) === true) {
			$sticky = $request->consumer;
		}

		$target = $this->selector->pick(candidates: $healthy, stickyKey: $sticky);
		if ($target === null) {
			return new JSONResponse(
				['error' => 'No target of this endpoint can take the request now', 'group' => $choice['group']],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$reason = $choice['reason'];
		if ($reason === 'weight' && $sticky !== null && $sticky !== '') {
			$reason = 'sticky';
		}

		$callLog = $this->dispatch(request: $request, target: $target, trace: $trace);
		$status = (int)($callLog->getObject()['statusCode'] ?? 0);
		if ($this->selector->mayRetry(method: $request->method, status: $status) === true) {
			$others = array_values(array_filter($healthy, static fn (array $other): bool => $other['index'] !== $target['index']));
			$retryTarget = $this->selector->pick(candidates: $others);
			if ($retryTarget !== null) {
				$this->stamp(callLog: $callLog, target: $target, reason: $reason);
				$target = $retryTarget;
				$reason = 'retry';
				$callLog = $this->dispatch(request: $request, target: $target, trace: $trace);
			}
		}

		return $this->stamp(callLog: $callLog, target: $target, reason: $reason);
	}//end call()

	/**
	 * What the targets' rules read: the parameters, the headers and, only when a rule reads it, the JSON body.
	 *
	 * @param UpstreamRequest $request The request.
	 * @param list<array{when: mixed}> $targets The normalised targets.
	 *
	 * @return array<string, mixed>
	 */
	private function requestData(UpstreamRequest $request, array $targets): array {
		$data = ['parameters' => $request->parameters, 'headers' => $request->headers, 'body' => []];
		if ($this->selector->readsBody(targets: $targets) === true) {
			$body = json_decode($request->rawBody, true);
			if (is_array($body) === true) {
				$data['body'] = $body;
			}
		}

		return $data;
	}//end requestData()

	/**
	 * The candidates whose source exists, belongs to the endpoint's organisation and is not down, each with its source.
	 *
	 * @param ObjectEntity $endpoint The endpoint.
	 * @param list<array{index: int, source: string, weight: int, when: mixed, group: string}> $candidates The candidates.
	 *
	 * @return list<array{index: int, source: string, weight: int, when: mixed, group: string, entity: ObjectEntity}>
	 */
	private function healthy(ObjectEntity $endpoint, array $candidates): array {
		$healthy = [];
		$now = time();
		foreach ($candidates as $candidate) {
			$source = $this->findSource(uuid: $candidate['source']);
			if ($source === null
				|| $this->sameOrganisation(first: $this->organisationOf(object: $endpoint), second: $this->organisationOf(object: $source)) === false
				|| $this->selector->isDown(sourceData: $source->getObject(), now: $now) === true
			) {
				continue;
			}

			$healthy[] = $candidate + ['entity' => $source];
		}

		return $healthy;
	}//end healthy()

	/**
	 * Load a target source in system context, as the single-target path does.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return ObjectEntity|null Null when it does not exist.
	 */
	private function findSource(string $uuid): ?ObjectEntity {
		try {
			$source = $this->objects->find(id: $uuid, register: 'integriq', schema: 'source', _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		if ($source instanceof ObjectEntity === false) {
			return null;
		}

		return $source;
	}//end findSource()

	/**
	 * Call one target.
	 *
	 * @param UpstreamRequest $request The request.
	 * @param array{entity: ObjectEntity} $target The target, with its source.
	 * @param ExecutionTraceContext|null $trace The active trace.
	 *
	 * @return ObjectEntity The call log.
	 */
	private function dispatch(UpstreamRequest $request, array $target, ?ExecutionTraceContext $trace): ObjectEntity {
		return $this->calls->call(
			source: $target['entity'],
			endpoint: $request->upstreamPath,
			method: $request->method,
			// A gateway call is live: it waits only briefly for a paced turn (REQ-RLPC-002).
			config: ['query' => $request->parameters, 'headers' => $request->headers, 'body' => $request->rawBody, 'pacing' => 'live'],
			trace: $trace
		);
	}//end dispatch()

	/**
	 * Record on the call log which target served the call and why (REQ-UPRT-003).
	 *
	 * A call log that cannot be stamped is logged, never a failed request.
	 *
	 * @param ObjectEntity $callLog The call log.
	 * @param array{source: string} $target The target that served the call.
	 * @param string $reason `rule:<index>`, `weight`, `sticky` or `retry`.
	 *
	 * @return ObjectEntity The call log, stamped when it could be.
	 *
	 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-the-call-log-records-which-target-served-a-call-req-uprt-003
	 */
	private function stamp(ObjectEntity $callLog, array $target, string $reason): ObjectEntity {
		$routing = ['routedTarget' => $target['source'], 'routedReason' => $reason];
		$uuid = (string)$callLog->getUuid();
		if ($uuid === '') {
			return $callLog;
		}

		try {
			// Merge onto the stored row, which CallService redacted before persisting
			// (http-call-engine REQ-006); the entity in hand may still hold the bodies.
			$stored = $this->objects->find(id: $uuid, register: 'integriq', schema: 'call_log', _rbac: false, _multitenancy: false);
			if ($stored instanceof ObjectEntity === false) {
				$this->logger->warning('[integriq] the call log ' . $uuid . ' was not found to record its routed target');
				return $callLog;
			}

			$saved = $this->objects->saveObject(
				object: array_merge((array)$stored->getObject(), $routing),
				register: 'integriq',
				schema: 'call_log',
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				silent: true,
				_validation: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[integriq] the call log ' . $uuid . ' could not record its routed target', ['exception' => $e->getMessage()]);
			return $callLog;
		}

		if ($saved instanceof ObjectEntity === false) {
			return $callLog;
		}

		return $saved;
	}//end stamp()
}//end class
