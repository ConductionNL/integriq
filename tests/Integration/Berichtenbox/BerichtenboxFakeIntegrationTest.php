<?php

/**
 * Integriq — the live Berichtenbox client, through the real transport, against the XSD-validating fake.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Integration\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration\Berichtenbox;

use GuzzleHttp\Client;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxValidatieClient;
use OCA\Integriq\Adapters\Berichtenbox\EbmsAdapterClient;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Everything below the provider is real: the letter builder, the WUS client on
 * the mTLS transport with an encrypted certificate, the ebMS adapter client over
 * HTTP. Only Logius and the adapter are the fake (tests/fake/berichtenbox), which
 * validates every letter against the vendored Logius XSD. This is a proof against
 * the fake, not against Logius.
 *
 * Skips, saying why, when python3 with lxml or the openssl CLI is absent.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-tests-and-live-proofs-run-against-a-fake-built-from-the-official-files-req-dpa-013
 */
class BerichtenboxFakeIntegrationTest extends TestCase {
	private const OIN = '00000001234567890000';

	/** @var resource|null */
	private static $process = null;

	private static string $dir = '';

	private static int $adapterPort = 0;

	private static int $wusPort = 0;

	/**
	 * Start the fake once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$probe = shell_exec('cd / && python3 -c "import lxml" 2>&1 && openssl version 2>&1');
		if (is_string($probe) === false || str_contains($probe, 'OpenSSL') === false) {
			self::markTestSkipped('The Berichtenbox fake needs python3 with lxml and the openssl CLI: ' . trim((string)$probe));
		}

		$fake = dirname(__DIR__, 2) . '/fake/berichtenbox';
		self::$dir = sys_get_temp_dir() . '/bbx-it-' . bin2hex(random_bytes(4));
		exec('sh ' . escapeshellarg($fake . '/make-ca.sh') . ' ' . escapeshellarg(self::$dir) . ' ' . self::OIN . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));

		$ready = self::$dir . '/ready';
		$command = sprintf(
			'cd / && exec python3 %s --host 127.0.0.1 --adapter-port 0 --wus-port 0 --server-cert %s --server-key %s --client-ca %s --client-oin %s --ready-file %s',
			escapeshellarg($fake . '/fake.py'),
			escapeshellarg(self::$dir . '/server.pem'),
			escapeshellarg(self::$dir . '/server.key'),
			escapeshellarg(self::$dir . '/ca.pem'),
			self::OIN,
			escapeshellarg($ready)
		);
		self::$process = proc_open($command, [1 => ['file', self::$dir . '/fake.log', 'a'], 2 => ['file', self::$dir . '/fake.log', 'a']], $pipes);

		for ($i = 0; $i < 100 && (is_file($ready) === false || filesize($ready) === 0); $i++) {
			usleep(50000);
			clearstatcache();
		}

		[$adapter, $wus] = array_map('intval', explode(' ', trim((string)file_get_contents($ready))));
		self::$adapterPort = $adapter;
		self::$wusPort = $wus;
	}//end setUpBeforeClass()

	/**
	 * Stop the fake, by its own handle.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (is_resource(self::$process) === true) {
			proc_terminate(self::$process);
			proc_close(self::$process);
		}
	}//end tearDownAfterClass()

	/**
	 * A source as the admin settings store it: the certificate encrypted.
	 *
	 * @param AesTestCrypto $crypto The crypto.
	 * @param string $certificate Which test certificate: `client` or `stranger`.
	 *
	 * @return array<string,mixed>
	 */
	private function config(AesTestCrypto $crypto, string $certificate = 'client'): array {
		return [
			'providerId' => 'berichtenbox',
			'certificateRef' => 'sha256:test',
			'senderOin' => self::OIN,
			'adapterUrl' => 'http://127.0.0.1:' . self::$adapterPort . '/service/rest/v19/ebms',
			'cpaId' => 'fake-cpa-berichtenbox',
			'toPartyId' => '00000004003214345001',
			'service' => 'urn:osb:services:GLOBE-R',
			'wusEndpoint' => 'https://localhost:' . self::$wusPort . '/BerichtenboxValidatieService',
			'berichtTypes' => ['besluit' => 'BESLUIT', 'case-update' => 'ZAAK', 'statutory' => 'ONBEKEND'],
			'authentication' => [
				'mode' => 'mtls',
				'mtls' => [
					'encryptedCertificate' => $crypto->encrypt((string)file_get_contents(self::$dir . '/' . $certificate . '.pem')),
					'encryptedPrivateKey' => $crypto->encrypt((string)file_get_contents(self::$dir . '/' . $certificate . '.key')),
					'encryptedCaBundle' => $crypto->encrypt((string)file_get_contents(self::$dir . '/ca.pem')),
				],
			],
		];
	}//end config()

