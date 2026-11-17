<?php

/**
 * Integriq — WS-Security encryption: the envelopes and answers it refuses, and the header it adds.
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
use OCA\Integriq\Adapters\Digikoppeling\WsSecurityEncryptor;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;

/**
 * Every answer that cannot be decrypted fails naming why, never with an empty
 * Body; an envelope without a Header gets one for the key (REQ-WSE-001).
 *
 * @covers \OCA\Integriq\Adapters\Digikoppeling\WsSecurityEncryptor
 *
 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
 */
class WsSecurityEncryptorRefusalsTest extends TestCase {

	/**
	 * The StUF body the answers carry.
	 *
	 * @var string
	 */
	private const BODY = '<stuf:npsLa01 xmlns:stuf="http://www.egem.nl/StUF/StUF0301"><stuf:bsn>999993653</stuf:bsn></stuf:npsLa01>';

	/**
	 * AES-256-CBC (XML Encryption 1.0).
	 *
	 * @var string
	 */
	private const CBC = 'http://www.w3.org/2001/04/xmlenc#aes256-cbc';

	/**
	 * The partner keypair: [certificate PEM, private key PEM].
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private static ?array $pair = null;

	/**
	 * The partner's keypair with a self-signed certificate, generated once.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function pair(): array {
		if (self::$pair === null) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			if ($key === false) {
				$this->markTestSkipped('openssl keypair generation unavailable');
			}

			$x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'partner', 'organizationName' => 'Test'], $key), null, $key, 1);
			openssl_x509_export($x509, $certificate);
			openssl_pkey_export($key, $privateKey);
			self::$pair = [(string)$certificate, (string)$privateKey];
		}

		return self::$pair;
	}//end pair()

	/**
	 * An encrypted answer built by hand, so each part can be made wrong.
	 *
	 * Options: `content` (EncryptedData algorithm, '' for no EncryptionMethod),
	 * `transport` (EncryptedKey algorithm), `key` (the content key), `value`
	 * (the EncryptedData CipherValue, base64; null for a correct CBC one of
	 * `plaintext`), `plaintext`, `reference` (the DataReference URI), `keys`
	 * (how many EncryptedKey elements), `cipherData` (false leaves the
	 * EncryptedData without CipherData).
	 *
	 * @param array<string, mixed> $options What to build differently.
	 *
	 * @return string The envelope.
	 */
	private function answer(array $options = []): string {
		[$certificate] = $this->pair();
		$options += [
			'content' => self::CBC,
			'transport' => WsSecurityEncryptor::ALGORITHM_RSA_OAEP,
			'key' => random_bytes(32),
			'value' => null,
			'plaintext' => self::BODY,
			'reference' => '#body',
			'keys' => 1,
			'cipherData' => true,
		];

		$value = $options['value'];
		if ($value === null) {
			$iv = random_bytes(16);
			$value = base64_encode($iv . openssl_encrypt($options['plaintext'], 'aes-256-cbc', $options['key'], OPENSSL_RAW_DATA, $iv));
		}

		openssl_public_encrypt($options['key'], $wrapped, openssl_pkey_get_public($certificate), OPENSSL_PKCS1_OAEP_PADDING);
		$encryptedKey = '<xenc:EncryptedKey><xenc:EncryptionMethod Algorithm="' . $options['transport'] . '"/>'
			. '<xenc:CipherData><xenc:CipherValue>' . base64_encode((string)$wrapped) . '</xenc:CipherValue></xenc:CipherData>'
			. '<xenc:ReferenceList><xenc:DataReference URI="' . $options['reference'] . '"/></xenc:ReferenceList></xenc:EncryptedKey>';

		$method = '';
		if ($options['content'] !== '') {
			$method = '<xenc:EncryptionMethod Algorithm="' . $options['content'] . '"/>';
		}

		$cipherData = '';
		if ($options['cipherData'] === true) {
			$cipherData = '<xenc:CipherData><xenc:CipherValue>' . $value . '</xenc:CipherValue></xenc:CipherData>';
		}

		return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xenc="http://www.w3.org/2001/04/xmlenc#">'
			. '<soap:Header><wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
			. str_repeat($encryptedKey, (int)$options['keys'])
			. '</wsse:Security></soap:Header><soap:Body>'
			. '<xenc:EncryptedData Id="body" Type="http://www.w3.org/2001/04/xmlenc#Content">' . $method . $cipherData . '</xenc:EncryptedData>'
			. '</soap:Body></soap:Envelope>';
	}//end answer()

