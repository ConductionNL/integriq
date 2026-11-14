<?php

/**
 * Integriq ConnectionCallService.
 *
 * Calls the source an administrator linked to a connection an app declared.
 * The app names its connection key and a request below the source's
 * location; the call runs through the call engine with the source's own
 * credential, so the app never holds it. Every outcome is an answer or a
 * structured refusal: nothing here throws to the caller.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One call through a declared, linked connection.
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */
class ConnectionCallService {

	/**
	 * The connection key is not one the app declared.
	 *
	 * @var string
	 */
	public const NOT_DECLARED = 'not-declared';

	/**
	 * The connection is declared but no source is linked to it.
	 *
	 * @var string
	 */
	public const NOT_LINKED = 'not-linked';

	/**
	 * The request is not one this command makes.
	 *
	 * @var string
	 */
	public const INVALID = 'invalid';

	/**
	 * The call engine could not make the call.
	 *
	 * @var string
	 */
	public const CALL_FAILED = 'call-failed';

	/**
	 * The methods a connection may be called with.
	 *
	 * @var list<string>
	 */
	public const METHODS = ['GET', 'POST'];

	/**
	 * Constructor.
	 *
	 * @param ConnectionStoreInterface $store       Reads the app's connection rows and the linked source.
	 * @param CallServiceInterface     $callService Makes and logs the call.
	 * @param LoggerInterface          $logger      Records refused and failed calls.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function __construct(
		private readonly ConnectionStoreInterface $store,
		private readonly CallServiceInterface $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Call an app's declared connection.
	 *
	 * @param string                   $app           The calling app id.
	 * @param string                   $connectionKey The declared connection key.
	 * @param string                   $method        `GET` or `POST`.
	 * @param string                   $path          The path below the source's location.
	 * @param array<string,mixed>      $query         Query parameters, scalar values only.
	 * @param array<mixed>|string|null $body          A POST body: an array is sent as JSON.
	 *
	 * @return array{status:int,body:mixed,sourceSlug:string}|array{refusal:array{code:string,reason:string}}
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function call(
		string $app,
		string $connectionKey,
		string $method = 'GET',
		string $path = '',
		array $query = [],
		array|string|null $body = null,
	): array {
		$method  = strtoupper(trim($method));
		$invalid = self::requestRefusal(method: $method, path: $path, query: $query);
		if ($invalid !== null) {
			return $this->refused(app: $app, key: $connectionKey, code: self::INVALID, reason: $invalid);
		}

		$linked = $this->linkedSource(app: $app, connectionKey: $connectionKey);
		if (isset($linked['refusal']) === true) {
			return $linked;
		}

		$source = $linked['source'];
		$slug   = (string)($source->getObject()['slug'] ?? '');

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $path,
				method: $method,
				config: self::callConfig(method: $method, query: $query, body: $body)
			);
		} catch (Throwable $e) {
			return $this->refused(
				app: $app,
				key: $connectionKey,
				code: self::CALL_FAILED,
				reason: sprintf('The call to the source "%s" failed: %s', $slug, $e->getMessage())
			);
		}

		$data     = $callLog->getObject();
		$response = $data['response'] ?? [];
		if (is_array($response) === false) {
			$response = [];
		}

		return [
			'status' => (int)($data['statusCode'] ?? $response['statusCode'] ?? 0),
			'body' => self::decodedBody(body: $response['body'] ?? null),
			'sourceSlug' => $slug,
		];
	}//end call()

	/**
	 * The source linked to an app's declared connection, or the refusal.
	 *
	 * @param string $app           The app id.
	 * @param string $connectionKey The connection key.
	 *
	 * @return array{source:ObjectEntity,title:string}|array{refusal:array{code:string,reason:string}}
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function linkedSource(string $app, string $connectionKey): array {
		$row = null;
		if ($app !== '' && $connectionKey !== '') {
			foreach ($this->store->findRows(app: $app) as $candidate) {
				$data = $candidate['data'] ?? [];
				if (($data['app'] ?? null) === $app && ($data['key'] ?? null) === $connectionKey) {
					$row = $data;
					break;
				}
			}
		}

		if ($row === null) {
			return $this->refused(
				app: $app,
				key: $connectionKey,
				code: self::NOT_DECLARED,
				reason: sprintf('The app "%s" declares no connection "%s".', $app, $connectionKey)
			);
		}

		$title    = (string)($row['title'] ?? $connectionKey);
		$sourceId = (string)($row['source'] ?? '');
		$source   = null;
		if ($sourceId !== '') {
			$source = $this->store->findSource(uuid: $sourceId);
		}

		if ($source === null) {
			return $this->refused(
				app: $app,
				key: $connectionKey,
				code: self::NOT_LINKED,
				reason: sprintf('The connection "%s" is not linked to a source yet.', $title)
			);
		}

		return ['source' => $source, 'title' => $title];
	}//end linkedSource()

	/**
	 * Why a request is not one this command makes, or null when it is.
	 *
	 * The path is appended to the source's location and may not leave it: no
	 * scheme or host, no `..` segment, no query, fragment or credentials of
	 * its own, no backslash or control character.
	 *
	 * @param string              $method The upper-cased method.
	 * @param string              $path   The path.
	 * @param array<string,mixed> $query  The query.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public static function requestRefusal(string $method, string $path, array $query): ?string {
		if (in_array($method, self::METHODS, true) === false) {
			return sprintf('The method "%s" is not allowed; use GET or POST.', $method);
		}

		if ($path !== '' && (
			str_starts_with($path, '/') === false
			|| str_starts_with($path, '//') === true
			|| preg_match('/[\\\\?#@\x00-\x1F\x7F]|:\/\//', $path) === 1
			|| in_array('..', explode('/', rawurldecode($path)), true) === true
		)) {
			return 'The path must start with "/" and stay below the source\'s location.';
		}

		foreach ($query as $name => $value) {
			if (is_string($name) === false || ($value !== null && is_scalar($value) === false)) {
				return 'Query parameters must be named and hold a single value each.';
			}
		}

		return null;
	}//end requestRefusal()

	/**
	 * The call engine configuration for the request.
	 *
	 * @param string                   $method The method.
	 * @param array<string,mixed>      $query  The query.
	 * @param array<mixed>|string|null $body   The body.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private static function callConfig(string $method, array $query, array|string|null $body): array {
		$config = [];
		if ($query !== []) {
			$config['query'] = $query;
		}

		if ($method !== 'POST' || $body === null) {
			return $config;
		}

		if (is_array($body) === true) {
			$config['body']    = (string)json_encode($body);
			$config['headers'] = ['Content-Type' => 'application/json'];
			return $config;
		}

		$config['body'] = $body;
		return $config;
	}//end callConfig()

	/**
	 * A response body as decoded JSON, or as the raw text when it is not JSON.
	 *
	 * @param mixed $body The body the call log holds.
	 *
	 * @return mixed The body.
	 */
	private static function decodedBody(mixed $body): mixed {
		if (is_string($body) === false) {
			return $body;
		}

		$decoded = json_decode($body, true);
		if ($decoded === null && trim($body) !== 'null') {
			return $body;
		}

		return $decoded;
	}//end decodedBody()

	/**
	 * Log a refusal and return it.
	 *
	 * @param string $app    The app id.
	 * @param string $key    The connection key.
	 * @param string $code   The refusal code.
	 * @param string $reason The reason.
	 *
	 * @return array{refusal:array{code:string,reason:string}}
	 */
	private function refused(string $app, string $key, string $code, string $reason): array {
		$this->logger->info(
			'Integriq: a connection call was refused',
			['app' => $app, 'connection' => $key, 'code' => $code]
		);

		return ['refusal' => ['code' => $code, 'reason' => $reason]];
	}//end refused()
}//end class
