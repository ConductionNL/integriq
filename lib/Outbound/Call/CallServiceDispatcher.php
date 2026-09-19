<?php

/**
 * Integriq CallServiceDispatcher.
 *
 * The production binding of the replay seam: it resolves the target source
 * and calls `CallService::call()`, the same path the original call took, with
 * that source's retry policy and circuit breaker still in force. A replay is
 * the call again, not a call that resembles it.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use OCA\Integriq\Exception\CallDispatchException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Dispatches a replayed or hand-fired call through CallService.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallServiceDispatcher implements CallDispatcherInterface {

	/**
	 * Constructor.
	 *
	 * @param CallService $callService The engine every outbound HTTP call goes through.
	 * @param ORObjectService $objectService Resolves the target source.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Send one call through the engine.
	 *
	 * @param string $target The source to call.
	 * @param array<string,mixed> $request The request: `method`, `endpoint`, `headers`, `body`.
	 *
	 * @return array{statusCode:int,body:mixed,headers:array<string,mixed>,durationMs:int,detail:string}
	 *         What came back.
	 *
	 * @throws CallDispatchException When the target is unknown or the engine refused outright.
	 */
	public function dispatch(string $target, array $request): array {
		$source = $this->source(target: $target);

		$config = [];
		foreach (['headers', 'query', 'body'] as $key) {
			if (isset($request[$key]) === true) {
				$config[$key] = $request[$key];
			}
		}

		$started = microtime(true);
		try {
			$log = $this->callService->call(
				source: $source,
				endpoint: (string)($request['endpoint'] ?? ''),
				method: strtoupper((string)($request['method'] ?? 'POST')),
				config: $config,
			);
		} catch (Throwable $exception) {
			throw new CallDispatchException('The call could not be made: ' . $exception->getMessage());
		}

		$logged = $log->getObject();
		$response = ($logged['response'] ?? []);
		if (is_array($response) === false) {
			$response = ['body' => $response];
		}

		$responseHeaders = ($response['headers'] ?? null);
		if (is_array($responseHeaders) === false) {
			$responseHeaders = [];
		}

		return [
			'statusCode' => (int)($logged['statusCode'] ?? ($response['statusCode'] ?? 0)),
			'body' => ($response['body'] ?? null),
			'headers' => $responseHeaders,
			'durationMs' => (int)round(((microtime(true) - $started) * 1000)),
			'detail' => (string)($logged['statusMessage'] ?? ''),
		];

	}//end dispatch()

	/**
	 * Resolve the target source.
	 *
	 * @param string $target The source id.
	 *
	 * @return ObjectEntity The source.
	 *
	 * @throws CallDispatchException When there is no such source.
	 */
	private function source(string $target): ObjectEntity {
		try {
			$source = $this->objectService->find(
				id: $target,
				register: MessageRecorder::REGISTER,
				schema: 'source',
			);
		} catch (DoesNotExistException) {
			throw new CallDispatchException('No source "' . $target . '" to call.');
		}

		if (($source instanceof ObjectEntity) === false) {
			throw new CallDispatchException('No source "' . $target . '" to call.');
		}

		return $source;

	}//end source()

}//end class
