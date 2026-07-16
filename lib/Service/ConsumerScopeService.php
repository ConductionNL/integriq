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
 * This service is the single enforcement point. It runs on the endpoint
 * runtime request path AFTER authentication resolved a consumer and BEFORE the
 * inbound rate limit, and it fails closed: a source outside a configured
 * allowlist is rejected with HTTP 403.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Enforces a consumer's configured source allowlist (`ips` / `domains`).
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class ConsumerScopeService
{

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
     * @param ICacheFactory   $cacheFactory Cache factory used to memoise DNS lookups.
     * @param LoggerInterface $logger       Logger used to record scope rejections.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->createDistributed('openconnector.consumerscope');

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
     * @param IRequest     $request  The inbound request.
     *
     * @return boolean True when the source is allowed, false when it MUST be rejected with 403.
     *
     * @spec openspec/specs/consumer-management/spec.md — Requirement: Consumer source-scope enforcement (REQ-CON-SCOPE-001)
     */
    public function isAllowed(ObjectEntity $consumer, IRequest $request): bool
    {
        $data    = $consumer->getObject();
        $ips     = ($data['ips'] ?? null);
        $domains = ($data['domains'] ?? null);

        $hasIps     = is_array($ips);
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

        if ($hasIps === true && $this->matchesIpList(clientIp: $clientIp, allowed: $ips) === true) {
            return true;
        }

        if ($hasDomains === true && $this->matchesDomainList(clientIp: $clientIp, allowed: $domains) === true) {
            return true;
        }

        $this->logger->warning(
            'OpenConnector: consumer source-scope rejected a request',
            [
                'consumer' => (string) $consumer->getUuid(),
                'clientIp' => $clientIp,
            ]
        );

        return false;

    }//end isAllowed()


    /**
     * Match the client IP against a list of exact addresses and/or CIDR ranges.
     *
     * @param string $clientIp The derived client IP.
     * @param array  $allowed  Entries: exact IPv4/IPv6 or CIDR (`10.0.0.0/8`, `2001:db8::/32`).
     *
     * @return boolean True when the client IP matches at least one entry.
     */
    private function matchesIpList(string $clientIp, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if (is_string($entry) === false || trim($entry) === '') {
                continue;
            }

            if ($this->ipMatches(clientIp: $clientIp, entry: trim($entry)) === true) {
                return true;
            }
        }

        return false;

    }//end matchesIpList()


    /**
     * Match a single allowlist entry (exact address or CIDR) against the client IP.
     *
     * @param string $clientIp The derived client IP.
     * @param string $entry    An exact IP or a CIDR range.
     *
     * @return boolean True on a match.
     */
    private function ipMatches(string $clientIp, string $entry): bool
    {
        if (str_contains($entry, '/') === false) {
            // Exact match — compare packed form so 127.0.0.1 and equivalent
            // textual spellings of the same address agree.
            $a = @inet_pton($clientIp);
            $b = @inet_pton($entry);

            return ($a !== false && $b !== false && $a === $b);
        }

        [$subnet, $bitsRaw] = explode('/', $entry, 2);

        $ip     = @inet_pton($clientIp);
        $net    = @inet_pton(trim($subnet));
        if ($ip === false || $net === false) {
            return false;
        }

        // An IPv4 client never matches an IPv6 range and vice versa.
        if (strlen($ip) !== strlen($net)) {
            return false;
        }

        if (is_numeric(trim($bitsRaw)) === false) {
            return false;
        }

        $bits    = (int) trim($bitsRaw);
        $maxBits = (strlen($net) * 8);
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        return ($this->maskBinary(addr: $ip, bits: $bits) === $this->maskBinary(addr: $net, bits: $bits));

    }//end ipMatches()


    /**
     * Zero every bit of a packed address beyond the given prefix length.
     *
     * @param string $addr Packed address from inet_pton().
     * @param int    $bits Prefix length in bits.
     *
     * @return string The masked packed address.
     */
    private function maskBinary(string $addr, int $bits): string
    {
        $bytes  = intdiv($bits, 8);
        $rest   = ($bits % 8);
        $masked = substr($addr, 0, $bytes);

        if ($rest !== 0 && $bytes < strlen($addr)) {
            $mask    = (0xFF << (8 - $rest)) & 0xFF;
            $masked .= chr(ord($addr[$bytes]) & $mask);
            $bytes++;
        }

        // Pad the remainder with zero bytes so both sides compare on equal length.
        return str_pad($masked, strlen($addr), "\0");

    }//end maskBinary()


    /**
     * Match the client IP's forward-confirmed hostname against domain patterns.
     *
     * Only forward-confirmed reverse DNS (FCrDNS) is trusted: the client IP is
     * reverse-resolved to a hostname, and that hostname is then forward-resolved
     * and MUST resolve back to the same IP. `Origin`/`Referer`/`Host` are NOT
     * used — they are caller-controlled and any non-browser client can set them
     * to anything, which would make `domains` a spoofable no-op.
     *
     * @param string $clientIp The derived client IP.
     * @param array  $allowed  Hostname patterns: exact (`api.example.com`) or
     *                         suffix wildcard (`*.example.com` / `.example.com`,
     *                         which also match the bare apex `example.com`).
     *
     * @return boolean True when a confirmed hostname matches at least one pattern.
     */
    private function matchesDomainList(string $clientIp, array $allowed): bool
    {
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

        foreach ($this->confirmedHostnames(clientIp: $clientIp) as $hostname) {
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
     * @param string $pattern  Lower-cased pattern.
     *
     * @return boolean True on a match.
     */
    private function hostnameMatches(string $hostname, string $pattern): bool
    {
        if (str_starts_with($pattern, '*.') === true) {
            $pattern = substr($pattern, 1);
        }

        if (str_starts_with($pattern, '.') === true) {
            $apex = substr($pattern, 1);

            return ($hostname === $apex || str_ends_with($hostname, $pattern) === true);
        }

        return ($hostname === $pattern);

    }//end hostnameMatches()


    /**
     * Forward-confirmed reverse-DNS hostnames for a client IP (cached).
     *
     * @param string $clientIp The derived client IP.
     *
     * @return array<int, string> Zero or more confirmed, lower-cased hostnames.
     */
    private function confirmedHostnames(string $clientIp): array
    {
        $cacheKey = 'fcrdns_'.md5($clientIp);
        $cached   = $this->cache->get($cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $hostnames = [];
        $hostname  = $this->reverseLookup(ip: $clientIp);

        if ($hostname !== null && $hostname !== $clientIp) {
            $hostname = strtolower(rtrim($hostname, '.'));

            // Forward-confirm: the hostname MUST resolve back to this exact IP.
            $forward = $this->forwardLookup(hostname: $hostname);
            foreach ($forward as $resolved) {
                if (@inet_pton($resolved) === @inet_pton($clientIp)) {
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
     * @param string $ip The client IP.
     *
     * @return string|null The PTR hostname, or null when none exists.
     */
    protected function reverseLookup(string $ip): ?string
    {
        $hostname = @gethostbyaddr($ip);
        if ($hostname === false || $hostname === '') {
            return null;
        }

        return $hostname;

    }//end reverseLookup()


    /**
     * Forward-resolve a hostname to its addresses. Seam for tests.
     *
     * @param string $hostname The PTR hostname.
     *
     * @return array<int, string> Resolved addresses (may be empty).
     */
    protected function forwardLookup(string $hostname): array
    {
        $ipv4 = @gethostbynamel($hostname);
        if ($ipv4 === false) {
            $ipv4 = [];
        }

        $ipv6 = [];
        $aaaa = @dns_get_record($hostname, DNS_AAAA);
        if (is_array($aaaa) === true) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) === true) {
                    $ipv6[] = (string) $record['ipv6'];
                }
            }
        }

        return array_merge($ipv4, $ipv6);

    }//end forwardLookup()
}//end class
