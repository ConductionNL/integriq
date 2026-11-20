<?php

/**
 * Integriq GatewayConnectionAuthorizer
 *
 * Answers a web server or broker: may this caller open this connection.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Gateway;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\RateLimit\InboundRateLimitServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use SensitiveParameter;
use Throwable;

/**
 * The connection authorization of design D2.
 *
 * In order: the server secret (a wrong one is 401 and logs nothing, since it
 * is not a connection), the client's credential to a consumer, the `stream`
 * endpoint whose protocol and route cover the connection, that endpoint's
 * allowed consumers and required scopes, and its connection rate limit. Every
 * answer past the server secret is one call log row with `protocol` and
 * `connectionId`. Any failure to read refuses: it never lets a caller through.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */
class GatewayConnectionAuthorizer {

	/**
	 * The protocols a stream endpoint describes.
	 */
	public const PROTOCOLS = ['websocket', 'grpc', 'mqtt'];

	/**
	 * The endpoint target type of a stream route.
	 */
	public const TARGET_TYPE = 'stream';

	/**
	 * Constructor for GatewayConnectionAuthorizer.
	 *
	 * @param GatewayServerSecret              $serverSecret The shared secret of the server.
	 * @param OpenRegisterCredentialBridge     $credentials  Resolves the client's credential to a consumer.
	 * @param StreamConnectionRecords          $records      Finds stream endpoints and writes the call log.
	 * @param InboundRateLimitServiceInterface $rateLimits   The limiter consumer rate limits use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GatewayServerSecret $serverSecret,
		private readonly OpenRegisterCredentialBridge $credentials,
		private readonly StreamConnectionRecords $records,
		private readonly InboundRateLimitServiceInterface $rateLimits,
	) {

	}//end __construct()

	/**
	 * Decide one connection.
	 *
	 * @param array<string, string> $ask What the server sent: serverSecret, protocol, target, credential,
	 *                                   clientAddress and connectionId.
	 *
	 * @return array{status: int, reason: string, consumer: string|null, headers: array<string, string>}
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
	 */
	public function authorize(array $ask): array {
		if ($this->serverSecret->verify(presented: (string)($ask['serverSecret'] ?? '')) === false) {
			return self::answer(status: Http::STATUS_UNAUTHORIZED, reason: 'server_not_authorized');
		}

		$protocol = strtolower(trim((string)($ask['protocol'] ?? '')));
		$target   = (string)($ask['target'] ?? '');
		if (in_array($protocol, self::PROTOCOLS, true) === false || trim($target) === '') {
			return self::answer(status: Http::STATUS_UNAUTHORIZED, reason: 'unknown_connection');
		}

		$endpoint = $this->records->streamEndpoint(protocol: $protocol, target: $target);
		$consumer = $this->consumer(credential: (string)($ask['credential'] ?? ''));
		$decision = $this->decide(endpoint: $endpoint, consumer: $consumer);

		$this->records->log(ask: $ask + ['protocol' => $protocol], endpoint: $endpoint, consumer: $consumer, decision: $decision);

		return $decision;

	}//end authorize()

	/**
	 * The answer for a known protocol and target.
	 *
	 * @param ObjectEntity|null $endpoint The stream endpoint covering the target.
	 * @param ObjectEntity|null $consumer The consumer the credential named.
	 *
	 * @return array{status: int, reason: string, consumer: string|null, headers: array<string, string>}
	 */
	private function decide(?ObjectEntity $endpoint, ?ObjectEntity $consumer): array {
		if ($consumer === null) {
			return self::answer(status: Http::STATUS_UNAUTHORIZED, reason: 'no_consumer');
		}

		if ($endpoint === null) {
			return self::answer(status: Http::STATUS_UNAUTHORIZED, reason: 'no_stream_endpoint');
		}

		$endpointData = $endpoint->getObject();
		$uuid = $this->records->uuidOf(entity: $consumer);
		if (self::consumerAllowed(endpointData: $endpointData, consumerUuid: $uuid, consumerData: $consumer->getObject()) === false) {
			return self::answer(status: Http::STATUS_UNAUTHORIZED, reason: 'consumer_not_allowed');
		}

		$limit = ($endpointData['connectionRateLimit'] ?? null);
		if (is_array($limit) === true && $limit !== []) {
			$rate = $this->rateLimits->enforce(
				consumerKey: 'stream:' . $this->records->uuidOf(entity: $endpoint) . ':consumer:' . $uuid,
				rateLimit: $limit,
				quota: null
			);
			if ($rate->allowed === false) {
				return self::answer(status: Http::STATUS_TOO_MANY_REQUESTS, reason: 'rate_limited', headers: $rate->toHeaders());
			}
		}

		return self::answer(status: Http::STATUS_OK, reason: 'allowed', consumer: $uuid, headers: ['X-Integriq-Consumer' => $uuid]);

	}//end decide()

	/**
	 * Whether a consumer may connect to a stream endpoint: on its consumer list
	 * (an empty list lets any consumer) and holding every required scope.
	 *
	 * @param array<string, mixed> $endpointData The stream endpoint.
	 * @param string               $consumerUuid The consumer's uuid.
	 * @param array<string, mixed> $consumerData The consumer.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
	 */
	public static function consumerAllowed(array $endpointData, string $consumerUuid, array $consumerData): bool {
		$allowed = ($endpointData['allowedConsumers'] ?? []);
		if (is_array($allowed) === true && $allowed !== [] && in_array($consumerUuid, array_map('strval', $allowed), true) === false) {
			return false;
		}

		$required = ($endpointData['requiredScopes'] ?? []);
		if (is_array($required) === false || $required === []) {
			return true;
		}

		$held = ($consumerData['scopes'] ?? []);
		if (is_array($held) === false) {
			return false;
		}

		return array_diff(array_map('strval', $required), array_map('strval', $held)) === [];

	}//end consumerAllowed()

	/**
	 * The consumer a credential names: a `Bearer` JWT, else an API key.
	 *
	 * @param string $credential The client's credential.
	 *
	 * @return ObjectEntity|null Null when it names none or is refused.
	 */
	private function consumer(#[SensitiveParameter] string $credential): ?ObjectEntity {
		$credential = trim($credential);
		if ($credential === '') {
			return null;
		}

		try {
			$this->authorizeCredential(credential: $credential);
		} catch (Throwable $e) {
			return null;
		}

		return $this->credentials->getResolvedConsumer();

	}//end consumer()

	/**
	 * Let the credential bridge resolve a credential: a `Bearer` JWT, else an API key.
	 *
	 * @param string $credential The client's credential, trimmed and not empty.
	 *
	 * @return void
	 *
	 * @throws Throwable When the bridge refuses the credential.
	 */
	private function authorizeCredential(#[SensitiveParameter] string $credential): void {
		if (str_starts_with($credential, 'Bearer ') === true) {
			$this->credentials->authorizeJwt(authorization: $credential);
			return;
		}

		$this->credentials->authorizeApiKey(header: $credential, keys: []);

	}//end authorizeCredential()

	/**
	 * One answer.
	 *
	 * @param int                   $status   The HTTP status.
	 * @param string                $reason   Why.
	 * @param string|null           $consumer The consumer uuid when allowed.
	 * @param array<string, string> $headers  Headers for the server.
	 *
	 * @return array{status: int, reason: string, consumer: string|null, headers: array<string, string>}
	 */
	private static function answer(int $status, string $reason, ?string $consumer=null, array $headers=[]): array {
		return ['status' => $status, 'reason' => $reason, 'consumer' => $consumer, 'headers' => $headers];

	}//end answer()
}//end class
