<?php

/**
 * Integriq — the operator's Digikoppeling ebMS adapter, over its REST API.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 * @link https://github.com/eluinstra/ebms-core/blob/ebms-core-2.20.x/core/src/main/java/nl/clockwork/ebms/api/ebms/EbMSRestController.java
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Berichtenbox;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\Security\EgressGuard;
use OCP\Security\ICrypto;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The ebMS leg (design D2): integriq never speaks ebMS on the wire.
 *
 * The adapter holds the CPA, the PKIoverheid key for ebMS, the retry schedule
 * and the acknowledgements. Integriq uses six calls of ebms-core's
 * `EbMSRestController`: `POST messages`, `GET messages/unprocessed`,
 * `GET messages/{id}`, `PATCH messages/{id}`, `GET events/unprocessed` and
 * `PATCH events/{id}`. `adapterUrl` is the base those paths hang off, for
 * ebms-admin typically `…/service/rest/v19/ebms`.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
 */
class EbmsAdapterClient {
	public const ACTION_REQUEST = 'GLOBE-R-BV-Request';

	public const ACTION_RESULT = 'GLOBE-R-BV-Result';

	public const EVENT_TYPES = ['DELIVERED', 'FAILED', 'EXPIRED'];

	/**
	 * Constructor.
	 *
	 * @param Client $httpClient The HTTP client.
	 * @param EgressGuard $egressGuard Refuses a URL integriq may not call.
	 * @param ICrypto $crypto Decrypts the optional adapter token.
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly EgressGuard $egressGuard,
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * The transport message id integriq gives a letter: its BerichtID, as an RFC 2822 msg-id.
	 *
	 * @param string $berichtId The BerichtID.
	 *
	 * @return string The ebMS MessageId.
	 */
	public static function messageIdFor(string $berichtId): string {
		return $berichtId . '@integriq.berichtenbox';
	}//end messageIdFor()

	/**
	 * Hand one batch to the adapter.
	 *
	 * @param BerichtenboxBatch $batch The batch.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The message id the adapter answered.
	 *
	 * @throws BerichtenboxException When the adapter does not take it.
	 */
	public function send(BerichtenboxBatch $batch, array $config): string {
		$properties = array_filter(
			[
				'cpaId' => (string)($config['cpaId'] ?? ''),
				'fromPartyId' => (string)($config['fromPartyId'] ?? $config['senderOin'] ?? ''),
				'fromRole' => (string)($config['fromRole'] ?? ''),
				'toPartyId' => (string)($config['toPartyId'] ?? ''),
				'toRole' => (string)($config['toRole'] ?? ''),
				'service' => (string)($config['service'] ?? ''),
				'action' => (string)($config['requestAction'] ?? self::ACTION_REQUEST),
				'conversationId' => $batch->berichtId,
				'messageId' => self::messageIdFor(berichtId: $batch->berichtId),
			],
			static fn (string $value): bool => $value !== ''
		);

		$response = $this->call(
			config: $config,
			method: 'POST',
			path: 'messages',
			options: [
				'json' => [
					'properties' => $properties,
					'dataSources' => [[
						'name' => self::ACTION_REQUEST . '.xml',
						'contentType' => 'application/xml',
						'content' => base64_encode($batch->xml),
					]],
				],
			]
		);

		$messageId = trim((string)$response->getBody());
		if ($messageId === '') {
			return self::messageIdFor(berichtId: $batch->berichtId);
		}

		return $messageId;
	}//end send()

