<?php

/**
 * Integriq EnvelopeReplayGuard.
 *
 * Burns a single-use identifier so the second presentation of it fails. Used
 * for an envelope's `jti` and for an assertion's id.
 *
 * It refuses to work at all without a shared, atomic cache. An ArrayCache
 * lives for one request, so a replay guard on top of one accepts every replay
 * that arrives in a different request: the guard would be there, green, and
 * guarding nothing.
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
 * Makes an identifier usable exactly once.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */
class EnvelopeReplayGuard {

	/**
	 * The cache prefix.
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'integriq.idp.jti';

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
			// `IMemcache::add()` is the atomic one. Without it, two requests
			// racing on the same jti both read "not seen" and both proceed,
			// which is the replay the guard exists to stop.
			$this->cache = $cache;
		}

	}//end __construct()

	/**
	 * Whether this instance can guarantee single use.
	 *
	 * @return boolean True when a shared atomic cache is available.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
	 */
	public function isUsable(): bool {
		return $this->cache !== null;

	}//end isUsable()

	/**
	 * Use an identifier, or refuse because it has been used.
	 *
	 * @param string $jti The identifier.
	 * @param integer $ttlSeconds How long to remember it.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When the identifier is empty, already used, or cannot be guarded.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
	 */
	public function burn(string $jti, int $ttlSeconds): void {
		$identifier = trim($jti);
		if ($identifier === '') {
			throw new IdpAssertionException(
				message: 'The artefact carries no single-use identifier, so it is refused.'
			);
		}

		if ($this->cache === null) {
			// Fail-closed. An instance with no shared cache cannot promise
			// single use, and a login that silently drops replay protection
			// is worse than a login that does not happen.
			throw new IdpAssertionException(
				message: 'No shared cache is configured, so single use cannot be guaranteed and the artefact is refused.'
			);
		}

		if ($this->cache->add($this->key(identifier: $identifier), 1, $ttlSeconds) === false) {
			throw new IdpAssertionException(
				message: 'This artefact has already been used, so it is refused.'
			);
		}

	}//end burn()

	/**
	 * The cache key for one identifier.
	 *
	 * Hashed, so the cache never holds the identifier itself.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return string The key.
	 */
	private function key(string $identifier): string {
		return hash('sha256', $identifier);

	}//end key()

}//end class
