<?php

/**
 * Characterisation tests for ConnectionCertificateReader: every certificate block it answers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Event {
	use OCP\EventDispatcher\Event;

	if (class_exists(CredentialExpiryRequestedEvent::class) === false) {
		/**
		 * Stand-in for keepiq's expiry question: answers what the test scripts.
		 */
		class CredentialExpiryRequestedEvent extends Event {
			/**
			 * The scripted notAfter answer.
			 *
			 * @var mixed
			 */
			public static mixed $notAfter = null;

			/**
			 * The scripted renewUrl answer.
			 *
			 * @var mixed
			 */
			public static mixed $renewUrl = null;

			/**
			 * The questions asked, as [credentialId, organisation].
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public static array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param string $credentialId The credential.
			 * @param string $organisation The organisation.
			 */
			public function __construct(string $credentialId, string $organisation) {
				parent::__construct();
				self::$asked[] = [$credentialId, $organisation];
			}

			/**
			 * The expiry.
			 *
			 * @return mixed
			 */
			public function getNotAfter(): mixed {
				return self::$notAfter;
			}

			/**
			 * The renew url.
			 *
			 * @return mixed
			 */
			public function getRenewUrl(): mixed {
				return self::$renewUrl;
			}
		}
	}
}

namespace OCA\Integriq\Tests\Unit\Service {
	use DateTimeImmutable;
	use OCA\Integriq\Service\ConnectionCertificateReader;
	use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
	use OCA\Keepiq\Event\CredentialExpiryRequestedEvent;
	use OCP\EventDispatcher\IEventDispatcher;
	use OCP\IL10N;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;

	/**
	 * The certificate block for each place a certificate can be kept and each expiry state.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
	 */
	class ConnectionCertificateReaderTest extends TestCase {
		private const NOW = '2026-10-07T12:00:00+00:00';

		/**
		 * Every translation asked, in order.
		 *
		 * @var array<int, string>
		 */
		private array $translated = [];

		/**
		 * Reset the scripted keepiq answer.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			CredentialExpiryRequestedEvent::$notAfter = null;
			CredentialExpiryRequestedEvent::$renewUrl = null;
			CredentialExpiryRequestedEvent::$asked = [];
			$this->translated = [];
		}

		/**
		 * The reader, with the inline expiry the mTLS resolver reports.
		 *
		 * @param int|null $inlineNotAfter The resolver's notAfter, unix time.
		 *
		 * @return ConnectionCertificateReader The reader.
		 */
		private function reader(?int $inlineNotAfter): ConnectionCertificateReader {
			$mtls = $this->createMock(MtlsConfigResolver::class);
			$mtls->method('certificateNotAfter')->willReturn($inlineNotAfter);

			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(
				function (string $text, array $params = []): string {
					$this->translated[] = $text;
					return vsprintf($text, $params);
				}
			);
			$l10n->method('l')->willReturnCallback(fn (string $type, $value): string => $value->format('j F Y'));

			return new ConnectionCertificateReader($mtls, $this->createMock(IEventDispatcher::class), $l10n, $this->createMock(LoggerInterface::class));
		}

		/**
		 * A source whose mTLS certificate is a keepiq reference.
		 *
		 * @param mixed $organisation The source's organisation field.
		 *
		 * @return array<string, mixed> The source.
		 */
		private function keepiqSource(mixed $organisation): array {
			return [
				'organisation' => $organisation,
				'configuration' => ['authentication' => ['mtls' => ['certificate' => ['credentialRef' => ['credentialId' => 'k-1']]]]],
			];
		}

		/**
		 * The block as the reader answers it for a source certificate expiring at the given time.
		 *
		 * @param int $notAfter The expiry, unix time.
		 *
		 * @return array<string, mixed> The block.
		 */
		private function sourceBlock(int $notAfter): array {
			return $this->reader(inlineNotAfter: $notAfter)->certificate(data: ['configuration' => []], now: new DateTimeImmutable(self::NOW));
		}

		/**
		 * No certificate anywhere answers `configured: false`.
		 *
		 * @return void
		 */
		public function testNoCertificateIsNotConfigured(): void {
			$this->assertSame(
				['configured' => false, 'none' => true],
				$this->reader(inlineNotAfter: null)->certificate(data: ['configuration' => 'not-an-array'], now: new DateTimeImmutable(self::NOW))
			);
		}

		/**
		 * A source certificate far from expiry is valid.
		 *
		 * @return void
		 */
		public function testASourceCertificateFarFromExpiryIsValid(): void {
			$notAfter = (new DateTimeImmutable(self::NOW))->modify('+100 days')->getTimestamp();

			$this->assertSame(
				[
					'configured' => true,
					'keptIn' => 'source',
					'expiresAt' => (new DateTimeImmutable())->setTimestamp($notAfter)->format('c'),
					'daysLeft' => 100,
					'status' => 'valid',
					'statusLabel' => 'Valid',
					'notice' => null,
					'renewUrl' => null,
				],
				$this->sourceBlock(notAfter: $notAfter)
			);
			$this->assertSame(['Expiry unknown', 'Valid'], $this->translated);
		}

