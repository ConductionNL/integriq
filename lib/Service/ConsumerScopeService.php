<?php

/**
 * OpenConnector — inbound consumer source-scope (domains/ips) enforcement.
 *
 * The `consumer` schema advertises `ips` ("Allowed source IP addresses") and
 * `domains` ("Allowed source domains") as scope controls. Until this service
 * existed nothing in `lib/` read either field — they were a fabricated control
 * (the "orphaned capability" defect class): the register schema and the
 * `consumer-management` spec both promised "unlisted domain → HTTP 403" while
 * every request was in fact allowed.
 *
 * This service owns the allowlist policy and is the single enforcement point. It
 * runs on the endpoint runtime request path AFTER authentication resolved a
 * consumer and BEFORE the inbound rate limit, and it fails closed: a source
 * outside a configured allowlist is rejected with HTTP 403. Address arithmetic
 * lives in {@see IpMatcher}, hostname binding in {@see ReverseDnsResolver}.
 *
 * Client-IP derivation (security-critical): the client IP comes from
 * {@see IRequest::getRemoteAddress()} and nothing else. Nextcloud core resolves
 * that value against the instance's `trusted_proxies` + `forwarded_for_headers`
 * config, so a forwarded header is honoured only when it arrives from a proxy
 * the admin actually trusts. This service deliberately does NOT use
 * {@see SecurityService::getClientIpAddress()}, which trusts `X-Forwarded-For`
 * / `CF-Connecting-IP` unconditionally — deriving an allowlist from a
 * caller-controlled header would make the allowlist trivially spoofable, i.e.
 * would replace one fabricated control with another.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
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

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Service\Scope\IpMatcher;
use OCA\OpenConnector\Service\Scope\ReverseDnsResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Enforces a consumer's configured source allowlist (`ips` / `domains`).
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class ConsumerScopeService {
	/**
	 * Constructor.
	 *
	 * @param IpMatcher $ipMatcher Matches the client IP against exact/CIDR entries.
	 * @param ReverseDnsResolver $dnsResolver Binds the client IP to confirmed hostnames.
	 * @param LoggerInterface $logger Logger used to record scope rejections.
	 */
	public function __construct(
		private readonly IpMatcher $ipMatcher,
		private readonly ReverseDnsResolver $dnsResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Decide whether a request's source is inside the consumer's allowlist.
	 *
	 * Semantics (design.md Decision 3):
	 *  - Neither `ips` nor `domains` configured (absent, or not an array) =>
	 *    unrestricted. This preserves every pre-existing consumer's behaviour.
	 *  - At least one of them configured => the request's source MUST match at
	 *    least one entry across the union of both lists. An empty-but-present
	 *    list therefore contributes zero matching entries and does NOT
	 *    allow-all: `ips: []` on its own rejects every request.
	 *
	 * @param ObjectEntity $consumer The consumer resolved during authentication.
	 * @param IRequest $request The inbound request.
	 *
	 * @return boolean True when the source is allowed, false when it MUST be rejected with 403.
	 *
	 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
	 */
	public function isAllowed(ObjectEntity $consumer, IRequest $request): bool {
		$data = $consumer->getObject();
		$ips = ($data['ips'] ?? null);
		$domains = ($data['domains'] ?? null);

		$hasIps = is_array($ips);
		$hasDomains = is_array($domains);

		// No allowlist configured at all => unrestricted (backwards compatible).
		if ($hasIps === false && $hasDomains === false) {
			return true;
		}

		$clientIp = $request->getRemoteAddress();
		if ($clientIp === '') {
			// Fail closed: an allowlist is configured but the source is unknown.
			return false;
		}

		if ($hasIps === true && $this->ipMatcher->matchesAny(clientIp: $clientIp, allowed: $ips) === true) {
			return true;
		}

		if ($hasDomains === true && $this->matchesDomainList(clientIp: $clientIp, allowed: $domains) === true) {
			return true;
		}

		$this->logger->warning(
			'OpenConnector: consumer source-scope rejected a request',
			[
				'consumer' => (string)$consumer->getUuid(),
				'clientIp' => $clientIp,
			]
		);

		return false;
	}//end isAllowed()

	/**
	 * Match the client IP's forward-confirmed hostname against domain patterns.
	 *
	 * @param string $clientIp The derived client IP.
	 * @param array $allowed Hostname patterns: exact (`api.example.com`) or
	 *                       suffix wildcard (`*.example.com` / `.example.com`,
	 *                       which also match the bare apex `example.com`).
	 *
	 * @return boolean True when a confirmed hostname matches at least one pattern.
	 */
	private function matchesDomainList(string $clientIp, array $allowed): bool {
		$patterns = [];
		foreach ($allowed as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$patterns[] = strtolower(trim($entry));
			}
		}

		// No usable pattern => nothing can match; skip the DNS round-trip.
		if ($patterns === []) {
			return false;
		}

		foreach ($this->dnsResolver->confirmedHostnames(clientIp: $clientIp) as $hostname) {
			foreach ($patterns as $pattern) {
				if ($this->hostnameMatches(hostname: $hostname, pattern: $pattern) === true) {
					return true;
				}
			}
		}

		return false;
	}//end matchesDomainList()

	/**
	 * Match one confirmed hostname against one pattern.
	 *
	 * @param string $hostname Lower-cased, dot-trimmed confirmed hostname.
	 * @param string $pattern Lower-cased pattern.
	 *
	 * @return boolean True on a match.
	 */
	private function hostnameMatches(string $hostname, string $pattern): bool {
		if (str_starts_with($pattern, '*.') === true) {
			$pattern = substr($pattern, 1);
		}

		if (str_starts_with($pattern, '.') === true) {
			$apex = substr($pattern, 1);

			return ($hostname === $apex || str_ends_with($hostname, $pattern) === true);
		}

		return ($hostname === $pattern);
	}//end hostnameMatches()
}//end class
