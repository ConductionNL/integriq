<?php

/**
 * Unit tests for ClientCertificateReader: the certificate the web server
 * verified is read from the server environment, a forwarding header only
 * from a Nextcloud trusted proxy, and a PKIoverheid subject yields its OIN.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Auth;

use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\Integriq\Service\Auth\ClientCertificateReader;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Auth\ClientCertificateReader
 * @uses   \OCA\Integriq\Service\Auth\ClientCertificate
 */
final class ClientCertificateReaderTest extends TestCase {

	/**
	 * A reader over a request with the given server variables and header.
	 *
	 * @param array<string,string> $server  The server variables.
	 * @param string               $header  The forwarding header's value.
	 * @param array<int,string>    $proxies Nextcloud's trusted_proxies.
	 * @param string               $name    The configured header name.
	 *
	 * @return ClientCertificateReader
	 */
	private function reader(array $server, string $header = '', array $proxies = ['10.0.0.0/8'], string $name = 'X-SSL-Client-Cert'): ClientCertificateReader {
		$request = $this->createMock(IRequest::class);
		$request->method('getEnv')->willReturnCallback(static fn (string $key): mixed => ($server[$key] ?? null));
		$request->method('getHeader')->willReturnCallback(
			static fn (string $asked): string => ($asked === $name ? $header : '')
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('trusted_proxies', [])->willReturn($proxies);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with('integriq', ClientCertificateReader::HEADER_KEY, ClientCertificateReader::DEFAULT_HEADER)
			->willReturn($name);

		return new ClientCertificateReader($request, $config, $appConfig);
	}//end reader()

	/**
	 * A PKIoverheid certificate parses to its fingerprint, subject and OIN.
	 *
	 * @return void
	 */
	public function testAPkiOverheidCertificateYieldsItsOin(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];

		$certificate = ClientCertificateReader::parse(pem: $pem);

		$this->assertInstanceOf(ClientCertificate::class, $certificate);
		$this->assertSame(strtolower((string)openssl_x509_fingerprint($pem, 'sha256')), $certificate->fingerprint);
		// OpenSSL's default config may append a state to the subject; the
		// fields the fixture names come first, in its order.
		$this->assertStringStartsWith('/C=NL/O=Gemeente Voorbeeld/CN=zaaksysteem.voorbeeld.nl/serialNumber=' . ClientCertificateFixtures::OIN, $certificate->subject);
		$this->assertStringStartsWith('/C=NL/CN=Fixture Staat der Nederlanden CA', $certificate->issuer);
		$this->assertSame(ClientCertificateFixtures::OIN, $certificate->oin);
		$this->assertTrue($certificate->isValidAt(now: time()));
		$this->assertFalse($certificate->isValidAt(now: $certificate->validTo + 1));
	}//end testAPkiOverheidCertificateYieldsItsOin()

	/**
	 * A subject without an OIN-shaped serialNumber has no OIN, and garbage is no certificate.
	 *
	 * @return void
	 */
	public function testNoOinAndNoGarbage(): void {
		$this->assertNull(ClientCertificateReader::parse(pem: ClientCertificateFixtures::pems()['plain'])->oin);
		$this->assertNull(ClientCertificateReader::parse(pem: 'not a certificate'));
		$this->assertNull(ClientCertificateReader::parse(pem: "-----BEGIN CERTIFICATE-----\n!!!\n-----END CERTIFICATE-----"));
		$this->assertNull(ClientCertificateReader::parse(pem: "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----"));
	}//end testNoOinAndNoGarbage()

	/**
	 * The certificate the web server verified is taken from the server environment, from any address.
	 *
	 * @return void
	 */
	public function testTheWebServersVerifiedCertificateIsRead(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];

		$certificate = $this->reader(server: ['SSL_CLIENT_VERIFY' => 'SUCCESS', 'SSL_CLIENT_CERT' => $pem, 'REMOTE_ADDR' => '203.0.113.9'])->fromRequest();

