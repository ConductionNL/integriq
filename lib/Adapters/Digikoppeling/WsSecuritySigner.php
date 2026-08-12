<?php

/**
 * OpenConnector — WS-Security 1.1 X.509 signer/verifier for the WUS profile.
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
 * @package  OCA\OpenConnector\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Adapters\Digikoppeling;

use DOMDocument;
use DOMElement;
use OCA\OpenConnector\Exception\DigikoppelingException;

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
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		if (@$doc->loadXML($soapEnvelopeXml) === false) {
			throw new DigikoppelingException(message:'Cannot sign: the SOAP envelope is not well-formed XML.');
		}

		$body = $this->firstByLocalName(doc: $doc, localName: 'Body');
		if ($body === null) {
			throw new DigikoppelingException(message:'Cannot sign: the SOAP envelope has no Body element.');
		}

		$privateKey = @openssl_pkey_get_private($privateKeyPem);
		if ($privateKey === false) {
			throw new DigikoppelingException(message:'Cannot sign: the PKIoverheid private key material is invalid.');
		}

		// Tag the Body with a wsu:Id so the Reference can point at it.
		$bodyId = 'body-' . bin2hex(random_bytes(8));
		$body->setAttributeNS(self::NS_WSU, 'wsu:Id', $bodyId);

		// Digest the canonicalised Body.
		$digestValue = base64_encode(hash('sha256', (string)$body->C14N(true, false), true));

		// Build ds:SignedInfo.
		$signedInfo = $doc->createElementNS(self::NS_DS, 'ds:SignedInfo');
		$signedInfo->appendChild($this->methodElement(doc: $doc, qname: 'ds:CanonicalizationMethod', algorithm: self::C14N));
		$signedInfo->appendChild($this->methodElement(doc: $doc, qname: 'ds:SignatureMethod', algorithm: self::SIG_METHOD));

		$reference = $doc->createElementNS(self::NS_DS, 'ds:Reference');
		$reference->setAttribute('URI', '#' . $bodyId);
		$transforms = $doc->createElementNS(self::NS_DS, 'ds:Transforms');
		$transforms->appendChild($this->methodElement(doc: $doc, qname: 'ds:Transform', algorithm: self::C14N));
		$reference->appendChild($transforms);
		$reference->appendChild($this->methodElement(doc: $doc, qname: 'ds:DigestMethod', algorithm: self::DIGEST_METHOD));
		$digestEl = $doc->createElementNS(self::NS_DS, 'ds:DigestValue', $digestValue);
		$reference->appendChild($digestEl);
		$signedInfo->appendChild($reference);

		// Sign the canonicalised SignedInfo. It must be in-document so the ds
		// namespace resolves during C14N; park it under a detached holder.
		$holder = $doc->createElementNS(self::NS_DS, 'ds:SignatureHolder');
		$holder->appendChild($signedInfo);
		$doc->documentElement->appendChild($holder);
		$canonicalSignedInfo = (string)$signedInfo->C14N(true, false);
		$doc->documentElement->removeChild($holder);

		$signatureValue = '';
		if (openssl_sign($canonicalSignedInfo, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256) === false) {
			throw new DigikoppelingException(message:'Cannot sign: RSA-SHA256 signing failed over the SignedInfo.');
		}

		// Build ds:Signature.
		$signature = $doc->createElementNS(self::NS_DS, 'ds:Signature');
		$signature->appendChild($signedInfo);
		$signature->appendChild($doc->createElementNS(self::NS_DS, 'ds:SignatureValue', base64_encode($signatureValue)));

		$keyInfo = $doc->createElementNS(self::NS_DS, 'ds:KeyInfo');
		$x509Data = $doc->createElementNS(self::NS_DS, 'ds:X509Data');
		$x509Data->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509Certificate', $this->certificateBody(certificatePem: $certificatePem)));
		$keyInfo->appendChild($x509Data);
		$signature->appendChild($keyInfo);

		// Attach a wsse:Security header carrying the signature.
		$header = $this->ensureHeader(doc: $doc);
		$security = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
		$security->appendChild($signature);
		$header->insertBefore($security, $header->firstChild);

		return (string)$doc->saveXML();
	}//end sign()

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
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		if (@$doc->loadXML($signedXml) === false) {
			return false;
		}

		$signature = $this->firstByLocalNameNs(doc: $doc, namespace: self::NS_DS, localName: 'Signature');
		$signedInfo = $this->firstByLocalNameNs(doc: $doc, namespace: self::NS_DS, localName: 'SignedInfo');
		$reference = $this->firstByLocalNameNs(doc: $doc, namespace: self::NS_DS, localName: 'Reference');
		if ($signature === null || $signedInfo === null || $reference === null) {
			return false;
		}

		// Recompute + compare the Body digest.
		$referenceUri = ltrim($reference->getAttribute('URI'), '#');
		$body = $this->firstByLocalName(doc: $doc, localName: 'Body');
		if ($body === null || $body->getAttributeNS(self::NS_WSU, 'Id') !== $referenceUri) {
			return false;
		}

		$expectedDigest = $this->firstChildText(parent: $reference, namespace: self::NS_DS, localName: 'DigestValue');
		$actualDigest = base64_encode(hash('sha256', (string)$body->C14N(true, false), true));
		if (hash_equals((string)$expectedDigest, $actualDigest) === false) {
			return false;
		}

		// Resolve the certificate to verify against.
		$certBody = $this->firstDeepText(parent: $signature, namespace: self::NS_DS, localName: 'X509Certificate');
		if ($expectedCertificatePem !== null) {
			$certBody = $this->certificateBody(certificatePem: $expectedCertificatePem);
		}

		if ($certBody === null || $certBody === '') {
			return false;
		}

		$publicKey = @openssl_pkey_get_public($this->pemFromBody(certificateBody: $certBody));
		if ($publicKey === false) {
			return false;
		}

		$signatureText = (string)$this->firstChildText(parent: $signature, namespace: self::NS_DS, localName: 'SignatureValue');
		$signatureValue = base64_decode($signatureText, true);
		if ($signatureValue === false) {
			return false;
		}

		$canonicalSignedInfo = (string)$signedInfo->C14N(true, false);

		return (openssl_verify($canonicalSignedInfo, $signatureValue, $publicKey, OPENSSL_ALGO_SHA256) === 1);
	}//end verify()

	/**
	 * Build a ds:* method element with an Algorithm attribute (+ optional text).
	 *
	 * @param DOMDocument $doc Owner document.
	 * @param string $qname The ds:* qualified name.
	 * @param string $algorithm The Algorithm attribute value.
	 *
	 * @return DOMElement
	 */
	private function methodElement(DOMDocument $doc, string $qname, string $algorithm): DOMElement {
		$element = $doc->createElementNS(self::NS_DS, $qname);
		$element->setAttribute('Algorithm', $algorithm);
		return $element;
	}//end methodElement()

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
	 * First element by namespace + local name.
	 *
	 * @param DOMDocument $doc The document.
	 * @param string $namespace The namespace URI.
	 * @param string $localName The local name.
	 *
	 * @return DOMElement|null
	 */
	private function firstByLocalNameNs(DOMDocument $doc, string $namespace, string $localName): ?DOMElement {
		$nodes = $doc->getElementsByTagNameNS($namespace, $localName);
		$first = $nodes->item(0);
		if ($first instanceof DOMElement) {
			return $first;
		}

		return null;
	}//end firstByLocalNameNs()

	/**
	 * Text of a direct child element by namespace + local name.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $namespace The child namespace URI.
	 * @param string $localName The child local name.
	 *
	 * @return string|null
	 */
	private function firstChildText(DOMElement $parent, string $namespace, string $localName): ?string {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === $namespace && $child->localName === $localName) {
				return $child->textContent;
			}
		}

		return null;
	}//end firstChildText()

	/**
	 * Text of the first descendant element by namespace + local name.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $namespace The descendant namespace URI.
	 * @param string $localName The descendant local name.
	 *
	 * @return string|null
	 */
	private function firstDeepText(DOMElement $parent, string $namespace, string $localName): ?string {
		$nodes = $parent->getElementsByTagNameNS($namespace, $localName);
		$first = $nodes->item(0);
		if ($first !== null) {
			return $first->textContent;
		}

		return null;
	}//end firstDeepText()

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