	/**
	 * The result messages waiting for this source.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> Message ids.
	 */
	public function unprocessedResults(array $config): array {
		$query = http_build_query(['cpaId' => (string)($config['cpaId'] ?? ''), 'action' => (string)($config['resultAction'] ?? self::ACTION_RESULT)]);
		$ids = json_decode((string)$this->call(config: $config, method: 'GET', path: 'messages/unprocessed?' . $query)->getBody(), true);

		if (is_array($ids) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $ids)));
	}//end unprocessedResults()

	/**
	 * The payload of one received message, gunzipped when Logius compressed it.
	 *
	 * @param string $messageId The message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The XML.
	 */
	public function payload(string $messageId, array $config): string {
		$message = json_decode((string)$this->call(config: $config, method: 'GET', path: 'messages/' . rawurlencode($messageId))->getBody(), true);
		$content = base64_decode((string)($message['dataSources'][0]['content'] ?? ''), true);
		if ($content === false) {
			return '';
		}

		// The AbonnementService result is gzipped (RFC 1952, aansluithandleiding 4.3).
		// For the BerichtVerwerkService the handleiding does not say, so both are read.
		if (str_starts_with($content, "\x1f\x8b") === true) {
			$content = (string)gzdecode($content);
		}

		return $content;
	}//end payload()

	/**
	 * Mark a received message processed.
	 *
	 * @param string $messageId The message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	public function markMessageProcessed(string $messageId, array $config): void {
		$this->call(config: $config, method: 'PATCH', path: 'messages/' . rawurlencode($messageId));
	}//end markMessageProcessed()

	/**
	 * The transport events waiting for this source.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<string,string> Message id => event type.
	 */
	public function unprocessedEvents(array $config): array {
		$query = 'cpaId=' . rawurlencode((string)($config['cpaId'] ?? ''));
		foreach (self::EVENT_TYPES as $type) {
			$query .= '&eventTypes=' . $type;
		}

		$events = json_decode((string)$this->call(config: $config, method: 'GET', path: 'events/unprocessed?' . $query)->getBody(), true);
		$byId = [];
		if (is_array($events) === false) {
			return $byId;
		}

		foreach ($events as $event) {
			if (is_array($event) === true && isset($event['messageId'], $event['type']) === true) {
				$byId[(string)$event['messageId']] = (string)$event['type'];
			}
		}

		return $byId;
	}//end unprocessedEvents()

	/**
	 * Mark a transport event processed.
	 *
	 * @param string $messageId The message id the event is about.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return void
	 */
	public function markEventProcessed(string $messageId, array $config): void {
		$this->call(config: $config, method: 'PATCH', path: 'events/' . rawurlencode($messageId));
	}//end markEventProcessed()

	/**
	 * One call to the adapter.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 * @param string $method The HTTP method.
	 * @param string $path The path below `adapterUrl`.
	 * @param array<string,mixed> $options Extra Guzzle options.
	 *
	 * @return ResponseInterface The 2xx response.
	 *
	 * @throws BerichtenboxException When the call is refused or fails.
	 */
	private function call(array $config, string $method, string $path, array $options = []): ResponseInterface {
		$url = rtrim((string)($config['adapterUrl'] ?? ''), '/') . '/' . $path;
		try {
			$this->egressGuard->assertAllowed(url: $url);
		} catch (EgressRefusedException $e) {
			throw new BerichtenboxException('The ebMS adapter may not be called: ' . $e->getMessage(), BerichtenboxException::CODE_NOT_CONFIGURED, $e);
		}

		$headers = ['Accept' => 'application/json, text/plain'];
		$token = $this->token(config: $config);
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->httpClient->request(
				$method,
				$url,
				array_merge(['headers' => $headers, 'http_errors' => false, 'timeout' => 30], $options)
			);
		} catch (GuzzleException $e) {
			throw new BerichtenboxException('The ebMS adapter cannot be reached: ' . $e->getMessage(), BerichtenboxException::CODE_TRANSPORT, $e);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$detail = trim(mb_substr(strip_tags((string)$response->getBody()), 0, 300));
			throw new BerichtenboxException(
				message: sprintf('The ebMS adapter answered HTTP %d to %s %s: %s', $status, $method, strtok($path, '?'), $detail),
				reason: BerichtenboxException::CODE_TRANSPORT
			);
		}

		return $response;
	}//end call()

	/**
	 * The adapter token, decrypted for this call, when one is configured.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return string The token, or empty.
	 */
	private function token(array $config): string {
		$encrypted = (string)($config['authentication']['encryptedToken'] ?? '');
		if ($encrypted === '') {
			return '';
		}

		try {
			return $this->crypto->decrypt($encrypted);
		} catch (Throwable $e) {
			throw new BerichtenboxException('The ebMS adapter token cannot be decrypted.', BerichtenboxException::CODE_NOT_CONFIGURED, $e);
		}
	}//end token()
}//end class