		/**
		 * A source certificate within the warning window is expiring, with the date in the notice.
		 *
		 * @return void
		 */
		public function testASourceCertificateInTheWarningWindowIsExpiring(): void {
			$notAfter = (new DateTimeImmutable(self::NOW))->modify('+60 days')->getTimestamp();
			$block = $this->sourceBlock(notAfter: $notAfter);

			$this->assertSame(['expiring', 'Expires soon', 60], [$block['status'], $block['statusLabel'], $block['daysLeft']]);
			$this->assertSame('Replace the certificate before 6 December 2026, or calls to this connection stop working.', $block['notice']);
		}

		/**
		 * An expired source certificate is expired, with zero days left.
		 *
		 * @return void
		 */
		public function testAnExpiredSourceCertificateIsExpired(): void {
			$notAfter = (new DateTimeImmutable(self::NOW))->modify('-3 days')->getTimestamp();
			$block = $this->sourceBlock(notAfter: $notAfter);

			$this->assertSame(['expired', 'Expired', 0], [$block['status'], $block['statusLabel'], $block['daysLeft']]);
			$this->assertSame('The certificate has expired, so calls to this connection fail. Replace it now.', $block['notice']);
		}

		/**
		 * Without an inline expiry, a PEM certificate on the source is read.
		 *
		 * @return void
		 */
		public function testAPemCertificateOnTheSourceIsRead(): void {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			$csr = openssl_csr_new(['commonName' => 'integriq-test'], $key);
			$certificate = openssl_csr_sign($csr, null, $key, 10);
			openssl_x509_export($certificate, $pem);
			$validTo = openssl_x509_parse($pem)['validTo_time_t'];
			$now = (new DateTimeImmutable())->setTimestamp($validTo)->modify('-200 days');

			$block = $this->reader(inlineNotAfter: null)->certificate(data: ['configuration' => ['cert' => $pem]], now: $now);

			$this->assertSame(['source', (new DateTimeImmutable())->setTimestamp($validTo)->format('c'), 'valid'], [$block['keptIn'], $block['expiresAt'], $block['status']]);
			$this->assertSame(
				['configured' => false, 'none' => true],
				$this->reader(inlineNotAfter: null)->certificate(data: ['configuration' => ['cert' => 'no pem here']], now: $now)
			);
		}

		/**
		 * A keepiq reference keepiq does not answer is unknown and says why.
		 *
		 * @return void
		 */
		public function testAnUnansweredKeepiqReferenceIsUnknown(): void {
			$block = $this->reader(inlineNotAfter: 1)->certificate(data: $this->keepiqSource(organisation: 42), now: new DateTimeImmutable(self::NOW));

			$this->assertSame(
				[
					'configured' => true,
					'keptIn' => 'keepiq',
					'expiresAt' => null,
					'daysLeft' => null,
					'status' => 'unknown',
					'statusLabel' => 'Expiry unknown',
					'notice' => 'Expiry unknown: keepiq did not answer.',
					'renewUrl' => null,
				],
				$block
			);
			$this->assertSame([['k-1', '']], CredentialExpiryRequestedEvent::$asked);
			$this->assertSame(['Expiry unknown: keepiq did not answer.', 'Expiry unknown'], $this->translated);
		}

		/**
		 * A keepiq answer carries its expiry and renew url.
		 *
		 * @return void
		 */
		public function testAKeepiqAnswerCarriesItsRenewUrl(): void {
			CredentialExpiryRequestedEvent::$notAfter = '2027-01-15T12:00:00+00:00';
			CredentialExpiryRequestedEvent::$renewUrl = 'https://keepiq.example/renew/k-1';

			$block = $this->reader(inlineNotAfter: null)->certificate(data: $this->keepiqSource(organisation: 'org-1'), now: new DateTimeImmutable(self::NOW));

			$this->assertSame(
				[
					'configured' => true,
					'keptIn' => 'keepiq',
					'expiresAt' => '2027-01-15T12:00:00+00:00',
					'daysLeft' => 100,
					'status' => 'valid',
					'statusLabel' => 'Valid',
					'notice' => null,
					'renewUrl' => 'https://keepiq.example/renew/k-1',
				],
				$block
			);
			$this->assertSame([['k-1', 'org-1']], CredentialExpiryRequestedEvent::$asked);
		}

		/**
		 * An unparseable keepiq expiry reads as unknown; a non-string renew url is dropped.
		 *
		 * @return void
		 */
		public function testAnUnparseableKeepiqExpiryIsUnknown(): void {
			CredentialExpiryRequestedEvent::$notAfter = 'not a date';
			CredentialExpiryRequestedEvent::$renewUrl = 17;

			$block = $this->reader(inlineNotAfter: null)->certificate(data: $this->keepiqSource(organisation: 'org-1'), now: new DateTimeImmutable(self::NOW));

			$this->assertSame(
				['configured' => true, 'keptIn' => 'keepiq', 'expiresAt' => null, 'daysLeft' => null, 'status' => 'unknown', 'statusLabel' => 'Expiry unknown', 'notice' => null, 'renewUrl' => null],
				$block
			);
		}

		/**
		 * An empty keepiq expiry is no answer.
		 *
		 * @return void
		 */
		public function testAnEmptyKeepiqExpiryIsNoAnswer(): void {
			CredentialExpiryRequestedEvent::$notAfter = '';

			$block = $this->reader(inlineNotAfter: null)->certificate(data: $this->keepiqSource(organisation: 'org-1'), now: new DateTimeImmutable(self::NOW));

			$this->assertSame(['unknown', 'Expiry unknown: keepiq did not answer.'], [$block['status'], $block['notice']]);
		}
	}
}
