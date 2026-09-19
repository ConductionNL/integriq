<?php

/**
 * Integriq DomainAlignmentChecker.
 *
 * Nothing here can make a receiver trust a domain. What it can do is look up
 * the SPF, DKIM and DMARC records for an identity's domain, say for each
 * whether it is aligned, misaligned or absent, and print the exact record to
 * publish. A failing identity still sends, with the risk shown: blocking the
 * send would break an instance the day a DNS change propagates slowly.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use DateTimeImmutable;

/**
 * Checks and reports the deliverability records of an identity's domain.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class DomainAlignmentChecker {

	/**
	 * The record is published and says what it should.
	 *
	 * @var string
	 */
	public const ALIGNED = 'aligned';

	/**
	 * The record is published and says something else.
	 *
	 * @var string
	 */
	public const MISALIGNED = 'misaligned';

	/**
	 * There is no such record.
	 *
	 * @var string
	 */
	public const ABSENT = 'absent';

	/**
	 * Constructor.
	 *
	 * @param DnsResolverInterface $resolver Reads the TXT records.
	 */
	public function __construct(private readonly DnsResolverInterface $resolver) {

	}//end __construct()

	/**
	 * Check one identity's domain.
	 *
	 * @param array<string,mixed> $identity The identity, whose address names the domain.
	 *
	 * @return array<string,mixed> Per record: its state, what is published and what to publish.
	 */
	public function check(array $identity): array {
		$domain = $this->domainOf(address: (string)($identity['address'] ?? ''));
		$selector = trim((string)($identity['dkimSelector'] ?? 'default'));
		$sendingHost = trim((string)($identity['sendingHost'] ?? ''));

		return [
			'domain' => $domain,
			'checkedAt' => (new DateTimeImmutable())->format('c'),
			'spf' => $this->checkSpf(domain: $domain, sendingHost: $sendingHost),
			'dkim' => $this->checkDkim(domain: $domain, selector: $selector),
			'dmarc' => $this->checkDmarc(domain: $domain),
		];

	}//end check()

	/**
	 * Whether a checked alignment is anything other than fully aligned.
	 *
	 * @param array<string,mixed> $alignment The result of {@see check()}.
	 *
	 * @return bool True when at least one record is absent or misaligned.
	 */
	public function isAtRisk(array $alignment): bool {
		foreach (['spf', 'dkim', 'dmarc'] as $record) {
			if ((string)($alignment[$record]['state'] ?? self::ABSENT) !== self::ALIGNED) {
				return true;
			}
		}

		return false;

	}//end isAtRisk()

	/**
	 * Check the SPF record.
	 *
	 * @param string $domain The domain.
	 * @param string $sendingHost The host mail actually leaves from, when the identity names one.
	 *
	 * @return array<string,string> The state, what is published and what to publish.
	 */
	private function checkSpf(string $domain, string $sendingHost): array {
		$published = $this->firstMatching(records: $this->resolver->txt($domain), marker: 'v=spf1');
		$include = 'include:_spf.example.org';
		if ($sendingHost !== '') {
			$include = 'include:' . $sendingHost;
		}

		$suggested = 'v=spf1 ' . $include . ' -all';

		if ($published === null) {
			return ['state' => self::ABSENT, 'published' => '', 'publish' => $domain . '. IN TXT "' . $suggested . '"'];
		}

		$aligned = true;
		if ($sendingHost !== '' && str_contains($published, $sendingHost) === false) {
			$aligned = false;
		}

		if (str_contains($published, 'all') === false) {
			$aligned = false;
		}

		$state = self::MISALIGNED;
		$publish = $domain . '. IN TXT "' . $suggested . '"';
		if ($aligned === true) {
			$state = self::ALIGNED;
			$publish = '';
		}

		return [
			'state' => $state,
			'published' => $published,
			'publish' => $publish,
		];

	}//end checkSpf()

	/**
	 * Check the DKIM record for the identity's selector.
	 *
	 * @param string $domain The domain.
	 * @param string $selector The DKIM selector.
	 *
	 * @return array<string,string> The state, what is published and what to publish.
	 */
	private function checkDkim(string $domain, string $selector): array {
		$host = $selector . '._domainkey.' . $domain;
		$published = $this->firstMatching(records: $this->resolver->txt($host), marker: 'v=DKIM1');
		$suggested = $host . '. IN TXT "v=DKIM1; k=rsa; p=<the public key of the signing key>"';

		if ($published === null) {
			return ['state' => self::ABSENT, 'published' => '', 'publish' => $suggested];
		}

		if (str_contains($published, 'p=') === false || preg_match('/p=\s*(?:;|$)/', $published) === 1) {
			return ['state' => self::MISALIGNED, 'published' => $published, 'publish' => $suggested];
		}

		return ['state' => self::ALIGNED, 'published' => $published, 'publish' => ''];

	}//end checkDkim()

	/**
	 * Check the DMARC record.
	 *
	 * @param string $domain The domain.
	 *
	 * @return array<string,string> The state, what is published and what to publish.
	 */
	private function checkDmarc(string $domain): array {
		$host = '_dmarc.' . $domain;
		$published = $this->firstMatching(records: $this->resolver->txt($host), marker: 'v=DMARC1');
		$suggested = $host . '. IN TXT "v=DMARC1; p=quarantine; rua=mailto:dmarc@' . $domain . '"';

		if ($published === null) {
			return ['state' => self::ABSENT, 'published' => '', 'publish' => $suggested];
		}

		// `p=none` publishes a record that asks the receiver to do nothing,
		// which is a policy, not an alignment. It is reported as misaligned so
		// the screen does not show a green tick for a record with no effect.
		if (preg_match('/p\s*=\s*(quarantine|reject)/i', $published) !== 1) {
			return ['state' => self::MISALIGNED, 'published' => $published, 'publish' => $suggested];
		}

		return ['state' => self::ALIGNED, 'published' => $published, 'publish' => ''];

	}//end checkDmarc()

	/**
	 * The first record starting with a marker.
	 *
	 * @param array<int,string> $records The TXT records.
	 * @param string $marker What the record starts with.
	 *
	 * @return string|null The record, or null.
	 */
	private function firstMatching(array $records, string $marker): ?string {
		foreach ($records as $record) {
			if (str_starts_with(trim($record), $marker) === true) {
				return trim($record);
			}
		}

		return null;

	}//end firstMatching()

	/**
	 * The domain of an address.
	 *
	 * @param string $address The address.
	 *
	 * @return string The domain, or an empty string.
	 */
	private function domainOf(string $address): string {
		$at = strrpos($address, '@');
		if ($at === false) {
			return '';
		}

		return strtolower(trim(substr($address, ($at + 1))));

	}//end domainOf()

}//end class
