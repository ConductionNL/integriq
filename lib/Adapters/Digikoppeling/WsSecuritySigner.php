<?php

/**
 * Integriq — WS-Security 1.1 X.509 signer/verifier for the WUS profile.
 *
 * Signs a SOAP envelope with an enveloped XML-DSig (RSA-SHA256) over the SOAP
 * Body, wrapped in a `wsse:Security` header carrying the X.509 token, and
 * verifies the WS-Security signature on a WUS response. Digikoppeling WUS
 * requires message-level signing on top of two-way TLS; a reply whose signature
 * is missing or does not verify MUST be rejected as a transport error
 * (REQ-DK-002).
 *
 * Implemented directly on ext-dom (`DOMNode::C14N()`) + ext-openssl so it has no
 * third-party XML-security dependency and its crypto is exercised by real
 * signed-envelope fixtures in the unit suite. Canonicalisation is exclusive
 * C14N 1.0; the signature method is `rsa-sha256`, the digest `sha256`.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Digikoppeling;

use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\DigikoppelingException;

/**
 * Signs and verifies WS-Security X.509 signatures over a SOAP Body.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class WsSecuritySigner {

	/**
	 * WS-Security secext namespace.
	 *
	 * @var string
	 */
	private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

	/**
	 * WS-Security utility namespace.
	 *
	 * @var string
	 */
	private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

	/**
	 * XML-DSig namespace.
	 *
	 * @var string
	 */
	private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

	/**
	 * Exclusive C14N 1.0 canonicalisation method.
	 *
	 * Exclusive canonicalisation is required for enveloped XML-DSig: it excludes
	 * ancestor namespace declarations that are not visibly used, so the
	 * SignedInfo digest is stable regardless of where the signature sits in the
	 * document (under a temporary holder at signing time vs under wsse:Security
	 * at verify time).
	 *
	 * @var string
	 */
	private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

	/**
	 * RSA-SHA256 signature method.
	 *
	 * @var string
	 */
	private const SIG_METHOD = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

	/**
	 * SHA-256 digest method.
	 *
	 * @var string
	 */
	private const DIGEST_METHOD = 'http://www.w3.org/2001/04/xmlenc#sha256';

	/**
	 * The Algorithm of each ds:* method element the signature carries.
	 *
	 * @var array<string, string>
	 */
	private const METHOD_ALGORITHMS = [
		'ds:CanonicalizationMethod' => self::C14N,
		'ds:SignatureMethod' => self::SIG_METHOD,
		'ds:Transform' => self::C14N,
		'ds:DigestMethod' => self::DIGEST_METHOD,
	];

	/**
	 * Sign a SOAP envelope's Body with a WS-Security X.509 enveloped signature.
	 *
	 * @param string $soapEnvelopeXml The SOAP 1.1/1.2 envelope XML.
	 * @param string $certificatePem The signer's X.509 certificate (PEM).
	 * @param string $privateKeyPem The signer's RSA private key (PEM).
	 *
	 * @return string The signed SOAP envelope XML.
	 *
	 * @throws DigikoppelingException If the envelope has no Body or the key material is invalid.
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function sign(string $soapEnvelopeXml, string $certificatePem, string $privateKeyPem): string {
		$doc = $this->loadEnvelope(xml: $soapEnvelopeXml);
		if ($doc === null) {
			throw new DigikoppelingException(message:'Cannot sign: the SOAP envelope is not well-formed XML.');
		}

		$body = $this->bodyToSign(doc: $doc);
		$privateKey = $this->signingKey(privateKeyPem: $privateKeyPem);

		$signedInfo = $this->signedInfoFor(body: $body);
		$signatureValue = $this->signatureOver(signedInfo: $signedInfo, privateKey: $privateKey);
		$signature = $this->signatureElement(signedInfo: $signedInfo, signatureValue: $signatureValue);
		$signature->appendChild($this->keyInfo(doc: $doc, certificatePem: $certificatePem));
		$this->attachSecurityHeader(signature: $signature);

		return (string)$doc->saveXML();
	}//end sign()

	/**
	 * Parse an envelope, or null when it is not well-formed XML.
	 *
	 * @param string $xml The SOAP envelope XML.
	 *
	 * @return DOMDocument|null
	 */
	private function loadEnvelope(string $xml): ?DOMDocument {
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		if (@$doc->loadXML($xml) === false) {
			return null;
		}

		return $doc;
	}//end loadEnvelope()

	/**
	 * The envelope's Body, which the signature covers.
	 *
	 * @param DOMDocument $doc The envelope.
	 *
	 * @return DOMElement
	 *
	 * @throws DigikoppelingException If the envelope has no Body.
	 */
	private function bodyToSign(DOMDocument $doc): DOMElement {
		$body = $this->firstByLocalName(doc: $doc, localName: 'Body');
		if ($body === null) {
			throw new DigikoppelingException(message:'Cannot sign: the SOAP envelope has no Body element.');
		}

		return $body;
	}//end bodyToSign()

	/**
	 * The signer's private key.
	 *
	 * @param string $privateKeyPem The signer's RSA private key (PEM).
	 *
	 * @return \OpenSSLAsymmetricKey
	 *
	 * @throws DigikoppelingException If the key material is invalid.
	 */
	private function signingKey(string $privateKeyPem): \OpenSSLAsymmetricKey {
		$privateKey = @openssl_pkey_get_private($privateKeyPem);
		if ($privateKey === false) {
			throw new DigikoppelingException(message:'Cannot sign: the PKIoverheid private key material is invalid.');
		}

		return $privateKey;
	}//end signingKey()

	/**
	 * Tag the Body with a wsu:Id and build the ds:SignedInfo whose Reference digests it.
	 *
	 * @param DOMElement $body The envelope's Body.
	 *
	 * @return DOMElement The ds:SignedInfo, not yet in the document.
	 */
	private function signedInfoFor(DOMElement $body): DOMElement {
		$doc = $body->ownerDocument;

		// Tag the Body with a wsu:Id so the Reference can point at it.
		$bodyId = 'body-' . bin2hex(random_bytes(8));
		$body->setAttributeNS(self::NS_WSU, 'wsu:Id', $bodyId);

		$signedInfo = $doc->createElementNS(self::NS_DS, 'ds:SignedInfo');
		$this->appendDsMethod(parent: $signedInfo, qname: 'ds:CanonicalizationMethod');
		$this->appendDsMethod(parent: $signedInfo, qname: 'ds:SignatureMethod');
		$signedInfo->appendChild($this->bodyReference(body: $body));

		return $signedInfo;
	}//end signedInfoFor()

	/**
	 * Verify the WS-Security signature on a signed SOAP envelope.
	 *
	 * Recomputes the referenced Body digest and verifies the RSA-SHA256
	 * signature over the canonicalised SignedInfo using the certificate carried
	 * in the message (optionally pinned to `$expectedCertificatePem`). Returns
	 * false when the signature is absent, the digest mismatches, or the
	 * signature does not verify — the caller MUST treat false as a transport
	 * error (REQ-DK-002).
	 *
	 * @param string $signedXml The signed SOAP envelope XML.
	 * @param string|null $expectedCertificatePem Optional certificate to pin the signer to.
	 *
	 * @return bool True only when a valid signature over the Body is present.
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function verify(string $signedXml, ?string $expectedCertificatePem = null): bool {
		$doc = $this->loadEnvelope(xml: $signedXml);
		if ($doc === null || $this->hasSignatureParts(doc: $doc) === false) {
			return false;
		}

		if ($this->bodyDigestMatches(doc: $doc) === false) {
			return false;
		}

		return $this->signatureVerifies(doc: $doc, expectedCertificatePem: $expectedCertificatePem);
	}//end verify()

	/**
	 * The ds:Reference over the Body: its URI, the transform, the digest method and the digest.
	 *
	 * @param DOMElement $body The Body, tagged with its wsu:Id.
	 *
	 * @return DOMElement
	 */
	private function bodyReference(DOMElement $body): DOMElement {
		$doc = $body->ownerDocument;
		$digestValue = base64_encode(hash('sha256', (string)$body->C14N(true, false), true));

		$reference = $doc->createElementNS(self::NS_DS, 'ds:Reference');
		$reference->setAttribute('URI', '#' . $body->getAttributeNS(self::NS_WSU, 'Id'));
		$transforms = $doc->createElementNS(self::NS_DS, 'ds:Transforms');
		$this->appendDsMethod(parent: $transforms, qname: 'ds:Transform');
		$reference->appendChild($transforms);
		$this->appendDsMethod(parent: $reference, qname: 'ds:DigestMethod');
		$reference->appendChild($doc->createElementNS(self::NS_DS, 'ds:DigestValue', $digestValue));

		return $reference;
	}//end bodyReference()

	/**
	 * RSA-SHA256 over the canonicalised SignedInfo.
	 *
	 * The SignedInfo must be in-document so the ds namespace resolves during
	 * C14N; it is parked under a detached holder while it is canonicalised.
	 *
	 * @param DOMElement            $signedInfo The ds:SignedInfo.
	 * @param \OpenSSLAsymmetricKey $privateKey The signer's private key.
	 *
	 * @return string The raw signature.
	 *
	 * @throws DigikoppelingException If signing fails.
	 */
	private function signatureOver(DOMElement $signedInfo, \OpenSSLAsymmetricKey $privateKey): string {
		$doc = $signedInfo->ownerDocument;
		$holder = $doc->createElementNS(self::NS_DS, 'ds:SignatureHolder');
		$holder->appendChild($signedInfo);
		$doc->documentElement->appendChild($holder);
		$canonicalSignedInfo = (string)$signedInfo->C14N(true, false);
		$doc->documentElement->removeChild($holder);

		$signatureValue = '';
		if (openssl_sign($canonicalSignedInfo, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256) === false) {
			throw new DigikoppelingException(message:'Cannot sign: RSA-SHA256 signing failed over the SignedInfo.');
		}

		return $signatureValue;
	}//end signatureOver()

	/**
	 * The ds:Signature carrying the SignedInfo and its value.
	 *
	 * @param DOMElement $signedInfo     The ds:SignedInfo.
	 * @param string     $signatureValue The raw signature.
	 *
	 * @return DOMElement
	 */
	private function signatureElement(DOMElement $signedInfo, string $signatureValue): DOMElement {
		$doc = $signedInfo->ownerDocument;
		$signature = $doc->createElementNS(self::NS_DS, 'ds:Signature');
		$signature->appendChild($signedInfo);
		$signature->appendChild($doc->createElementNS(self::NS_DS, 'ds:SignatureValue', base64_encode($signatureValue)));

		return $signature;
	}//end signatureElement()

	/**
	 * The ds:KeyInfo carrying the signer's certificate.
	 *
	 * @param DOMDocument $doc            The envelope.
	 * @param string      $certificatePem The signer's X.509 certificate (PEM).
	 *
	 * @return DOMElement
	 */
	private function keyInfo(DOMDocument $doc, string $certificatePem): DOMElement {
		$keyInfo = $doc->createElementNS(self::NS_DS, 'ds:KeyInfo');
		$x509Data = $doc->createElementNS(self::NS_DS, 'ds:X509Data');
		$x509Data->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509Certificate', $this->certificateBody(certificatePem: $certificatePem)));
		$keyInfo->appendChild($x509Data);

		return $keyInfo;
	}//end keyInfo()

	/**
	 * Attach a wsse:Security header carrying the signature, first in the SOAP Header.
	 *
	 * @param DOMElement $signature The ds:Signature.
	 *
	 * @return void
	 *
	 * @throws DigikoppelingException If the envelope has no Envelope root.
	 */
	private function attachSecurityHeader(DOMElement $signature): void {
		$doc = $signature->ownerDocument;
		$header = $this->ensureHeader(doc: $doc);
		$security = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
		$security->appendChild($signature);
		$header->insertBefore($security, $header->firstChild);
	}//end attachSecurityHeader()

	/**
	 * Whether the envelope carries a ds:Signature, a ds:SignedInfo and a ds:Reference.
	 *
	 * @param DOMDocument $doc The envelope.
	 *
	 * @return bool
	 */
	private function hasSignatureParts(DOMDocument $doc): bool {
		foreach (['Signature', 'SignedInfo', 'Reference'] as $localName) {
			if ((new XmlDsigLookup())->element(doc: $doc, localName: $localName) === null) {
				return false;
			}
		}

		return true;
	}//end hasSignatureParts()

	/**
	 * Whether the Reference points at the Body and its digest matches the recomputed one.
	 *
	 * @param DOMDocument $doc The envelope, with its signature parts.
	 *
	 * @return bool
	 */
	private function bodyDigestMatches(DOMDocument $doc): bool {
		$reference = (new XmlDsigLookup())->element(doc: $doc, localName: 'Reference');
		$referenceUri = ltrim($reference->getAttribute('URI'), '#');
		$body = $this->firstByLocalName(doc: $doc, localName: 'Body');
		if ($body === null || $body->getAttributeNS(self::NS_WSU, 'Id') !== $referenceUri) {
			return false;
		}

		$expectedDigest = (new XmlDsigLookup())->childText(parent: $reference, localName: 'DigestValue');
		$actualDigest = base64_encode(hash('sha256', (string)$body->C14N(true, false), true));

		return hash_equals((string)$expectedDigest, $actualDigest);
	}//end bodyDigestMatches()

	/**
	 * Whether the RSA-SHA256 signature over the canonicalised SignedInfo verifies
	 * against the carried certificate, or the pinned one.
	 *
	 * @param DOMDocument $doc                    The envelope, with its signature parts.
	 * @param string|null $expectedCertificatePem Optional certificate to pin the signer to.
	 *
	 * @return bool
	 */
	private function signatureVerifies(DOMDocument $doc, ?string $expectedCertificatePem): bool {
		$signature = (new XmlDsigLookup())->element(doc: $doc, localName: 'Signature');
		$publicKey = $this->signerKey(signature: $signature, expectedCertificatePem: $expectedCertificatePem);
		if ($publicKey === null) {
			return false;
		}

		$signatureValue = base64_decode((string)(new XmlDsigLookup())->childText(parent: $signature, localName: 'SignatureValue'), true);
		if ($signatureValue === false) {
			return false;
		}

		$canonicalSignedInfo = (string)(new XmlDsigLookup())->element(doc: $doc, localName: 'SignedInfo')->C14N(true, false);

		return (openssl_verify($canonicalSignedInfo, $signatureValue, $publicKey, OPENSSL_ALGO_SHA256) === 1);
	}//end signatureVerifies()

	/**
	 * The public key of the carried certificate, or of the pinned one, or null when there is none.
	 *
	 * @param DOMElement  $signature              The ds:Signature.
	 * @param string|null $expectedCertificatePem Optional certificate to pin the signer to.
	 *
	 * @return \OpenSSLAsymmetricKey|null
	 */
	private function signerKey(DOMElement $signature, ?string $expectedCertificatePem): ?\OpenSSLAsymmetricKey {
		$certBody = (new XmlDsigLookup())->descendantText(parent: $signature, localName: 'X509Certificate');
		if ($expectedCertificatePem !== null) {
			$certBody = $this->certificateBody(certificatePem: $expectedCertificatePem);
		}

		if ($certBody === null || $certBody === '') {
			return null;
		}

		$publicKey = @openssl_pkey_get_public($this->pemFromBody(certificateBody: $certBody));
		if ($publicKey === false) {
			return null;
		}

		return $publicKey;
	}//end signerKey()

	/**
	 * Append a ds:* method element carrying its Algorithm attribute.
	 *
	 * @param DOMElement $parent The element to append to.
	 * @param string     $qname  The ds:* qualified name, a key of METHOD_ALGORITHMS.
	 *
	 * @return void
	 */
	private function appendDsMethod(DOMElement $parent, string $qname): void {
		$element = $parent->ownerDocument->createElementNS(self::NS_DS, $qname);
		$element->setAttribute('Algorithm', self::METHOD_ALGORITHMS[$qname]);
		$parent->appendChild($element);
	}//end appendDsMethod()

	/**
	 * Ensure the envelope has a SOAP Header, creating one if needed.
	 *
	 * @param DOMDocument $doc The envelope document.
	 *
	 * @return DOMElement The Header element.
	 *
	 * @throws DigikoppelingException If the envelope has no Envelope root.
	 */
	private function ensureHeader(DOMDocument $doc): DOMElement {
		$header = $this->firstByLocalName(doc: $doc, localName: 'Header');
		if ($header !== null) {
			return $header;
		}

		$envelope = $this->firstByLocalName(doc: $doc, localName: 'Envelope');
		if ($envelope === null) {
			throw new DigikoppelingException(message:'Cannot sign: the document has no SOAP Envelope element.');
		}

		$headerQname = 'Header';
		if ($envelope->prefix !== '') {
			$headerQname = $envelope->prefix . ':Header';
		}

		$header = $doc->createElementNS($envelope->namespaceURI, $headerQname);
		$envelope->insertBefore($header, $envelope->firstChild);
		return $header;
	}//end ensureHeader()

	/**
	 * First element in the document by local name (namespace-agnostic).
	 *
	 * @param DOMDocument $doc The document.
	 * @param string $localName The local name to match.
	 *
	 * @return DOMElement|null
	 */
	private function firstByLocalName(DOMDocument $doc, string $localName): ?DOMElement {
		foreach ($doc->getElementsByTagName('*') as $node) {
			if ($node instanceof DOMElement && $node->localName === $localName) {
				return $node;
			}
		}

		return null;
	}//end firstByLocalName()

	/**
	 * Extract the base64 body of a PEM certificate (no header/footer/newlines).
	 *
	 * @param string $certificatePem The PEM certificate.
	 *
	 * @return string
	 */
	private function certificateBody(string $certificatePem): string {
		$body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $certificatePem);
		return preg_replace('/\s+/', '', (string)$body);
	}//end certificateBody()

	/**
	 * Re-wrap a base64 certificate body as a PEM certificate.
	 *
	 * @param string $certificateBody The base64 DER body.
	 *
	 * @return string
	 */
	private function pemFromBody(string $certificateBody): string {
		return "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificateBody, 64, "\n") . "-----END CERTIFICATE-----\n";
	}//end pemFromBody()
}//end class
