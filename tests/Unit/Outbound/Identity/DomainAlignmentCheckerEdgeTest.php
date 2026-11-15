<?php

/**
 * Unit tests for the edges of DomainAlignmentChecker: a fully aligned domain,
 * an SPF record without an `all` mechanism, DKIM records with and without a
 * public key, and an identity address without a domain.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\DnsResolverInterface;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the record states DomainAlignmentChecker reports.
 *
 * @covers \OCA\Integriq\Outbound\Identity\DomainAlignmentChecker
 */
class DomainAlignmentCheckerEdgeTest extends TestCase {

	/**
	 * Hosts asked for, in order.
	 *
	 * @var array<int,string>
	 */
	private array $asked = [];

	/**
	 * A checker over a fixed set of TXT records.
	 *
	 * @param array<string,array<int,string>> $records TXT records per host.
	 *
	 * @return DomainAlignmentChecker The checker.
	 */
	private function checker(array $records): DomainAlignmentChecker {
		$resolver = $this->createMock(DnsResolverInterface::class);
		$resolver->method('txt')->willReturnCallback(
			function (string $host) use ($records): array {
				$this->asked[] = $host;
				return ($records[$host] ?? []);
			}
		);

		return new DomainAlignmentChecker($resolver);

	}//end checker()

	/**
	 * A domain whose three records all say what they should is aligned and not at risk.
	 *
	 * @return void
	 */
	public function testAFullyAlignedDomainIsNotAtRisk(): void {
		$checker = $this->checker(
			[
				'gemeente.nl' => ['some-other=record', ' v=spf1 include:mail.gemeente.nl -all '],
				'mail2026._domainkey.gemeente.nl' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkq'],
				'_dmarc.gemeente.nl' => ['v=DMARC1; p=reject; rua=mailto:d@gemeente.nl'],
			]
		);

		$result = $checker->check(['address' => 'Info@Gemeente.NL', 'dkimSelector' => ' mail2026 ', 'sendingHost' => 'mail.gemeente.nl']);

		$this->assertSame('gemeente.nl', $result['domain']);
		$this->assertSame(
			['state' => 'aligned', 'published' => 'v=spf1 include:mail.gemeente.nl -all', 'publish' => ''],
			$result['spf']
		);
		$this->assertSame(
			['state' => 'aligned', 'published' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkq', 'publish' => ''],
			$result['dkim']
		);
		$this->assertSame('aligned', $result['dmarc']['state']);
		$this->assertSame('', $result['dmarc']['publish']);
		$this->assertFalse($checker->isAtRisk($result));
		$this->assertSame(['gemeente.nl', 'mail2026._domainkey.gemeente.nl', '_dmarc.gemeente.nl'], $this->asked);

	}//end testAFullyAlignedDomainIsNotAtRisk()

	/**
	 * An SPF record that never ends in an `all` mechanism is misaligned, with the record to publish.
	 *
	 * @return void
	 */
	public function testAnSpfRecordWithoutAnAllMechanismIsMisaligned(): void {
		$checker = $this->checker(['gemeente.nl' => ['v=spf1 include:mail.gemeente.nl']]);

		$spf = $checker->check(['address' => 'info@gemeente.nl', 'sendingHost' => 'mail.gemeente.nl'])['spf'];

		$this->assertSame('misaligned', $spf['state']);
		$this->assertSame('v=spf1 include:mail.gemeente.nl', $spf['published']);
		$this->assertSame('gemeente.nl. IN TXT "v=spf1 include:mail.gemeente.nl -all"', $spf['publish']);

	}//end testAnSpfRecordWithoutAnAllMechanismIsMisaligned()

	/**
	 * A DKIM record with an empty public key is misaligned and the default selector is used.
	 *
	 * @return void
	 */
	public function testADkimRecordWithAnEmptyKeyIsMisaligned(): void {
		$checker = $this->checker(['default._domainkey.gemeente.nl' => ['v=DKIM1; k=rsa; p=;']]);

		$result = $checker->check(['address' => 'info@gemeente.nl']);

		$this->assertSame(
			[
				'state' => 'misaligned',
				'published' => 'v=DKIM1; k=rsa; p=;',
				'publish' => 'default._domainkey.gemeente.nl. IN TXT "v=DKIM1; k=rsa; p=<the public key of the signing key>"',
			],
			$result['dkim']
		);
		$this->assertTrue($checker->isAtRisk($result));

	}//end testADkimRecordWithAnEmptyKeyIsMisaligned()

	/**
	 * A DKIM record that has no `p=` tag at all is misaligned.
	 *
	 * @return void
	 */
	public function testADkimRecordWithoutAKeyTagIsMisaligned(): void {
		$checker = $this->checker(['default._domainkey.gemeente.nl' => ['v=DKIM1; k=rsa']]);

		$this->assertSame('misaligned', $checker->check(['address' => 'info@gemeente.nl'])['dkim']['state']);

	}//end testADkimRecordWithoutAKeyTagIsMisaligned()

	/**
	 * An address without an `@` names no domain, and an absent SPF suggests the placeholder include.
	 *
	 * @return void
	 */
	public function testAnAddressWithoutADomainChecksAnEmptyDomain(): void {
		$checker = $this->checker([]);

		$result = $checker->check(['address' => 'not-an-address']);

		$this->assertSame('', $result['domain']);
		$this->assertSame(
			['state' => 'absent', 'published' => '', 'publish' => '. IN TXT "v=spf1 include:_spf.example.org -all"'],
			$result['spf']
		);
		$this->assertSame(['', 'default._domainkey.', '_dmarc.'], $this->asked);

	}//end testAnAddressWithoutADomainChecksAnEmptyDomain()

	/**
	 * A record missing from the checked alignment counts as at risk.
	 *
	 * @return void
	 */
	public function testAMissingRecordInTheAlignmentIsAtRisk(): void {
		$checker = $this->checker([]);

		$this->assertTrue($checker->isAtRisk(['spf' => ['state' => 'aligned'], 'dkim' => ['state' => 'aligned']]));

	}//end testAMissingRecordInTheAlignmentIsAtRisk()

}//end class
