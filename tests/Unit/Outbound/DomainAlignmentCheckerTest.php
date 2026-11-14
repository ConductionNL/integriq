<?php

/**
 * Unit tests for DomainAlignmentChecker.
 *
 * Drives the SPF, DKIM and DMARC checks through a recorded DNS resolver: which
 * hosts are asked, which TXT record is taken, when a published record counts
 * as aligned, the record suggested otherwise, and when an identity is at risk.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\DnsResolverInterface;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use PHPUnit\Framework\TestCase;

/**
 * SPF/DKIM/DMARC alignment of a sender identity's domain.
 *
 * @covers \OCA\Integriq\Outbound\Identity\DomainAlignmentChecker
 *
 * @spec openspec/specs/outbound-sender-identity/spec.md
 */
final class DomainAlignmentCheckerTest extends TestCase {

	/**
	 * A resolver answering from a fixed table and recording every host asked.
	 *
	 * @param array<string, array<int, string>> $records TXT records per host.
	 *
	 * @return DnsResolverInterface&object{asked: array<int, string>}
	 */
	private function resolver(array $records): DnsResolverInterface {
		return new class($records) implements DnsResolverInterface {
			/**
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * @param array<string, array<int, string>> $records TXT records per host.
			 */
			public function __construct(private readonly array $records) {
			}

			/**
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			public function txt(string $host): array {
				$this->asked[] = $host;
				return ($this->records[$host] ?? []);
			}
		};
	}//end resolver()

	/**
	 * Check one identity against the given records.
	 *
	 * @param array<string, array<int, string>> $records  TXT records per host.
	 * @param array<string, mixed>              $identity The identity.
	 *
	 * @return array<string, mixed>
	 */
	private function check(array $records, array $identity = ['address' => 'noreply@gemeente.nl']): array {
		return (new DomainAlignmentChecker($this->resolver($records)))->check($identity);
	}//end check()

	/**
	 * Every record absent: three suggestions to publish, and the hosts asked.
	 *
	 * @return void
	 */
	public function testNothingPublishedSuggestsAllThreeRecords(): void {
		$resolver = $this->resolver([]);
		$result = (new DomainAlignmentChecker($resolver))->check(['address' => 'Noreply@Gemeente.NL ']);

		$this->assertSame('gemeente.nl', $result['domain']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result['checkedAt']);
		$this->assertSame(['gemeente.nl', 'default._domainkey.gemeente.nl', '_dmarc.gemeente.nl'], $resolver->asked);
		$this->assertSame(
			['state' => 'absent', 'published' => '', 'publish' => 'gemeente.nl. IN TXT "v=spf1 include:_spf.example.org -all"'],
			$result['spf']
		);
		$this->assertSame(
			['state' => 'absent', 'published' => '', 'publish' => 'default._domainkey.gemeente.nl. IN TXT "v=DKIM1; k=rsa; p=<the public key of the signing key>"'],
			$result['dkim']
		);
		$this->assertSame(
			['state' => 'absent', 'published' => '', 'publish' => '_dmarc.gemeente.nl. IN TXT "v=DMARC1; p=quarantine; rua=mailto:dmarc@gemeente.nl"'],
			$result['dmarc']
		);
	}//end testNothingPublishedSuggestsAllThreeRecords()

	/**
	 * A complete, correct set of records is aligned and not at risk.
	 *
	 * @return void
	 */
	public function testACorrectSetIsAlignedAndNotAtRisk(): void {
		$checker = new DomainAlignmentChecker(
			$this->resolver(
				[
					'gemeente.nl' => ['google-site-verification=abc', '  v=spf1 include:mail.provider.nl -all  '],
					'mail2026._domainkey.gemeente.nl' => ['v=DKIM1; k=rsa; p=MIIBIjANBg'],
					'_dmarc.gemeente.nl' => ['v=DMARC1; P = Reject; rua=mailto:d@gemeente.nl'],
				]
			)
		);

		$result = $checker->check(['address' => 'noreply@gemeente.nl', 'dkimSelector' => ' mail2026 ', 'sendingHost' => 'mail.provider.nl']);

		$this->assertSame(['state' => 'aligned', 'published' => 'v=spf1 include:mail.provider.nl -all', 'publish' => ''], $result['spf']);
		$this->assertSame(['state' => 'aligned', 'published' => 'v=DKIM1; k=rsa; p=MIIBIjANBg', 'publish' => ''], $result['dkim']);
		$this->assertSame('aligned', $result['dmarc']['state']);
		$this->assertFalse($checker->isAtRisk($result));
	}//end testACorrectSetIsAlignedAndNotAtRisk()

	/**
	 * An SPF record that omits the sending host is misaligned and names the host to include.
	 *
	 * @return void
	 */
	public function testSpfWithoutTheSendingHostIsMisaligned(): void {
		$result = $this->check(['gemeente.nl' => ['v=spf1 include:other.nl -all']], ['address' => 'a@gemeente.nl', 'sendingHost' => 'mail.provider.nl']);

		$this->assertSame(
			['state' => 'misaligned', 'published' => 'v=spf1 include:other.nl -all', 'publish' => 'gemeente.nl. IN TXT "v=spf1 include:mail.provider.nl -all"'],
			$result['spf']
		);
	}//end testSpfWithoutTheSendingHostIsMisaligned()

	/**
	 * An SPF record without an `all` mechanism is misaligned even with no host to check.
	 *
	 * @return void
	 */
	public function testSpfWithoutAnAllMechanismIsMisaligned(): void {
		$this->assertSame('misaligned', $this->check(['gemeente.nl' => ['v=spf1 include:x.nl']])['spf']['state']);
		$this->assertSame('aligned', $this->check(['gemeente.nl' => ['v=spf1 include:x.nl ~all']])['spf']['state']);
	}//end testSpfWithoutAnAllMechanismIsMisaligned()

	/**
	 * A DKIM record without a key, or with an empty one, is misaligned.
	 *
	 * @param string $record The published DKIM record.
	 *
	 * @dataProvider unusableDkimProvider
	 *
	 * @return void
	 */
	public function testADkimRecordWithoutAKeyIsMisaligned(string $record): void {
		$result = $this->check(['default._domainkey.gemeente.nl' => [$record]]);

		$this->assertSame('misaligned', $result['dkim']['state']);
		$this->assertSame(trim($record), $result['dkim']['published']);
		$this->assertSame('default._domainkey.gemeente.nl. IN TXT "v=DKIM1; k=rsa; p=<the public key of the signing key>"', $result['dkim']['publish']);
	}//end testADkimRecordWithoutAKeyIsMisaligned()

	/**
	 * DKIM records that carry no usable key.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unusableDkimProvider(): array {
		return [
			'no p tag' => ['v=DKIM1; k=rsa'],
			'empty p before another tag' => ['v=DKIM1; p=; k=rsa'],
			'empty p at the end' => ['v=DKIM1; k=rsa; p='],
			'blank p at the end' => ['v=DKIM1; k=rsa; p=  '],
		];
	}//end unusableDkimProvider()

	/**
	 * A DMARC policy of none, or none stated, is misaligned; quarantine is aligned.
	 *
	 * @return void
	 */
	public function testOnlyAnEnforcingDmarcPolicyIsAligned(): void {
		$none = $this->check(['_dmarc.gemeente.nl' => ['v=DMARC1; p=none']])['dmarc'];
		$this->assertSame('misaligned', $none['state']);
		$this->assertSame('v=DMARC1; p=none', $none['published']);
		$this->assertSame('_dmarc.gemeente.nl. IN TXT "v=DMARC1; p=quarantine; rua=mailto:dmarc@gemeente.nl"', $none['publish']);

		$this->assertSame('misaligned', $this->check(['_dmarc.gemeente.nl' => ['v=DMARC1; rua=mailto:x@y.nl']])['dmarc']['state']);
		$this->assertSame('aligned', $this->check(['_dmarc.gemeente.nl' => ['v=DMARC1; p=quarantine']])['dmarc']['state']);
	}//end testOnlyAnEnforcingDmarcPolicyIsAligned()

	/**
	 * Only records that start with the marker count; the first one wins.
	 *
	 * @return void
	 */
	public function testOnlyTheFirstRecordStartingWithTheMarkerIsRead(): void {
		$result = $this->check(['_dmarc.gemeente.nl' => ['x v=DMARC1; p=reject', 'v=DMARC1; p=none', 'v=DMARC1; p=reject']]);

		$this->assertSame('v=DMARC1; p=none', $result['dmarc']['published']);
	}//end testOnlyTheFirstRecordStartingWithTheMarkerIsRead()

	/**
	 * An address without an @ has no domain; the last @ decides otherwise.
	 *
	 * @return void
	 */
	public function testTheDomainIsTakenAfterTheLastAt(): void {
		$this->assertSame('', $this->check([], ['address' => 'not-an-address'])['domain']);
		$this->assertSame('', $this->check([], [])['domain']);
		$this->assertSame('mail.gemeente.nl', $this->check([], ['address' => '"a@b"@Mail.Gemeente.nl'])['domain']);
	}//end testTheDomainIsTakenAfterTheLastAt()

	/**
	 * Any record that is not aligned, or missing, puts the identity at risk.
	 *
	 * @return void
	 */
	public function testAnyUnalignedOrMissingRecordIsAtRisk(): void {
		$checker = new DomainAlignmentChecker($this->resolver([]));
		$aligned = ['state' => 'aligned'];

		$this->assertFalse($checker->isAtRisk(['spf' => $aligned, 'dkim' => $aligned, 'dmarc' => $aligned]));
		$this->assertTrue($checker->isAtRisk(['spf' => $aligned, 'dkim' => ['state' => 'misaligned'], 'dmarc' => $aligned]));
		$this->assertTrue($checker->isAtRisk(['spf' => $aligned, 'dkim' => $aligned]));
		$this->assertTrue($checker->isAtRisk([]));
	}//end testAnyUnalignedOrMissingRecordIsAtRisk()
}//end class
