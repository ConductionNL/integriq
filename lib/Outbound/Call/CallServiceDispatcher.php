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
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use OCA\Integriq\Exception\CallDispatchException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Dispatches a replayed or hand-fired call through CallService.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
class CallServiceDispatcher implements CallDispatcherInterface {

	/**
	 * Constructor.
	 *
	 * @param CallServiceInterface $callService The engine every outbound HTTP call goes through.
	 * @param ORObjectService $objectService Resolves the target source.
	 */
	public function __construct(
		private readonly CallServiceInterface $callService,
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Send one call through the engine.
	 *
	 * @param string $target The source to call.
	 * @param array<string,mixed> $request The request: `method`, `endpoint` (or the stored `url`), `headers`, `body`.
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
				endpoint: $this->endpointOf(source: $source, request: $request),
				method: strtoupper((string)($request['method'] ?? 'POST')),
				config: $config,
			);
		} catch (Throwable $exception) {
			throw new CallDispatchException(message: 'The call could not be made: ' . $exception->getMessage());
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
	 * The endpoint a request names, from `endpoint` or from a stored call record's `url`.
	 *
	 * A call record stores `url` and `method`, not `endpoint`. A `url` is taken as the endpoint
	 * only when it lies under the source's own address and has no redacted part: a URL whose
	 * secrets were replaced on the way into the log would be replayed with the placeholder.
	 *
	 * @param ObjectEntity $source The source being called.
	 * @param array<string,mixed> $request The request.
	 *
	 * @return string The endpoint, relative to the source.
	 *
	 * @throws CallDispatchException When the stored URL is outside the source's address or was redacted.
	 */
	private function endpointOf(ObjectEntity $source, array $request): string {
		$endpoint = (string)($request['endpoint'] ?? '');
		$url = (string)($request['url'] ?? '');
		if ($endpoint !== '' || $url === '') {
			return $endpoint;
		}

		$rest = $this->restUnderSource(source: $source, url: $url);
		$this->assertNoDotSegment(rest: $rest);

		return $rest;
	}//end endpointOf()

	/**
	 * The part of a stored URL that follows the source's address.
	 *
	 * @param ObjectEntity $source The source being called.
	 * @param string       $url    The stored URL.
	 *
	 * @return string The remainder: '', or a path or query that starts at the address boundary.
	 *
	 * @throws CallDispatchException When the URL is outside the source's address or has a redacted part.
	 */
	private function restUnderSource(ObjectEntity $source, string $url): string {
		$location = rtrim((string)($source->getObject()['location'] ?? ''), '/');
		if ($location === '' || stripos($url, $location) !== 0 || stripos($url, 'REDACTED') !== false) {
			throw new CallDispatchException(message: 'The stored URL cannot be replayed: it is outside the source address or has a redacted part.');
		}

		$rest = substr($url, strlen($location));
		if ($rest !== '' && $rest[0] !== '/' && $rest[0] !== '?') {
			throw new CallDispatchException(message: 'The stored URL cannot be replayed: it is outside the source address or has a redacted part.');
		}

		return $rest;
	}//end restUnderSource()

	/**
	 * Refuse a stored URL path that holds a dot segment.
	 *
	 * A dot segment (plain or percent-encoded) would climb out of the source's address once the path
	 * is normalised. Decoded twice, so a double-encoded `%252e%252e` does not slip through either.
	 *
	 * @param string $rest The remainder of the URL after the source's address.
	 *
	 * @return void
	 *
	 * @throws CallDispatchException When the path holds a dot segment.
	 */
	private function assertNoDotSegment(string $rest): void {
		$path = (string)preg_replace('/[?#].*$/s', '', $rest);
		$once = rawurldecode($path);
		if (preg_match('#(^|/)\.{1,2}(/|$)#', rawurldecode($once)) === 1 || preg_match('#(^|/)\.{1,2}(/|$)#', $once) === 1) {
			throw new CallDispatchException(message: 'The stored URL cannot be replayed: its path holds a dot segment.');
		}
	}//end assertNoDotSegment()

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
			throw new CallDispatchException(message: 'No source "' . $target . '" to call.');
		}

		if (($source instanceof ObjectEntity) === false) {
			throw new CallDispatchException(message: 'No source "' . $target . '" to call.');
		}

		return $source;

	}//end source()

}//end class
