<?php

/**
 * Integriq — WS-Security signer: SOAP Header creation and the no-Envelope refusal.
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
use OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner;
use OCA\Integriq\Adapters\Digikoppeling\XmlDsigLookup;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The signer places its Security header in the envelope's own SOAP Header,
 * creating that Header when the envelope has none.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
 */
#[CoversClass(WsSecuritySigner::class)]
#[UsesClass(XmlDsigLookup::class)]
#[UsesClass(DigikoppelingException::class)]
class WsSecuritySignerHeaderTest extends TestCase {

	/**
	 * SOAP 1.1 envelope namespace.
	 */
	private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

	/**
	 * @var string
	 */
	private string $certPem = '';

	/**
	 * @var string
	 */
	private string $keyPem = '';

	/**
	 * Generate a self-signed RSA keypair for signing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false) {
			$this->markTestSkipped('openssl keypair generation unavailable in this environment');
		}

		$csr = openssl_csr_new(['commonName' => 'test-pkio'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, $this->certPem);
		openssl_pkey_export($key, $this->keyPem);

	}//end setUp()

	/**
	 * An envelope without a Header gets one, prefixed like the Envelope, as its first child.
	 *
	 * @return void
	 */
	public function testMissingHeaderIsCreatedWithTheEnvelopePrefix(): void {
		$envelope = '<?xml version="1.0"?><env:Envelope xmlns:env="' . self::NS_SOAP . '"><env:Body><vraag>payload</vraag></env:Body></env:Envelope>';
		$signer = new WsSecuritySigner();

		$signed = $signer->sign($envelope, $this->certPem, $this->keyPem);

		$doc = new DOMDocument();
		$doc->loadXML($signed);
		$first = $doc->documentElement->firstChild;
		$this->assertSame('env:Header', $first->nodeName);
		$this->assertSame(self::NS_SOAP, $first->namespaceURI);
		$this->assertSame(1, $doc->getElementsByTagNameNS(self::NS_SOAP, 'Header')->length);
		$this->assertSame('Security', $first->firstChild->localName);
		$this->assertTrue($signer->verify($signed, $this->certPem));

	}//end testMissingHeaderIsCreatedWithTheEnvelopePrefix()

	/**
	 * An unprefixed envelope without a Header gets an unprefixed Header in its namespace.
	 *
	 * @return void
	 */
	public function testMissingHeaderOnADefaultNamespaceEnvelopeIsUnprefixed(): void {
		$envelope = '<?xml version="1.0"?><Envelope xmlns="' . self::NS_SOAP . '"><Body><vraag>payload</vraag></Body></Envelope>';
		$signer = new WsSecuritySigner();

		$signed = $signer->sign($envelope, $this->certPem, $this->keyPem);

		$doc = new DOMDocument();
		$doc->loadXML($signed);
		$first = $doc->documentElement->firstChild;
		$this->assertSame('Header', $first->nodeName);
		$this->assertSame(self::NS_SOAP, $first->namespaceURI);
		$this->assertTrue($signer->verify($signed, $this->certPem));

	}//end testMissingHeaderOnADefaultNamespaceEnvelopeIsUnprefixed()

	/**
	 * A document with a Body but neither Header nor Envelope is refused.
	 *
	 * @return void
	 */
	public function testBodyWithoutEnvelopeIsRefused(): void {
		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessage('Cannot sign: the document has no SOAP Envelope element.');

		(new WsSecuritySigner())->sign('<soap:Body xmlns:soap="' . self::NS_SOAP . '"><vraag>payload</vraag></soap:Body>', $this->certPem, $this->keyPem);

	}//end testBodyWithoutEnvelopeIsRefused()
}//end class