	/**
	 * The provider, over the real clients.
	 *
	 * @param AesTestCrypto $crypto The crypto.
	 *
	 * @return BerichtenboxProvider
	 */
	private function provider(AesTestCrypto $crypto): BerichtenboxProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === EgressGuard::ALLOWED_HOSTS_KEY ? '127.0.0.1,localhost' : $default)
		);
		$guard = new EgressGuard($appConfig);
		$logger = $this->createMock(LoggerInterface::class);
		$http = new Client();

		$client = new BerichtenboxClientHttp(
			new BerichtenboxValidatieClient($http, new MtlsConfigResolver($crypto), new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger), $guard),
			new EbmsAdapterClient($http, $guard, $crypto),
			$logger
		);

		return new BerichtenboxProvider($client, $logger);
	}//end provider()

	/**
	 * What the fake recorded.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function recorded(): array {
		return (array)json_decode((string)file_get_contents('http://127.0.0.1:' . self::$adapterPort . '/fake/requests'), true);
	}//end recorded()

	/**
	 * A letter.
	 *
	 * @param string $bsn The recipient.
	 * @param array<string,mixed> $override Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function letter(string $bsn, array $override = []): array {
		return array_merge(
			[
				'recipient' => $bsn,
				'subject' => 'Besluit op uw aanvraag',
				'body' => "Beste heer De Vries,\n\nZie https://example.nl/zaak.",
				'category' => 'besluit',
				'caseRef' => 'Z-2026-002',
				'attachments' => [['content' => base64_encode('%PDF-1.7 test'), 'encoding' => 'base64', 'description' => 'Besluit']],
			],
			$override
		);
	}//end letter()

	/**
	 * A subscribed citizen's letter is checked, sent, validated by the fake, and delivered on its result.
	 *
	 * @return void
	 */
	public function testASubscribedLetterIsSubmittedAndItsResultRecorded(): void {
		$crypto = new AesTestCrypto();
		$provider = $this->provider($crypto);
		$config = $this->config($crypto);

		$sent = $provider->send($this->letter('999993653'), $config);
		$this->assertSame(DigitalPostResult::STATUS_SENT, $sent->getStatus(), $sent->getError());
		$this->assertFalse($sent->isSimulated());

		$reference = (string)$sent->getProviderReference();
		$posted = array_values(array_filter($this->recorded(), static fn (array $r): bool => ($r['messageId'] ?? '') === EbmsAdapterClient::messageIdFor($reference)));
		$this->assertCount(1, $posted);
		$this->assertTrue($posted[0]['xsdValid'], (string)$posted[0]['xsdErrors']);
		$this->assertStringNotContainsString('Signature', $posted[0]['payload'], 'Nothing is signed.');

		$wus = array_values(array_filter($this->recorded(), static fn (array $r): bool => ($r['face'] ?? '') === 'wus'));
		$this->assertSame(self::OIN, end($wus)['clientSerial'], 'The WUS call presented the client certificate from the mTLS transport.');
		$this->assertStringNotContainsString('wsse', end($wus)['payload']);

		$status = $this->provider($crypto);
		$result = $status->status($reference, $config);
		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $result->getStatus());
		$status->statusRecorded($reference, $config);
		$this->assertSame(DigitalPostResult::STATUS_SENT, $this->provider($crypto)->status($reference, $config)->getStatus(), 'The result was marked processed.');
	}//end testASubscribedLetterIsSubmittedAndItsResultRecorded()

	/**
	 * A citizen who is not subscribed is refused before submission.
	 *
	 * @return void
	 */
	public function testANotSubscribedCitizenIsRefusedBeforeSubmission(): void {
		$crypto = new AesTestCrypto();
		$before = count(array_filter($this->recorded(), static fn (array $r): bool => ($r['method'] ?? '') === 'POST'));

		$result = $this->provider($crypto)->send($this->letter('999990019'), $this->config($crypto));

		$this->assertSame(BerichtenboxProvider::CODE_NOT_SUBSCRIBED, $result->getCode());
		$after = count(array_filter($this->recorded(), static fn (array $r): bool => ($r['method'] ?? '') === 'POST'));
		$this->assertSame($before, $after, 'Nothing was submitted.');
	}//end testANotSubscribedCitizenIsRefusedBeforeSubmission()

	/**
	 * A BerichtType Logius does not know comes back as a failure with the Logius code.
	 *
	 * @return void
	 */
	public function testALetterLogiusRejectsSurfacesAsAFailure(): void {
		$crypto = new AesTestCrypto();
		$config = $this->config($crypto);
		$sent = $this->provider($crypto)->send($this->letter('999993653', ['category' => 'statutory']), $config);
		$this->assertSame(DigitalPostResult::STATUS_SENT, $sent->getStatus(), $sent->getError());

		$result = $this->provider($crypto)->status((string)$sent->getProviderReference(), $config);

		$this->assertSame(DigitalPostResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('BerichtTypeNietOndersteund', $result->getError());
	}//end testALetterLogiusRejectsSurfacesAsAFailure()

	/**
	 * A malformed letter that reaches the fake is rejected by its XSD check and fails.
	 *
	 * @return void
	 */
	public function testAMalformedLetterIsRejectedByTheFakeAndFails(): void {
		$crypto = new AesTestCrypto();
		$config = $this->config($crypto);
		$adapter = new EbmsAdapterClient(new Client(), new EgressGuard($this->allowLocal()), $crypto);
		$builder = new \OCA\Integriq\Adapters\Berichtenbox\BerichtenboxLetterBuilder();
		$good = $builder->build($this->letter('999993653'), self::OIN, 'BESLUIT');

		// Bypass the builder's own check, as a broken sender would.
		$broken = new \OCA\Integriq\Adapters\Berichtenbox\BerichtenboxBatch(str_replace('>Burger<', '>Bedrijf<', $good->xml), $good->batchId, $good->berichtId);
		$adapter->send($broken, $config);

		$result = $this->provider($crypto)->status($good->berichtId, $config);
		$this->assertSame(DigitalPostResult::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('XmlValidatieTegenXsdValtNegatiefUit', $result->getError());
	}//end testAMalformedLetterIsRejectedByTheFakeAndFails()

	/**
	 * A certificate from another CA cannot make the subscription check.
	 *
	 * @return void
	 */
	public function testACertificateFromAnotherCaCannotCheckSubscriptions(): void {
		$crypto = new AesTestCrypto();

		$result = $this->provider($crypto)->send($this->letter('999993653'), $this->config($crypto, 'stranger'));

		$this->assertSame(BerichtenboxException::CODE_SUBSCRIPTION_FAULT, $result->getCode());
	}//end testACertificateFromAnotherCaCannotCheckSubscriptions()

	/**
	 * A letter with no result stays sent.
	 *
	 * @return void
	 */
	public function testALetterWithoutAResultStaysSent(): void {
		$crypto = new AesTestCrypto();
		$config = $this->config($crypto);
		$sent = $this->provider($crypto)->send($this->letter('999990032'), $config);

		$this->assertSame(DigitalPostResult::STATUS_SENT, $this->provider($crypto)->status((string)$sent->getProviderReference(), $config)->getStatus());
	}//end testALetterWithoutAResultStaysSent()

	/**
	 * An app config that allows the fake's loopback host.
	 *
	 * @return IAppConfig
	 */
	private function allowLocal(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('127.0.0.1,localhost');

		return $appConfig;
	}//end allowLocal()
}//end class
