<?php

/**
 * Integriq Egress Guard.
 *
 * Refuses an outbound URL that points into the instance's own network before an
 * HTTP call is made to it: the app-local form of the egress guard hydra ADR-067
 * decision 3 describes, until OpenRegister ships the shared `EgressGuard`. The
 * rules are the ones `openspec/changes/events-async-api-products/design.md`
 * gives a caller-chosen sink:
 *
 * - `https` only;
 * - never a loopback, private, carrier-grade NAT, link-local, unspecified,
 *   multicast or reserved address, checked on every address the host resolves to;
 * - never a cloud metadata endpoint, by name or by address.
 *
 * An administrator can allow a known internal receiver by listing its host name
 * in the `egress_allowed_hosts` app config (comma separated). A listed host may
 * use `http` and may resolve to a private or loopback address. It may never be a
 * link-local or metadata address: no receiver legitimately lives there, and it is
 * where cloud credentials are served.
 *
 * Nextcloud's HTTP client refuses local addresses on its own unless
 * `allow_local_remote_servers` is set. This guard does not read that setting: an
 * instance that allows local servers for other apps has not thereby allowed an
 * event subscription to post into its network.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Exception\EgressRefusedException;
use OCP\IAppConfig;

/**
 * Decides whether an outbound URL may be called.
 *
 * @spec openspec/changes/events-async-api-products/design.md
 */
class EgressGuard {

	/**
	 * App config key holding the comma-separated list of allowed internal hosts.
	 *
	 * @var string
	 */
	public const ALLOWED_HOSTS_KEY = 'egress_allowed_hosts';

	/**
	 * Host names that serve cloud instance metadata.
	 *
	 * @var array<int, string>
	 */
	private const METADATA_HOSTS = [
		'metadata',
		'metadata.google.internal',
		'metadata.goog',
		'instance-data',
		'instance-data.ec2.internal',
	];

	/**
	 * Ranges refused even for an allowed host: link-local (where AWS, Azure and
	 * GCP serve metadata on 169.254.169.254) and the AWS IPv6 metadata address.
	 *
	 * @var array<int, string>
	 */
	private const ALWAYS_REFUSED_RANGES = [
		'169.254.0.0/16',
		'fe80::/10',
		'fd00:ec2::254/128',
	];

	/**
	 * Ranges refused unless the host is on the allowlist.
	 *
	 * @var array<int, string>
	 */
	private const INTERNAL_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'fc00::/7',
		'ff00::/8',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig App config holding the allowlist; without
	 *                                   it no host is allowed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Refuse an outbound URL that may not be called.
	 *
	 * @param string $url The URL about to be called.
	 *
	 * @return void
	 *
	 * @throws EgressRefusedException When the URL is refused; the message names
	 *                                the rule and the host, never a credential.
	 *
	 * @spec openspec/changes/events-async-api-products/design.md
	 */
	public function assertAllowed(string $url): void {
		$parts = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

		if ($host === '') {
			throw new EgressRefusedException(message: 'The URL has no host.');
		}

		$allowed = $this->isAllowedHost(host: $host);

		if ($scheme !== 'https' && ($scheme !== 'http' || $allowed === false)) {
			throw new EgressRefusedException(
				message: 'Only https URLs may be called; "' . $host . '" is not on the '
					. self::ALLOWED_HOSTS_KEY . ' list that permits http.'
			);
		}

		if (in_array(rtrim($host, '.'), self::METADATA_HOSTS, true) === true) {
			throw new EgressRefusedException(message: 'The host "' . $host . '" is a cloud metadata endpoint.');
		}

		$this->assertAddressesAllowed(host: $host, allowed: $allowed);
	}//end assertAllowed()

