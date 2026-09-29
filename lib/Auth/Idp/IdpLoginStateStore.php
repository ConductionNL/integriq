<?php

/**
 * Integriq IdpLoginStateStore.
 *
 * Holds what a login was started for, between the start and the callback:
 * the organisation, the consumer, the provider, the requested trust, the
 * return address and the consumer's relay state. The callback reads the
 * return address from here and nowhere else, which is what keeps the
 * redirect from being steerable by whoever calls the callback.
 *
 * Each state is signed under the envelope signing key, lives five minutes and
 * is taken away in the same operation that reads it, so a state answers one
 * callback and no more.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
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
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Signed, single-use initiation states.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 */
class IdpLoginStateStore {

	/**
	 * The cache prefix.
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'integriq.idp.state';

	/**
	 * How long a state lives.
	 *
	 * @var integer
	 */
	public const TTL_SECONDS = 300;

	/**
	 * The fields a state carries.
	 *
	 * @var array<int,string>
	 */
	public const FIELDS = ['nonce', 'organisation', 'consumer', 'provider', 'trust', 'returnUrl', 'relayState', 'expiresAt'];

	/**
	 * The shared cache, or null when this instance has none that qualifies.
	 *
	 * @var IMemcache|null
	 */
	private ?IMemcache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory The Nextcloud cache factory.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		if ($cacheFactory->isAvailable() === false) {
			return;
		}

		$cache = $cacheFactory->createDistributed(self::CACHE_PREFIX . '.');
		if ($cache instanceof IMemcache) {
			$this->cache = $cache;
		}

	}//end __construct()

	/**
	 * A fresh, unguessable state id.
	 *
	 * @return string The id, as url-safe base64.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function newNonce(): string {
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

	}//end newNonce()

	/**
	 * Store one state under the request id the identity provider will answer.
	 *
	 * @param string $requestId The id the assertion's `inResponseTo` will carry.
	 * @param array<string,string> $state The state, without `expiresAt`.
	 * @param string $signingKey The envelope signing key.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When no shared cache is available or the entry cannot be written.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function store(string $requestId, array $state, string $signingKey, ?int $now = null): void {
		if ($this->cache === null) {
			throw new IdpAssertionException(
				message: 'No shared cache is configured, so a login state cannot be kept and no login starts.'
			);
		}

		if (trim($requestId) === '' || $signingKey === '') {
			throw new IdpAssertionException(message: 'A login state needs a request id and a signing key.');
		}

		$payload = [];
		foreach (self::FIELDS as $field) {
			$payload[$field] = (string)($state[$field] ?? '');
		}

		$payload['expiresAt'] = (string)(($now ?? time()) + self::TTL_SECONDS);

		$body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
		$entry = (string)json_encode(['state' => $body, 'sig' => $this->sign(body: $body, signingKey: $signingKey)]);

		if ($this->cache->add($this->key(requestId: $requestId), $entry, self::TTL_SECONDS) === false) {
			throw new IdpAssertionException(message: 'The login state could not be stored, so no login starts.');
		}

	}//end store()

	/**
	 * Take the state for one request id away, once.
	 *
	 * @param string $requestId The `inResponseTo` of the assertion.
	 * @param string $signingKey The envelope signing key.
	 * @param integer|null $now The clock, injectable for tests.
	 *
	 * @return array<string,string> The state.
	 *
	 * @throws IdpAssertionException When there is no such state, it was used, it expired, or its signature fails.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function consume(string $requestId, string $signingKey, ?int $now = null): array {
		if ($this->cache === null || trim($requestId) === '') {
			throw new IdpAssertionException(message: 'The response answers no login this broker started.');
		}

		$key = $this->key(requestId: trim($requestId));
		$entry = $this->cache->get($key);

		// Read and removed in one step, as the code store does: the loser of a
		// race on the same state finds nothing.
		if (is_string($entry) === false || $this->cache->cad($key, $entry) === false) {
			throw new IdpAssertionException(message: 'The response answers no login this broker started.');
		}

		$state = $this->verified(entry: $entry, signingKey: $signingKey);

		if ((int)($state['expiresAt'] ?? 0) <= ($now ?? time())) {
			throw new IdpAssertionException(message: 'The login state has expired, so it is refused.');
		}

		$narrowed = [];
		foreach (self::FIELDS as $field) {
			$narrowed[$field] = (string)($state[$field] ?? '');
		}

		return $narrowed;

	}//end consume()

	/**
	 * The state inside one cache entry, when its signature verifies.
	 *
	 * @param string $entry The cache entry.
	 * @param string $signingKey The envelope signing key.
	 *
	 * @return array<string,mixed> The state.
	 *
	 * @throws IdpAssertionException When the signature fails or the state is unreadable.
	 */
	private function verified(string $entry, string $signingKey): array {
		$decoded = json_decode($entry, true);
		$body = (string)($decoded['state'] ?? '');
		$signature = (string)($decoded['sig'] ?? '');

		if ($signingKey === '' || hash_equals($this->sign(body: $body, signingKey: $signingKey), $signature) === false) {
			throw new IdpAssertionException(message: 'The login state does not verify, so it is refused.');
		}

		$state = json_decode($body, true);
		if (is_array($state) === false) {
			throw new IdpAssertionException(message: 'The login state is unreadable, so it is refused.');
		}

		return $state;

	}//end verified()

	/**
	 * The signature over one state body.
	 *
	 * The context string keeps a state signature from ever being mistaken for
	 * an envelope signature under the same key.
	 *
	 * @param string $body The JSON body.
	 * @param string $signingKey The key.
	 *
	 * @return string The signature, as hex.
	 */
	private function sign(string $body, string $signingKey): string {
		return hash_hmac('sha256', 'integriq-idp-login-state' . "\0" . $body, $signingKey);

	}//end sign()

	/**
	 * The cache key for one request id.
	 *
	 * @param string $requestId The request id.
	 *
	 * @return string The key.
	 */
	private function key(string $requestId): string {
		return hash('sha256', $requestId);

	}//end key()

}//end class
