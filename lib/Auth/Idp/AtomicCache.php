<?php

/**
 * Integriq AtomicCache.
 *
 * The cache a single-use IdP store may keep: the shared, distributed cache
 * under the store's own prefix, and only when it is an IMemcache. An
 * ArrayCache lives for one request and a non-atomic cache cannot `add()`, so a
 * store on either would accept a second use that arrives in another request
 * or races the first. Without one the store keeps none and refuses to work.
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Picks the distributed atomic cache for one IdP store.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */
final class AtomicCache {

	/**
	 * The distributed cache under a prefix, or null when there is none that is atomic.
	 *
	 * @param ICacheFactory $cacheFactory The Nextcloud cache factory.
	 * @param string        $prefix       The store's cache prefix, without the trailing dot.
	 *
	 * @return IMemcache|null The cache, or null.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
	 */
	public static function distributed(ICacheFactory $cacheFactory, string $prefix): ?IMemcache {
		if ($cacheFactory->isAvailable() === false) {
			return null;
		}

		$cache = $cacheFactory->createDistributed($prefix . '.');
		if ($cache instanceof IMemcache) {
			// `IMemcache::add()` is the atomic one. Without it, two requests
			// racing on the same value both read "not seen" and both proceed,
			// which is the second use a single-use store exists to stop.
			return $cache;
		}

		return null;

	}//end distributed()
}//end class
