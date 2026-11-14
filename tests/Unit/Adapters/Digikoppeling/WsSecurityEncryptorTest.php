<?php

/**
 * Integriq — WS-Security body encryption tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use DOMDocument;
use DOMXPath;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WsSecurityEncryptor;
use OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;

/**
 * Encrypt after signing, decrypt before verifying; a wrong key fails with the reason (REQ-WSE-001).
 *
 * @covers \OCA\Integriq\Adapters\Digikoppeling\WsSecurityEncryptor
 * @covers \OCA\Integriq\Adapters\Digikoppeling\WusProfileService
 * @uses   \OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner
 * @uses   \OCA\Integriq\Adapters\Digikoppeling\XmlDsigLookup
 * @uses   \OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver
 *
 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
 */
class WsSecurityEncryptorTest extends TestCase {

	/**
	 * The StUF body the tests send.
	 *
	 * @var string
	 */
	private const BODY = '<stuf:npsLv01 xmlns:stuf="http://www.egem.nl/StUF/StUF0301"><stuf:bsn>999993653</stuf:bsn></stuf:npsLv01>';

	/**
	 * Keypairs by name: [certificate PEM, private key PEM].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $pairs = [];

	/**
	 * A keypair with a self-signed certificate, generated once per name.
	 *
	 * @param string $name The keypair name.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function pair(string $name): array {
		if (isset(self::$pairs[$name]) === false) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			if ($key === false) {
				$this->markTestSkipped('openssl keypair generation unavailable');
			}

			$x509 = openssl_csr_sign(openssl_csr_new(['commonName' => $name, 'organizationName' => 'Test'], $key), null, $key, 1);
			openssl_x509_export($x509, $certificate);
			openssl_pkey_export($key, $privateKey);
			self::$pairs[$name] = [(string)$certificate, (string)$privateKey];
		}

		return self::$pairs[$name];
	}//end pair()

	/**
	 * An envelope signed by the organisation.
	 *
	 * @return string
	 */
	private function signedEnvelope(): string {
		[$certificate, $key] = $this->pair(name: 'organisation');
		$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header/>'
			. '<soap:Body>' . self::BODY . '</soap:Body></soap:Envelope>';

		return (new WsSecuritySigner())->sign(soapEnvelopeXml: $envelope, certificatePem: $certificate, privateKeyPem: $key);
	}//end signedEnvelope()

	/**
	 * XPath over an envelope with the SOAP, xenc and wsse prefixes.
	 *
	 * @param string $xml The envelope.
	 *
	 * @return DOMXPath
	 */
	private function xpath(string $xml): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
		$xpath->registerNamespace('xenc', WsSecurityEncryptor::NS_XENC);
		$xpath->registerNamespace('wsse', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');
		$xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

		return $xpath;
	}//end xpath()

	/**
	 * On the wire the body is one EncryptedData, keyed for the partner ahead of the signature.
	 *
	 * @return void
	 */
	public function testTheBodyOnTheWireIsAnEncryptedData(): void {
		[$partnerCertificate] = $this->pair(name: 'partner');
		$encrypted = (new WsSecurityEncryptor())->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: $partnerCertificate);
		$xpath = $this->xpath(xml: $encrypted);

		self::assertStringNotContainsString('999993653', $encrypted);
		self::assertSame(1, $xpath->query('/soap:Envelope/soap:Body/*')->length);
		self::assertSame(1, $xpath->query('/soap:Envelope/soap:Body/xenc:EncryptedData')->length);
		self::assertSame(
			WsSecurityEncryptor::ALGORITHM_AES256_GCM,
			$xpath->evaluate('string(//xenc:EncryptedData/xenc:EncryptionMethod/@Algorithm)')
		);