	/**
	 * Decrypting the answer fails with exactly this message.
	 *
	 * @param string $envelope The answer.
	 * @param string $message  The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(string $envelope, string $message): void {
		try {
			(new WsSecurityEncryptor())->decrypt(soapEnvelopeXml: $envelope, privateKeyPem: $this->pair()[1]);
		} catch (DigikoppelingException $e) {
			self::assertSame($message, $e->getMessage());
			return;
		}

		self::fail('The answer was decrypted although it should be refused with: ' . $message);
	}//end assertRefused()

	/**
	 * XPath over an envelope with the SOAP, wsse and xenc prefixes.
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

		return $xpath;
	}//end xpath()

	/**
	 * The hand-built answer decrypts, so every refusal below is caused by the one part made wrong.
	 *
	 * @return void
	 */
	public function testTheHandBuiltAnswerDecrypts(): void {
		$decrypted = (new WsSecurityEncryptor())->decrypt(soapEnvelopeXml: $this->answer(), privateKeyPem: $this->pair()[1]);

		self::assertSame('999993653', $this->xpath(xml: $decrypted)->evaluate('string(/soap:Envelope/soap:Body/*/*)'));
	}//end testTheHandBuiltAnswerDecrypts()

	/**
	 * An envelope that is not XML, or has no Body, is refused for either direction and is not encrypted.
	 *
	 * @return void
	 */
	public function testAnEnvelopeThatIsNotXmlOrHasNoBodyIsRefused(): void {
		$encryptor = new WsSecurityEncryptor();
		$noBody = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header/></soap:Envelope>';

		self::assertFalse($encryptor->isEncrypted(soapEnvelopeXml: '<soap:Envelope'));
		self::assertFalse($encryptor->isEncrypted(soapEnvelopeXml: $noBody));
		$this->assertRefused(envelope: '<soap:Envelope', message: 'Cannot decrypt: the SOAP envelope is not well-formed XML.');

		try {
			$encryptor->encrypt(soapEnvelopeXml: $noBody, partnerCertificatePem: $this->pair()[0]);
			self::fail('An envelope without a Body was encrypted.');
		} catch (DigikoppelingException $e) {
			self::assertSame('Cannot encrypt: the SOAP envelope has no Body element.', $e->getMessage());
		}

		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('Cannot encrypt: the SOAP envelope is not well-formed XML.');
		$encryptor->encrypt(soapEnvelopeXml: 'not xml', partnerCertificatePem: $this->pair()[0]);
	}//end testAnEnvelopeThatIsNotXmlOrHasNoBodyIsRefused()

	/**
	 * Encrypting an envelope without a Header adds one, in the envelope's own prefix, holding the key.
	 *
	 * @return void
	 */
	public function testEncryptingWithoutAHeaderAddsOneWithTheKey(): void {
		[$certificate, $key] = $this->pair();
		$encryptor = new WsSecurityEncryptor();
		$plain = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>' . self::BODY . '</soap:Body></soap:Envelope>';

		$encrypted = $encryptor->encrypt(soapEnvelopeXml: $plain, partnerCertificatePem: $certificate);
		$xpath = $this->xpath(xml: $encrypted);

		self::assertSame('Header', $xpath->query('/soap:Envelope/*')->item(0)->localName, 'the Header comes first');
		self::assertSame('soap:Header', $xpath->query('/soap:Envelope/*')->item(0)->nodeName);
		self::assertSame(1, $xpath->query('/soap:Envelope/soap:Header/wsse:Security/xenc:EncryptedKey')->length);
		self::assertTrue($encryptor->isEncrypted(soapEnvelopeXml: $encrypted));
		self::assertStringContainsString('<stuf:bsn>999993653</stuf:bsn>', $encryptor->decrypt(soapEnvelopeXml: $encrypted, privateKeyPem: $key));
	}//end testEncryptingWithoutAHeaderAddsOneWithTheKey()

	/**
	 * An unprefixed envelope gets an unprefixed Header in its namespace.
	 *
	 * @return void
	 */
	public function testAnUnprefixedEnvelopeGetsAnUnprefixedHeader(): void {
		$plain = '<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"><Body>' . self::BODY . '</Body></Envelope>';

		$encrypted = (new WsSecurityEncryptor())->encrypt(soapEnvelopeXml: $plain, partnerCertificatePem: $this->pair()[0]);
		$header = $this->xpath(xml: $encrypted)->query('/soap:Envelope/soap:Header')->item(0);

		self::assertNotNull($header);
		self::assertSame('Header', $header->nodeName);
		self::assertSame('Security', $header->firstChild->localName);
	}//end testAnUnprefixedEnvelopeGetsAnUnprefixedHeader()

	/**
	 * An existing Header without a Security element gets one, ahead of what it already holds; no second Header.
	 *
	 * @return void
	 */
	public function testAnExistingHeaderReceivesTheSecurityElement(): void {
		$plain = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Header><ref xmlns="urn:x">keep</ref></soap:Header><soap:Body>' . self::BODY . '</soap:Body></soap:Envelope>';

		$encrypted = (new WsSecurityEncryptor())->encrypt(soapEnvelopeXml: $plain, partnerCertificatePem: $this->pair()[0]);
		$xpath = $this->xpath(xml: $encrypted);

		self::assertSame(1, $xpath->query('/soap:Envelope/soap:Header')->length);
		$children = $xpath->query('/soap:Envelope/soap:Header/*');
		self::assertSame(2, $children->length);
		self::assertSame('Security', $children->item(0)->localName);
		self::assertSame('keep', $children->item(1)->textContent);
	}//end testAnExistingHeaderReceivesTheSecurityElement()

