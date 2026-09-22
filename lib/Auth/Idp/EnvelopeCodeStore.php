<?php

/**
 * Integriq EnvelopeCodeStore.
 *
 * Holds one signed envelope behind one opaque code for at most a minute. The
 * code travels back through the browser redirect, where anything in the URL
 * bar can be read, logged and shared; the envelope itself never does.
 *
 * Redemption is atomic. `IMemcache::cad()` takes the entry away in the same
 * operation that reads it, so two requests racing on one code cannot both
 * receive the envelope.
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * One-time codes for the envelope hand-off.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */
class EnvelopeCodeStore {

	/**
	 * The cache prefix.
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'integriq.idp.code';

	/**
	 * How many random bytes a code carries.
	 *
	 * @var integer
	 */
	public const CODE_BYTES = 32;

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
	 * Whether this instance can issue codes at all.
	 *
	 * @return boolean True when a shared atomic cache is available.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function isUsable(): bool {
		return $this->cache !== null;

	}//end isUsable()

	/**
	 * Put one envelope behind a fresh code.
	 *
	 * @param string $envelopeToken The signed envelope.
	 * @param string $consumer The consumer allowed to redeem it.
	 * @param integer $ttlSeconds How long the code lives, capped at 60.
	 *
	 * @return string The code, as url-safe base64.
	 *
	 * @throws IdpAssertionException When no shared cache is available or the entry cannot be written.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function issue(
		string $envelopeToken,
		string $consumer,
		int $ttlSeconds = SubjectEnvelope::MAX_TTL_SECONDS,
	): string {
		if ($this->cache === null) {
			throw new IdpAssertionException(
				message: 'No shared cache is configured, so a one-time code cannot be issued.'
			);
		}

		$lifetime = min($ttlSeconds, SubjectEnvelope::MAX_TTL_SECONDS);
		if ($lifetime < 1) {
			$lifetime = 1;
		}

		$code = rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
		$entry = (string)json_encode(['envelope' => $envelopeToken, 'consumer' => $consumer]);

		if ($this->cache->add($this->key(code: $code), $entry, $lifetime) === false) {
			throw new IdpAssertionException(
				message: 'The one-time code could not be stored, so no code is issued.'
			);
		}

		return $code;

	}//end issue()

	/**
	 * Take the envelope out from behind a code, once.
	 *
	 * @param string $code The code.
	 * @param string $consumer The consumer presenting it.
	 *
	 * @return string The signed envelope.
	 *
	 * @throws IdpAssertionException When the code is unknown, expired, already redeemed, or another consumer's.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function redeem(string $code, string $consumer): string {
		if ($this->cache === null) {
			throw new IdpAssertionException(
				message: 'No shared cache is configured, so a one-time code cannot be redeemed.'
			);
		}

		$key = $this->key(code: trim($code));
		$entry = $this->cache->get($key);

		// Take it away in the same breath as reading it. `cad` removes the
		// entry only if it still holds the value we just read, so the loser of
		// a race gets false here and sees "already redeemed", which is the
		// truth.
		if (is_string($entry) === false || $this->cache->cad($key, $entry) === false) {
			throw new IdpAssertionException(
				message: 'The one-time code is unknown, expired or already redeemed, so it is refused.'
			);
		}

		$decoded = json_decode($entry, true);
		if (is_array($decoded) === false) {
			throw new IdpAssertionException(message: 'The stored code entry is unreadable, so it is refused.');
		}

		if (hash_equals((string)($decoded['consumer'] ?? ''), $consumer) === false) {
			// The code is burnt either way: a consumer probing another
			// consumer's code must not be able to retry it.
			throw new IdpAssertionException(
				message: 'The one-time code belongs to another consumer, so it is refused.'
			);
		}

		return (string)($decoded['envelope'] ?? '');

	}//end redeem()

	/**
	 * The cache key for one code.
	 *
	 * Hashed, so a cache dump never hands anybody a usable code.
	 *
	 * @param string $code The code.
	 *
	 * @return string The key.
	 */
	private function key(string $code): string {
		return hash('sha256', $code);

	}//end key()

}//end class
