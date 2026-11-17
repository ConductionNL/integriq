<?php

/**
 * Integriq OidcLoginStore.
 *
 * What an OIDC login has to remember between the redirect to the issuer and
 * the callback: the `nonce` the ID token must carry and the PKCE code
 * verifier the token request proves possession with. Kept in the distributed
 * cache under the login's `state`, for as long as a login state lives, and
 * readable once.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * The nonce and PKCE verifier of each outstanding OIDC login, single use.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class OidcLoginStore {

	/**
	 * The cache prefix.
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'integriq.idp.oidc';

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
		$this->cache = AtomicCache::distributed(cacheFactory: $cacheFactory, prefix: self::CACHE_PREFIX);

	}//end __construct()

	/**
	 * Remember a login's nonce and code verifier under its state.
	 *
	 * @param string $state    The `state` the issuer sends back.
	 * @param string $nonce    The nonce the ID token must carry.
	 * @param string $verifier The PKCE code verifier.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When there is no shared cache, or the state is already in use.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function remember(string $state, string $nonce, string $verifier): void {
		if ($this->cache === null) {
			throw new IdpAssertionException(message: 'No shared cache is available to hold the login, so no login starts.');
		}

		$entry = json_encode(['nonce' => $nonce, 'verifier' => $verifier], JSON_THROW_ON_ERROR);
		if ($this->cache->add(self::key(state: $state), $entry, IdpLoginStateStore::TTL_SECONDS) !== true) {
			throw new IdpAssertionException(message: 'The login state is already in use, so no login starts.');
		}

	}//end remember()

	/**
	 * Take a login's nonce and code verifier; a second take of the same state finds nothing.
	 *
	 * @param string $state The `state` the callback carried.
	 *
	 * @return array{nonce: string, verifier: string}
	 *
	 * @throws IdpAssertionException When no login is outstanding under this state.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function take(string $state): array {
		if ($this->cache === null) {
			throw new IdpAssertionException(message: 'No shared cache is available to read the login, so it is refused.');
		}

		$key = self::key(state: $state);
		$raw = $this->cache->get($key);
		$this->cache->remove($key);

		$entry = null;
		if (is_string($raw) === true) {
			$entry = json_decode($raw, true);
		}

		$nonce = ($entry['nonce'] ?? null);
		$verifier = ($entry['verifier'] ?? null);
		if (is_string($nonce) === false || $nonce === '' || is_string($verifier) === false || $verifier === '') {
			throw new IdpAssertionException(message: 'The response answers no outstanding login, so it is refused.');
		}

		return ['nonce' => $nonce, 'verifier' => $verifier];

	}//end take()

	/**
	 * The cache key of a state: its hash, so the key never carries the state itself.
	 *
	 * @param string $state The state.
	 *
	 * @return string
	 */
	private static function key(string $state): string {
		return hash('sha256', $state);

	}//end key()

}//end class