	/**
	 * Refuse a host whose addresses fall in a refused range.
	 *
	 * A link-local or metadata address is always refused; a loopback, private
	 * or reserved address only when the host is not on the allow list.
	 *
	 * @param string  $host    The lower-cased host, without IPv6 brackets.
	 * @param boolean $allowed Whether the administrator allowed this host.
	 *
	 * @return void
	 *
	 * @throws EgressRefusedException When an address is refused.
	 *
	 * @spec openspec/changes/events-async-api-products/design.md
	 */
	private function assertAddressesAllowed(string $host, bool $allowed): void {
		foreach ($this->addressesOf(host: $host) as $address) {
			if ($this->inAnyRange(address: $address, ranges: self::ALWAYS_REFUSED_RANGES) === true) {
				throw new EgressRefusedException(
					message: 'The host "' . $host . '" resolves to a link-local or metadata address.'
				);
			}

			if ($allowed === false && $this->inAnyRange(address: $address, ranges: self::INTERNAL_RANGES) === true) {
				throw new EgressRefusedException(
					message: 'The host "' . $host . '" resolves to a loopback, private or reserved address. '
						. 'Add it to the ' . self::ALLOWED_HOSTS_KEY . ' app config to allow it.'
				);
			}
		}
	}//end assertAddressesAllowed()

	/**
	 * Every IP address a host stands for: the host itself when it is a literal,
	 * otherwise its A and AAAA records.
	 *
	 * A host that resolves to nothing yields no address and is not refused here:
	 * there is nothing to judge, and the call fails on its own.
	 *
	 * @param string $host The lower-cased host, without IPv6 brackets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/events-async-api-products/design.md
	 */
	protected function addressesOf(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$addresses = [];
		$ipv4 = gethostbynamel($host);
		if (is_array($ipv4) === true) {
			$addresses = $ipv4;
		}

		// The dns_get_record() call warns on a failed lookup; that is not an
		// error here, so the warning is swallowed for this one call.
		set_error_handler(static fn (): bool => true);
		try {
			$ipv6 = dns_get_record($host, DNS_AAAA);
		} finally {
			restore_error_handler();
		}

		if (is_array($ipv6) === true) {
			foreach ($ipv6 as $record) {
				if (isset($record['ipv6']) === true) {
					$addresses[] = (string)$record['ipv6'];
				}
			}
		}

		return $addresses;
	}//end addressesOf()

	/**
	 * Whether the administrator listed this host as an allowed internal receiver.
	 *
	 * @param string $host The lower-cased host.
	 *
	 * @return boolean
	 */
	private function isAllowedHost(string $host): bool {
		if ($this->appConfig === null) {
			return false;
		}

		$list = $this->appConfig->getValueString(Application::APP_ID, self::ALLOWED_HOSTS_KEY, '');
		foreach (explode(',', $list) as $entry) {
			if (strtolower(trim($entry)) === $host && $host !== '') {
				return true;
			}
		}

		return false;
	}//end isAllowedHost()

	/**
	 * Whether an address falls in one of the given CIDR ranges.
	 *
	 * An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`) is judged as the IPv4
	 * address it carries.
	 *
	 * @param string $address An IPv4 or IPv6 address.
	 * @param array<int, string> $ranges CIDR ranges.
	 *
	 * @return boolean
	 */
	private function inAnyRange(string $address, array $ranges): bool {
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			return false;
		}

		$packed = inet_pton($address);
		if ($packed === false) {
			return false;
		}

		if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") === true) {
			$packed = substr($packed, 12);
		}

		foreach ($ranges as $range) {
			[$subnet, $bits] = explode('/', $range);
			$subnetPacked = inet_pton($subnet);
			if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
				continue;
			}

			if ($this->prefixMatches(left: $packed, right: $subnetPacked, bits: (int)$bits) === true) {
				return true;
			}
		}

		return false;
	}//end inAnyRange()

	/**
	 * Whether the first `$bits` bits of two packed addresses are equal.
	 *
	 * @param string $left A packed address.
	 * @param string $right A packed address of the same length.
	 * @param integer $bits The prefix length.
	 *
	 * @return boolean
	 */
	private function prefixMatches(string $left, string $right, int $bits): bool {
		$fullBytes = intdiv($bits, 8);
		if (substr($left, 0, $fullBytes) !== substr($right, 0, $fullBytes)) {
			return false;
		}

		$remainder = ($bits % 8);
		if ($remainder === 0) {
			return true;
		}

		$mask = ((0xff << (8 - $remainder)) & 0xff);

		return ((ord($left[$fullBytes]) & $mask) === (ord($right[$fullBytes]) & $mask));
	}//end prefixMatches()
}//end class