		$this->assertSame(ClientCertificateFixtures::OIN, $certificate?->oin);
	}//end testTheWebServersVerifiedCertificateIsRead()

	/**
	 * A certificate the web server did not verify is not read.
	 *
	 * @return void
	 */
	public function testAnUnverifiedServerCertificateIsIgnored(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];

		$this->assertNull($this->reader(server: ['SSL_CLIENT_VERIFY' => 'FAILED:unable to verify', 'SSL_CLIENT_CERT' => $pem, 'REMOTE_ADDR' => '203.0.113.9'])->fromRequest());
		$this->assertNull($this->reader(server: ['SSL_CLIENT_VERIFY' => 'NONE', 'REMOTE_ADDR' => '203.0.113.9'])->fromRequest());
	}//end testAnUnverifiedServerCertificateIsIgnored()

	/**
	 * A forged header from an address that is not a trusted proxy is ignored.
	 *
	 * @return void
	 */
	public function testAForgedHeaderFromOutsideIsIgnored(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];

		$this->assertNull($this->reader(server: ['REMOTE_ADDR' => '203.0.113.9'], header: $pem)->fromRequest());
		$this->assertNull($this->reader(server: [], header: $pem)->fromRequest());
	}//end testAForgedHeaderFromOutsideIsIgnored()

	/**
	 * Behind a trusted proxy the header is read, URL-encoded (nginx) or with spaces for newlines (Apache).
	 *
	 * @return void
	 */
	public function testTheHeaderFromATrustedProxyIsRead(): void {
		$pem = ClientCertificateFixtures::pems()['pkio'];
		$fingerprint = strtolower((string)openssl_x509_fingerprint($pem, 'sha256'));

		$nginx = $this->reader(server: ['REMOTE_ADDR' => '10.1.2.3'], header: rawurlencode($pem))->fromRequest();
		$apache = $this->reader(server: ['REMOTE_ADDR' => '10.1.2.3'], header: str_replace("\n", ' ', $pem))->fromRequest();
		$named = $this->reader(server: ['REMOTE_ADDR' => '192.0.2.10'], header: $pem, proxies: ['192.0.2.10'], name: 'X-Client-Cert')->fromRequest();

		$this->assertSame($fingerprint, $nginx?->fingerprint);
		$this->assertSame($fingerprint, $apache?->fingerprint);
		$this->assertSame($fingerprint, $named?->fingerprint);
	}//end testTheHeaderFromATrustedProxyIsRead()

	/**
	 * Trusted proxies match exactly or by CIDR range, for IPv4 and IPv6.
	 *
	 * @return void
	 */
	public function testTrustedProxyRanges(): void {
		$reader = $this->reader(server: [], proxies: ['10.0.0.0/8', '192.168.1.7', '2001:db8::/32', '172.16.0.0/12', 'garbage/x']);

		$this->assertTrue($reader->isTrustedProxy(address: '10.255.0.1'));
		$this->assertTrue($reader->isTrustedProxy(address: '192.168.1.7'));
		$this->assertTrue($reader->isTrustedProxy(address: '2001:db8:1::5'));
		$this->assertTrue($reader->isTrustedProxy(address: '172.31.255.255'));
		$this->assertFalse($reader->isTrustedProxy(address: '172.32.0.1'));
		$this->assertFalse($reader->isTrustedProxy(address: '11.0.0.1'));
		$this->assertFalse($reader->isTrustedProxy(address: '192.168.1.8'));
		$this->assertFalse($reader->isTrustedProxy(address: '2001:db9::1'));
		$this->assertFalse($reader->isTrustedProxy(address: ''));
		$this->assertFalse($reader->isTrustedProxy(address: 'not-an-ip'));
		$this->assertFalse($this->reader(server: [], proxies: [])->isTrustedProxy(address: '10.0.0.1'));
	}//end testTrustedProxyRanges()
}//end class
