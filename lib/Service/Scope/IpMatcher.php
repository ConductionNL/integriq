<?php

/**
 * OpenConnector — IP allowlist matching for consumer source scope.
 *
 * Matches a client address against exact IPv4/IPv6 addresses and CIDR ranges.
 * Split out of {@see \OCA\OpenConnector\Service\ConsumerScopeService} so the
 * address arithmetic is testable and reusable independently of the allowlist
 * policy that consumes it.
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

/**
 * Matches a client IP against exact-address and CIDR allowlist entries.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class IpMatcher
{
    /**
     * Pack a textual IP address, returning null for anything that is not one.
     *
     * Validating first means `inet_pton()` is only ever handed input it accepts,
     * so no error-control operator is needed to swallow a warning.
     *
     * @param string $value A candidate IPv4/IPv6 address.
     *
     * @return string|null The packed address, or null when $value is not an IP.
     *
     * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
     */
    public function pack(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }

        return $packed;

    }//end pack()

    /**
     * Match the client IP against a list of exact addresses and/or CIDR ranges.
     *
     * Entries that are not strings, are blank, or do not parse are skipped —
     * never treated as wildcards.
     *
     * @param string $clientIp The derived client IP.
     * @param array  $allowed  Entries: exact IPv4/IPv6 or CIDR (`10.0.0.0/8`, `2001:db8::/32`).
     *
     * @return boolean True when the client IP matches at least one entry.
     *
     * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
     */
    public function matchesAny(string $clientIp, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if (is_string($entry) === false || trim($entry) === '') {
                continue;
            }

            if ($this->matches(clientIp: $clientIp, entry: trim($entry)) === true) {
                return true;
            }
        }

        return false;

    }//end matchesAny()

    /**
     * Match a single allowlist entry (exact address or CIDR) against the client IP.
     *
     * @param string $clientIp The derived client IP.
     * @param string $entry    An exact IP or a CIDR range.
     *
     * @return boolean True on a match.
     */
    private function matches(string $clientIp, string $entry): bool
    {
        $packedClient = $this->pack(value: $clientIp);
        if ($packedClient === null) {
            return false;
        }

        if (str_contains($entry, '/') === false) {
            // Exact match — compare packed form so equivalent textual spellings
            // of the same address agree.
            $packedEntry = $this->pack(value: $entry);

            return ($packedEntry !== null && $packedEntry === $packedClient);
        }

        return $this->cidrMatches(packedClient: $packedClient, entry: $entry);

    }//end matches()

    /**
     * Match a packed client address against a CIDR range entry.
     *
     * @param string $packedClient The packed client address.
     * @param string $entry        A CIDR range such as `10.0.0.0/8` or `2001:db8::/32`.
     *
     * @return boolean True when the client falls inside the range.
     */
    private function cidrMatches(string $packedClient, string $entry): bool
    {
        [$subnet, $bitsRaw] = explode('/', $entry, 2);

        $net = $this->pack(value: trim($subnet));
        if ($net === null) {
            return false;
        }

        // An IPv4 client never matches an IPv6 range and vice versa.
        if (strlen($packedClient) !== strlen($net)) {
            return false;
        }

        $bitsRaw = trim($bitsRaw);
        if (is_numeric($bitsRaw) === false) {
            return false;
        }

        $bits    = (int) $bitsRaw;
        $maxBits = (strlen($net) * 8);
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        return ($this->maskBinary(addr: $packedClient, bits: $bits) === $this->maskBinary(addr: $net, bits: $bits));

    }//end cidrMatches()

    /**
     * Zero every bit of a packed address beyond the given prefix length.
     *
     * @param string  $addr Packed address from inet_pton().
     * @param integer $bits Prefix length in bits.
     *
     * @return string The masked packed address.
     */
    private function maskBinary(string $addr, int $bits): string
    {
        $bytes  = intdiv($bits, 8);
        $rest   = ($bits % 8);
        $masked = substr($addr, 0, $bytes);

        if ($rest !== 0 && $bytes < strlen($addr)) {
            $mask    = ((0xFF << (8 - $rest)) & 0xFF);
            $masked .= chr(ord($addr[$bytes]) & $mask);
        }

        // Pad the remainder with zero bytes so both sides compare on equal length.
        return str_pad($masked, strlen($addr), "\0");

    }//end maskBinary()
}//end class
