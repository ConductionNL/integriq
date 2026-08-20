<?php

/**
 * OpenConnector — forward-confirmed reverse DNS (FCrDNS) for consumer source scope.
 *
 * Binds a client IP to a hostname in the only way an inbound request allows to be
 * trusted: PTR-resolve the address, then require the resulting hostname to
 * forward-resolve back to that same address. A hostile network can point its own
 * PTR at any name, but it cannot make that name resolve to its address.
 *
 * `Origin`, `Referer` and `Host` are deliberately NOT used — they are
 * caller-controlled, and matching a domain allowlist against them would produce a
 * control that stops nobody.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Scope
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Scope;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Resolves forward-confirmed reverse-DNS hostnames for a client IP.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class ReverseDnsResolver {

	/**
	 * TTL (seconds) for cached forward-confirmed reverse-DNS results.
	 *
	 * @var integer
	 */
	private const DNS_CACHE_TTL = 300;

	/**
	 * Distributed cache holding forward-confirmed reverse-DNS results.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory used to memoise DNS lookups.
	 * @param IpMatcher $ipMatcher Used to compare resolved addresses to the client IP.
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IpMatcher $ipMatcher,
	) {
		$this->cache = $cacheFactory->createDistributed('openconnector.consumerscope');

	}//end __construct()

	/**
	 * Forward-confirmed reverse-DNS hostnames for a client IP (cached).
	 *
	 * Results — including the empty result — are cached for
	 * {@see DNS_CACHE_TTL} seconds to keep DNS off the request hot path. That
	 * TTL is also the window in which a revoked DNS delegation stays honoured.
	 *
	 * @param string $clientIp The derived client IP.
	 *
	 * @return array<int, string> Zero or more confirmed, lower-cased hostnames.
	 *
	 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
	 */
	public function confirmedHostnames(string $clientIp): array {
		$cacheKey = 'fcrdns_' . md5($clientIp);
		$cached = $this->cache->get($cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$hostnames = [];
		$hostname = $this->reverseLookup(address: $clientIp);

		if ($hostname !== null && $hostname !== $clientIp) {
			$hostname = strtolower(rtrim($hostname, '.'));
			$packedClient = $this->ipMatcher->pack(value: $clientIp);

			// Forward-confirm: the hostname MUST resolve back to this exact IP.
			foreach ($this->forwardLookup(hostname: $hostname) as $resolved) {
				$packedResolved = $this->ipMatcher->pack(value: (string)$resolved);
				if ($packedClient !== null && $packedResolved === $packedClient) {
					$hostnames[] = $hostname;
					break;
				}
			}
		}

		$this->cache->set($cacheKey, $hostnames, self::DNS_CACHE_TTL);

		return $hostnames;
	}//end confirmedHostnames()

	/**
	 * Reverse-resolve an IP to a hostname. Seam for tests.
	 *
	 * The error-control operator is deliberate and cannot be validated away: a
	 * DNS failure (SERVFAIL, timeout, no resolver) raises a warning, and for a
	 * security control the correct response is "no confirmed hostname" — i.e.
	 * fail closed — not a surfaced PHP warning on the request path.
	 *
	 * @param string $address The client IP.
	 *
	 * @return string|null The PTR hostname, or null when none exists.
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)
	 *
	 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
	 */
	protected function reverseLookup(string $address): ?string {
		$hostname = @gethostbyaddr($address);
		if ($hostname === false || $hostname === '') {
			return null;
		}

		return $hostname;
	}//end reverseLookup()

	/**
	 * Forward-resolve a hostname to its addresses. Seam for tests.
	 *
	 * The error-control operator is deliberate — see {@see reverseLookup()}.
	 *
	 * @param string $hostname The PTR hostname.
	 *
	 * @return array<int, string> Resolved addresses (may be empty).
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)
	 *
	 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
	 */
	protected function forwardLookup(string $hostname): array {
		$ipv4 = @gethostbynamel($hostname);
		if ($ipv4 === false) {
			$ipv4 = [];
		}

		$ipv6 = [];
		$aaaa = @dns_get_record($hostname, DNS_AAAA);
		if (is_array($aaaa) === true) {
			foreach ($aaaa as $record) {
				if (isset($record['ipv6']) === true) {
					$ipv6[] = (string)$record['ipv6'];
				}
			}
		}

		return array_merge($ipv4, $ipv6);
	}//end forwardLookup()
}//end class