	/**
	 * Without a key for the encrypted body the answer is refused; a single key is used even unreferenced.
	 *
	 * @return void
	 */
	public function testTheKeyMustBeThereForTheBody(): void {
		$message = 'WS-Security decryption failed: the answer carries no EncryptedKey for its encrypted body.';
		$this->assertRefused(envelope: $this->answer(options: ['keys' => 0]), message: $message);
		$this->assertRefused(envelope: $this->answer(options: ['keys' => 2, 'reference' => '#other']), message: $message);

		$decrypted = (new WsSecurityEncryptor())->decrypt(
			soapEnvelopeXml: $this->answer(options: ['reference' => '#other']),
			privateKeyPem: $this->pair()[1]
		);
		self::assertStringContainsString('<stuf:bsn>999993653</stuf:bsn>', $decrypted);
	}//end testTheKeyMustBeThereForTheBody()

	/**
	 * An unknown or missing content algorithm is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnsupportedContentAlgorithmIsRefused(): void {
		$this->assertRefused(
			envelope: $this->answer(options: ['content' => 'http://www.w3.org/2001/04/xmlenc#tripledes-cbc']),
			message: 'WS-Security decryption failed: unsupported content encryption algorithm "http://www.w3.org/2001/04/xmlenc#tripledes-cbc".'
		);
		$this->assertRefused(
			envelope: $this->answer(options: ['content' => '']),
			message: 'WS-Security decryption failed: unsupported content encryption algorithm "".'
		);
	}//end testAnUnsupportedContentAlgorithmIsRefused()

	/**
	 * Invalid private key material is refused before any decryption.
	 *
	 * @return void
	 */
	public function testInvalidPrivateKeyMaterialIsRefused(): void {
		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('WS-Security decryption failed: the organisation\'s private key material is invalid.');
		(new WsSecurityEncryptor())->decrypt(soapEnvelopeXml: $this->answer(), privateKeyPem: 'not a key');
	}//end testInvalidPrivateKeyMaterialIsRefused()

	/**
	 * A content key of the wrong length for the declared cipher is refused.
	 *
	 * @return void
	 */
	public function testAContentKeyOfTheWrongLengthIsRefused(): void {
		$this->assertRefused(
			envelope: $this->answer(options: ['key' => random_bytes(16), 'value' => base64_encode(random_bytes(32))]),
			message: 'WS-Security decryption failed: the content key has the wrong length for its algorithm.'
		);
	}//end testAContentKeyOfTheWrongLengthIsRefused()

	/**
	 * A cipher value that is missing, not base64 or shorter than its IV is refused as truncated.
	 *
	 * @return void
	 */
	public function testATruncatedCipherValueIsRefused(): void {
		$message = 'WS-Security decryption failed: the encrypted body is truncated or not base64.';
		$this->assertRefused(envelope: $this->answer(options: ['value' => base64_encode(random_bytes(16))]), message: $message);
		$this->assertRefused(envelope: $this->answer(options: ['value' => '***not-base64***']), message: $message);
		$this->assertRefused(envelope: $this->answer(options: ['cipherData' => false]), message: $message);
	}//end testATruncatedCipherValueIsRefused()

	/**
	 * CBC ciphertext that is not whole blocks, or whose padding byte is out of range, does not decrypt.
	 *
	 * @return void
	 */
	public function testCbcWithBrokenPaddingDoesNotDecrypt(): void {
		$message = 'WS-Security decryption failed: the encrypted body does not decrypt with its content key (tampered or corrupt).';
		$key = random_bytes(32);
		$iv = random_bytes(16);

		$this->assertRefused(envelope: $this->answer(options: ['key' => $key, 'value' => base64_encode($iv . random_bytes(17))]), message: $message);

		foreach ([chr(0), chr(17)] as $padByte) {
			$block = openssl_encrypt(str_repeat('a', 15) . $padByte, 'aes-256-cbc', $key, (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING), $iv);
			$this->assertRefused(envelope: $this->answer(options: ['key' => $key, 'value' => base64_encode($iv . $block)]), message: $message);
		}
	}//end testCbcWithBrokenPaddingDoesNotDecrypt()

	/**
	 * Decrypted content that is not well-formed XML, or carries a DOCTYPE, is refused.
	 *
	 * @return void
	 */
	public function testDecryptedContentMustBeWellFormedWithoutDoctype(): void {
		$message = 'WS-Security decryption failed: the decrypted body is not well-formed XML.';
		$this->assertRefused(envelope: $this->answer(options: ['plaintext' => '<stuf:bsn>unclosed']), message: $message);
		$this->assertRefused(envelope: $this->answer(options: ['plaintext' => '<!DOCTYPE x [<!ENTITY e "x">]><x>&e;</x>']), message: $message);
	}//end testDecryptedContentMustBeWellFormedWithoutDoctype()
}//end class
