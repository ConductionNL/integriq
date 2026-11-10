<?php

/**
 * Integriq OCS API dispatcher.
 *
 * Dispatches one call to a sibling app's OCS API through CallService::call()
 * and decodes or classifies the response, the way the Forms and Tables
 * clients both did: a transport failure is logged and raised as the API's
 * upstream exception, 401/403 as its permission exception (with the status),
 * 404 as its not-found exception, any other non-2xx as its upstream exception;
 * an empty or non-JSON-object body decodes to null.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Calls a sibling app's OCS API and classifies the outcome.
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */
final class OcsApiDispatcher {

	/**
	 * Constructor.
	 *
	 * @param CallService     $callService The shared HTTP dispatcher.
	 * @param LoggerInterface $logger      Logger for upstream-failure diagnostics.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Dispatch one call and decode or classify its response.
	 *
	 * @param OcsRequest $request The call.
	 *
	 * @return array|null The JSON-decoded response body, or null for an empty/204 or non-object body.
	 *
	 * @throws \Exception The API's permission, not-found or upstream exception.
	 *
	 * @spec openspec/specs/nextcloud-tables-connector/spec.md
	 */
	public function dispatch(OcsRequest $request): ?array {
		$callLog = $this->call(request: $request);
		$this->assertSuccessful(request: $request, callLog: $callLog);

		return $this->decodedBody(callLog: $callLog);

	}//end dispatch()

	/**
	 * Make the call; a transport failure is logged and raised as the API's upstream exception.
	 *
	 * @param OcsRequest $request The call.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @throws \Exception The API's upstream exception.
	 */
	private function call(OcsRequest $request): ObjectEntity {
		try {
			return $this->callService->call(source: $request->source, endpoint: $request->endpoint, method: $request->method, config: $request->config);
		} catch (GuzzleException|LoaderError|SyntaxError|\OCP\DB\Exception $exception) {
			$label = $request->api->label;
			$this->logger->warning(
				"{$label}OcsClient: transport failure calling {$label} API",
				['endpoint' => $request->endpoint, 'method' => $request->method, 'error' => $exception->getMessage()]
			);
			throw new ($request->api->upstream)(
				message: "Failed to reach the {$label} API ({$request->method} {$request->endpoint}): " . $exception->getMessage()
			);
		}

	}//end call()

	/**
	 * Refuse a response that is not 2xx with the exception its status maps to.
	 *
	 * @param OcsRequest   $request The call.
	 * @param ObjectEntity $callLog Its call log.
	 *
	 * @return void
	 *
	 * @throws \Exception The API's permission, not-found or upstream exception.
	 */
	private function assertSuccessful(OcsRequest $request, ObjectEntity $callLog): void {
		$statusCode = (int)($callLog->getObject()['response']['statusCode'] ?? 0);
		$this->assertPermittedAndFound(request: $request, statusCode: $statusCode);

		if ($statusCode < 200 || $statusCode >= 300) {
			throw new ($request->api->upstream)(
				message: "{$request->api->label} API returned HTTP {$statusCode} for {$request->method} {$request->endpoint} (see CallLog {$callLog->getUuid()})"
			);
		}

	}//end assertSuccessful()

	/**
	 * Refuse a 401/403 as the permission exception and a 404 as the not-found exception.
	 *
	 * @param OcsRequest $request    The call.
	 * @param int        $statusCode The response status.
	 *
	 * @return void
	 *
	 * @throws \Exception The API's permission or not-found exception.
	 */
	private function assertPermittedAndFound(OcsRequest $request, int $statusCode): void {
		$label = $request->api->label;
		if ($statusCode === 401 || $statusCode === 403) {
			// Positional: the permission exceptions take (message, statusCode), the same order as Exception's (message, code).
			throw new ($request->api->permissionDenied)(
				"{$label} API denied {$request->method} {$request->endpoint} (HTTP {$statusCode})",
				$statusCode
			);
		}

		if ($statusCode === 404) {
			throw new ($request->api->notFound)(message: "{$label} API resource not found: {$request->method} {$request->endpoint}");
		}

	}//end assertPermittedAndFound()

	/**
	 * The response body decoded as a JSON object or list; null when empty or anything else.
	 *
	 * @param ObjectEntity $callLog The call log.
	 *
	 * @return array|null The decoded body.
	 */
	private function decodedBody(ObjectEntity $callLog): ?array {
		$rawBody = (string)($callLog->getObject()['response']['body'] ?? '');
		if ($rawBody === '') {
			return null;
		}

		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;

	}//end decodedBody()
}//end class