		$security = $xpath->query('//wsse:Security/*');
		self::assertSame('EncryptedKey', $security->item(0)->localName, 'the key comes first: encrypted after signing');
		self::assertSame('Signature', $security->item(1)->localName);
		self::assertSame(WsSecurityEncryptor::ALGORITHM_RSA_OAEP, $xpath->evaluate('string(//xenc:EncryptedKey/xenc:EncryptionMethod/@Algorithm)'));
		self::assertSame(
			'#' . $xpath->evaluate('string(//xenc:EncryptedData/@Id)'),
			$xpath->evaluate('string(//xenc:EncryptedKey/xenc:ReferenceList/xenc:DataReference/@URI)')
		);
		self::assertSame(
			(string)openssl_x509_parse($partnerCertificate)['serialNumber'],
			$xpath->evaluate('string(//xenc:EncryptedKey//ds:X509SerialNumber)')
		);
	}//end testTheBodyOnTheWireIsAnEncryptedData()

	/**
	 * Decrypted with the partner's key, the body is back and the signature still verifies.
	 *
	 * @return void
	 */
	public function testTheSignatureVerifiesAfterDecryption(): void {
		[$partnerCertificate, $partnerKey] = $this->pair(name: 'partner');
		$encryptor = new WsSecurityEncryptor();
		$signer = new WsSecuritySigner();
		$encrypted = $encryptor->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: $partnerCertificate);

		self::assertFalse($signer->verify(signedXml: $encrypted), 'an encrypted body does not verify before decryption');

		$decrypted = $encryptor->decrypt(soapEnvelopeXml: $encrypted, privateKeyPem: $partnerKey);
		$xpath = $this->xpath(xml: $decrypted);

		self::assertTrue($signer->verify(signedXml: $decrypted, expectedCertificatePem: $this->pair(name: 'organisation')[0]));
		self::assertSame('999993653', $xpath->evaluate('string(/soap:Envelope/soap:Body/*/*)'));
		self::assertSame(0, $xpath->query('//xenc:EncryptedData|//xenc:EncryptedKey')->length);
	}//end testTheSignatureVerifiesAfterDecryption()

	/**
	 * An answer encrypted for a different key fails naming the decryption error.
	 *
	 * @return void
	 */
	public function testAnAnswerForADifferentKeyFailsWithTheReason(): void {
		[$partnerCertificate] = $this->pair(name: 'partner');
		[, $otherKey] = $this->pair(name: 'other');
		$encryptor = new WsSecurityEncryptor();
		$encrypted = $encryptor->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: $partnerCertificate);

		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('WS-Security decryption failed: the content key could not be decrypted');
		$encryptor->decrypt(soapEnvelopeXml: $encrypted, privateKeyPem: $otherKey);
	}//end testAnAnswerForADifferentKeyFailsWithTheReason()

	/**
	 * A tampered ciphertext fails; it is never passed on with an empty body.
	 *
	 * @return void
	 */
	public function testATamperedBodyFails(): void {
		[$partnerCertificate, $partnerKey] = $this->pair(name: 'partner');
		$encryptor = new WsSecurityEncryptor();
		$encrypted = $encryptor->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: $partnerCertificate);
		$xpath = $this->xpath(xml: $encrypted);
		$value = $xpath->query('//xenc:EncryptedData//xenc:CipherValue')->item(0);
		$raw = base64_decode($value->textContent);
		$raw[20] = chr((ord($raw[20]) ^ 0x01));
		$value->textContent = base64_encode($raw);

		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('the encrypted body does not decrypt with its content key');
		$encryptor->decrypt(soapEnvelopeXml: (string)$xpath->document->saveXML(), privateKeyPem: $partnerKey);
	}//end testATamperedBodyFails()

	/**
	 * An answer with an unsupported or weak algorithm is refused by name.
	 *
	 * @return void
	 */
	public function testAWeakKeyTransportIsRefused(): void {
		[$partnerCertificate, $partnerKey] = $this->pair(name: 'partner');
		$encryptor = new WsSecurityEncryptor();
		$encrypted = $encryptor->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: $partnerCertificate);
		$weak = str_replace(WsSecurityEncryptor::ALGORITHM_RSA_OAEP, 'http://www.w3.org/2001/04/xmlenc#rsa-1_5', $encrypted);

		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('unsupported key transport algorithm "http://www.w3.org/2001/04/xmlenc#rsa-1_5"');
		$encryptor->decrypt(soapEnvelopeXml: $weak, privateKeyPem: $partnerKey);
	}//end testAWeakKeyTransportIsRefused()

	/**
	 * A partner answering with AES-256-CBC (XML Encryption 1.0) is read too.
	 *
	 * @return void
	 */
	public function testAnAes256CbcAnswerDecrypts(): void {
		[$partnerCertificate, $partnerKey] = $this->pair(name: 'partner');
		$contentKey = random_bytes(32);
		$iv = random_bytes(16);
		$ciphertext = openssl_encrypt(self::BODY, 'aes-256-cbc', $contentKey, OPENSSL_RAW_DATA, $iv);
		openssl_public_encrypt($contentKey, $wrapped, openssl_pkey_get_public($partnerCertificate), OPENSSL_PKCS1_OAEP_PADDING);
		$envelope = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xenc="http://www.w3.org/2001/04/xmlenc#">'
			. '<soap:Header><wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
			. '<xenc:EncryptedKey><xenc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p"/>'
			. '<xenc:CipherData><xenc:CipherValue>' . base64_encode((string)$wrapped) . '</xenc:CipherValue></xenc:CipherData>'
			. '<xenc:ReferenceList><xenc:DataReference URI="#body"/></xenc:ReferenceList></xenc:EncryptedKey>'
			. '</wsse:Security></soap:Header><soap:Body>'
			. '<xenc:EncryptedData Id="body" Type="http://www.w3.org/2001/04/xmlenc#Content">'
			. '<xenc:EncryptionMethod Algorithm="http://www.w3.org/2001/04/xmlenc#aes256-cbc"/>'
			. '<xenc:CipherData><xenc:CipherValue>' . base64_encode($iv . $ciphertext) . '</xenc:CipherValue></xenc:CipherData>'
			. '</xenc:EncryptedData></soap:Body></soap:Envelope>';

		$decrypted = (new WsSecurityEncryptor())->decrypt(soapEnvelopeXml: $envelope, privateKeyPem: $partnerKey);

		self::assertSame('999993653', $this->xpath(xml: $decrypted)->evaluate('string(/soap:Envelope/soap:Body/*/*)'));
	}//end testAnAes256CbcAnswerDecrypts()

	/**
	 * A plain answer is returned unchanged, and recognised as not encrypted.
	 *
	 * @return void
	 */
	public function testAPlainAnswerIsLeftAlone(): void {
		$signed = $this->signedEnvelope();
		$encryptor = new WsSecurityEncryptor();

		self::assertFalse($encryptor->isEncrypted(soapEnvelopeXml: $signed));
		self::assertSame($signed, $encryptor->decrypt(soapEnvelopeXml: $signed, privateKeyPem: $this->pair(name: 'partner')[1]));
	}//end testAPlainAnswerIsLeftAlone()

	/**
	 * Encryption without a usable partner certificate is refused, not sent in clear.
	 *
	 * @return void
	 */
	public function testEncryptingWithoutACertificateIsRefused(): void {
		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('Cannot encrypt: the partner certificate is missing or invalid.');
		(new WsSecurityEncryptor())->encrypt(soapEnvelopeXml: $this->signedEnvelope(), partnerCertificatePem: '');
	}//end testEncryptingWithoutACertificateIsRefused()

	/**
	 * The WUS profile signs then encrypts, and decrypts with the brokered key before verifying.
	 *
	 * @return void
	 */
	public function testTheWusProfileSignsEncryptsAndOpensAnAnswer(): void {
		[$partnerCertificate, $partnerKey] = $this->pair(name: 'partner');
		$organisation = new WusProfileService(new WsSecuritySigner(), $this->resolver(pair: $this->pair(name: 'organisation')));

		$request = $organisation->buildSignedEncryptedRequest(
			certificateRef: 'cred-1',
			stufBodyXml: self::BODY,
			partnerCertificatePem: $partnerCertificate
		);
		self::assertStringNotContainsString('999993653', $request);

		// The partner reads it with its own key: decrypt, then the organisation's signature verifies.
		$partner = new WusProfileService(new WsSecuritySigner(), $this->resolver(pair: [$partnerCertificate, $partnerKey]));
		$opened = $partner->decryptAndVerifyResponse(certificateRef: 'cred-partner', responseXml: $request);
		self::assertStringContainsString('<stuf:bsn>999993653</stuf:bsn>', $opened);

		// The same message opened with the wrong key fails, naming the decryption error.
		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('WS-Security decryption failed');
		$organisation->decryptAndVerifyResponse(certificateRef: 'cred-1', responseXml: $request);
	}//end testTheWusProfileSignsEncryptsAndOpensAnAnswer()

	/**
	 * A resolver that answers the given keypair as the brokered material.
	 *
	 * @param array{0: string, 1: string} $pair The certificate and key.
	 *
	 * @return PkiOverheidCredentialResolver
	 */
	private function resolver(array $pair): PkiOverheidCredentialResolver {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);

		return new class($container, $pair) extends PkiOverheidCredentialResolver {
			/**
			 * Constructor.
			 *
			 * @param \Psr\Container\ContainerInterface $container The container.
			 * @param array{0: string, 1: string}       $pair      The certificate and key.
			 */
			public function __construct($container, private readonly array $pair) {
				parent::__construct($container);
			}

			/**
			 * The test keypair.
			 *
			 * @param string $certificateRef The reference.
			 *
			 * @return array{certificatePem: string, privateKeyPem: string}
			 */
			public function resolveSigningMaterial(string $certificateRef): array {
				return ['certificatePem' => $this->pair[0], 'privateKeyPem' => $this->pair[1]];
			}
		};
	}//end resolver()
}//end class
