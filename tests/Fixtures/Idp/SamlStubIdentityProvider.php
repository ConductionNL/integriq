<?php

/**
 * A stub SAML identity provider for the Service Provider adapter's tests: its
 * metadata, and signed (optionally encrypted) Responses to an AuthnRequest,
 * with keys and certificates generated here, in the test, and never committed.
 * It can also forge the Responses the adapter must refuse: a broken
 * signature, a wrapped assertion, an entity declaration and an unsigned
 * assertion.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Fixtures\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Fixtures\Idp;

use DOMDocument;
use DOMElement;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * Answers the identity provider's metadata and builds Responses.
 */
final class SamlStubIdentityProvider {

	public const ENTITY_ID = 'https://idp.example/saml';

	public const SSO_URL = 'https://idp.example/sso';

	public const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

	public const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

	/** @var array{0: string, 1: string} The identity provider's certificate and key. */
	private array $idpKeys;

	/** @var array{0: string, 1: string} The SP's signing certificate and key. */
	private array $spSigning;

	/** @var array{0: string, 1: string} The SP's decryption certificate and key. */
	private array $spDecryption;

	/**
	 * Constructor: three fresh key pairs, for the identity provider and the SP.
	 *
	 * @param string $spEntityId The SP EntityID an assertion is meant for.
	 * @param string $acsUrl     The assertion consumer service a Response is sent to.
	 */
	public function __construct(
		public readonly string $spEntityId,
		public readonly string $acsUrl,
	) {
		$this->idpKeys = self::keyPair(commonName: 'idp.example');
		$this->spSigning = self::keyPair(commonName: 'sp-signing.example');
		$this->spDecryption = self::keyPair(commonName: 'sp-decryption.example');
	}

	/**
	 * A fresh RSA key and a self-signed certificate for it, both PEM.
	 *
	 * @param string $commonName The certificate's common name.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function keyPair(string $commonName): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false) {
			throw new RuntimeException('openssl could not generate a key');
		}

		$csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
		$certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		openssl_x509_export($certificate, $certificatePem);
		openssl_pkey_export($key, $keyPem);

		return [$certificatePem, $keyPem];
	}

	/**
	 * The SP signing credential as Keepiq would hold it: certificate and key in one PEM.
	 *
	 * @return string
	 */
	public function spSigningCredential(): string {
		return $this->spSigning[0].$this->spSigning[1];
	}

	/**
	 * The SP decryption credential as Keepiq would hold it.
	 *
	 * @return string
	 */
	public function spDecryptionCredential(): string {
		return $this->spDecryption[0].$this->spDecryption[1];
	}

	/**
	 * The SP signing certificate, PEM.
	 *
	 * @return string
	 */
	public function spSigningCertificate(): string {
		return $this->spSigning[0];
	}

	/**
	 * The identity provider's metadata, with its signing certificate and a Redirect sign-on service.
	 *
	 * @param string $ssoUrl The sign-on service address it publishes.
	 *
	 * @return string
	 */
	public function metadata(string $ssoUrl = self::SSO_URL): string {
		$certificate = self::derBody(pem: $this->idpKeys[0]);

		return '<?xml version="1.0"?>'
			.'<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"'
			.' xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="'.self::ENTITY_ID.'">'
			.'<md:IDPSSODescriptor WantAuthnRequestsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
			.'<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data>'
			.'<ds:X509Certificate>'.$certificate.'</ds:X509Certificate>'
			.'</ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
			.'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="'.$ssoUrl.'"/>'
			.'</md:IDPSSODescriptor></md:EntityDescriptor>';
	}

	/**
	 * A base64 SAMLResponse to a request.
	 *
	 * @param string               $inResponseTo The AuthnRequest ID.
	 * @param array<string,string> $attributes   Attribute name => value.
	 * @param array<string,mixed>  $options      `encrypt`, `sign` (default true), `audience`, `classRef`, `tamper`
	 *                                           (change a value after signing), `wrap` (XML signature wrapping),
	 *                                           `doctype` (an entity declaration), `destination`.
	 *
	 * @return string
	 */
	public function response(string $inResponseTo, array $attributes, array $options = []): string {
		$document = new DOMDocument();
		$document->loadXML($this->responseXml(inResponseTo: $inResponseTo, options: $options));
		$assertionXml = $this->assertionXml(inResponseTo: $inResponseTo, attributes: $attributes, options: $options);
		if (($options['sign'] ?? true) === true) {
			$assertionXml = Utils::addSign(
				$assertionXml,
				$this->idpKeys[1],
				$this->idpKeys[0],
				XMLSecurityKey::RSA_SHA256,
				'http://www.w3.org/2001/04/xmlenc#sha256'
			);
		}

		$assertionDocument = new DOMDocument();
		$assertionDocument->loadXML($assertionXml);
		$assertion = $document->importNode($assertionDocument->documentElement, true);
		$document->documentElement->appendChild($assertion);
		// The schema wants the enveloped signature right after the Issuer; the
		// enveloped-signature transform makes its place irrelevant to the digest.
		$signature = $assertion->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
		if ($signature !== null) {
			$issuer = $assertion->getElementsByTagNameNS(self::NS_SAML, 'Issuer')->item(0);
			$assertion->insertBefore($signature, $issuer->nextSibling);
		}

		if (isset($options['tamper']) === true) {
			foreach ($document->getElementsByTagNameNS(self::NS_SAML, 'AttributeValue') as $value) {
				$value->textContent = (string)$options['tamper'];
			}
		}

		if (($options['wrap'] ?? false) === true) {
			$this->wrap(document: $document, assertion: $assertion);
		}

		if (($options['encrypt'] ?? false) === true) {
			$this->encrypt(document: $document, assertion: $assertion);
		}

		$xml = (string)$document->saveXML($document->documentElement);
		if (($options['doctype'] ?? false) === true) {
			$xml = '<?xml version="1.0"?><!DOCTYPE samlp:Response [<!ENTITY bsn "999999990">]>'.$xml;
		}

		return base64_encode($xml);
	}

	/**
	 * Wrapping: an unsigned forged assertion with the signed one's ID takes its place
	 * directly under the Response, and the signed original hides inside it.
	 *
	 * @param DOMDocument $document  The Response.
	 * @param DOMElement  $assertion The signed assertion.
	 *
	 * @return void
	 */
	private function wrap(DOMDocument $document, DOMElement $assertion): void {
		$forged = $assertion->cloneNode(true);
		foreach (iterator_to_array($forged->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')) as $signature) {
			$signature->parentNode->removeChild($signature);
		}

		foreach ($forged->getElementsByTagNameNS(self::NS_SAML, 'AttributeValue') as $value) {
			$value->textContent = '999999990';
		}

		$advice = $document->createElementNS(self::NS_SAML, 'saml:Advice');
		$document->documentElement->replaceChild($forged, $assertion);
		$advice->appendChild($assertion);
		$conditions = $forged->getElementsByTagNameNS(self::NS_SAML, 'Conditions')->item(0);
		$forged->insertBefore($advice, $conditions->nextSibling);
	}

	/**
	 * Replace the assertion with an EncryptedAssertion for the SP's decryption certificate.
	 *
	 * @param DOMDocument $document  The Response.
	 * @param DOMElement  $assertion The (signed) assertion.
	 *
	 * @return void
	 */
	private function encrypt(DOMDocument $document, DOMElement $assertion): void {
		$sessionKey = new XMLSecurityKey(XMLSecurityKey::AES256_GCM);
		$sessionKey->generateSessionKey();
		$transportKey = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
		$transportKey->loadKey($this->spDecryption[0], false, true);

		$encrypted = $document->createElementNS(self::NS_SAML, 'saml:EncryptedAssertion');
		$document->documentElement->replaceChild($encrypted, $assertion);
		$holder = new DOMDocument();
		$holder->appendChild($holder->importNode($assertion, true));

		$enc = new XMLSecEnc();
		$enc->setNode($holder->documentElement);
		$enc->type = XMLSecEnc::Element;
		$enc->encryptKey($transportKey, $sessionKey);
		$data = $enc->encryptNode($sessionKey, false);
		$encrypted->appendChild($document->importNode($data, true));
	}

	/**
	 * The Response envelope, without its assertion.
	 *
	 * @param string              $inResponseTo The AuthnRequest ID.
	 * @param array<string,mixed> $options      `destination`.
	 *
	 * @return string
	 */
	private function responseXml(string $inResponseTo, array $options): string {
		return '<samlp:Response xmlns:samlp="'.self::NS_SAMLP.'" xmlns:saml="'.self::NS_SAML.'"'
			.' ID="_r'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.self::instant(offset: 0).'"'
			.' Destination="'.($options['destination'] ?? $this->acsUrl).'" InResponseTo="'.$inResponseTo.'">'
			.'<saml:Issuer>'.self::ENTITY_ID.'</saml:Issuer>'
			.'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
			.'</samlp:Response>';
	}

	/**
	 * The assertion, unsigned.
	 *
	 * @param string               $inResponseTo The AuthnRequest ID.
	 * @param array<string,string> $attributes   Attribute name => value.
	 * @param array<string,mixed>  $options      `audience`, `classRef`.
	 *
	 * @return string
	 */
	private function assertionXml(string $inResponseTo, array $attributes, array $options): string {
		$statement = '';
		foreach ($attributes as $name => $value) {
			$statement .= '<saml:Attribute Name="'.htmlspecialchars($name).'" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:uri">'
				.'<saml:AttributeValue>'.htmlspecialchars($value).'</saml:AttributeValue></saml:Attribute>';
		}

		if ($statement !== '') {
			$statement = '<saml:AttributeStatement>'.$statement.'</saml:AttributeStatement>';
		}

		return '<saml:Assertion xmlns:saml="'.self::NS_SAML.'" ID="_a'.bin2hex(random_bytes(16)).'"'
			.' Version="2.0" IssueInstant="'.self::instant(offset: 0).'">'
			.'<saml:Issuer>'.self::ENTITY_ID.'</saml:Issuer>'
			.'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:transient">_t'.bin2hex(random_bytes(8)).'</saml:NameID>'
			.'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
			.'<saml:SubjectConfirmationData InResponseTo="'.$inResponseTo.'" Recipient="'.$this->acsUrl.'" NotOnOrAfter="'.self::instant(offset: 300).'"/>'
			.'</saml:SubjectConfirmation></saml:Subject>'
			.'<saml:Conditions NotBefore="'.self::instant(offset: -60).'" NotOnOrAfter="'.self::instant(offset: 300).'">'
			.'<saml:AudienceRestriction><saml:Audience>'.($options['audience'] ?? $this->spEntityId).'</saml:Audience></saml:AudienceRestriction>'
			.'</saml:Conditions>'
			.'<saml:AuthnStatement AuthnInstant="'.self::instant(offset: 0).'" SessionIndex="_s1">'
			.'<saml:AuthnContext><saml:AuthnContextClassRef>'
			.($options['classRef'] ?? 'urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard')
			.'</saml:AuthnContextClassRef></saml:AuthnContext>'
			.'</saml:AuthnStatement>'
			.$statement
			.'</saml:Assertion>';
	}

	/**
	 * A SAML instant, offset from now.
	 *
	 * @param integer $offset Seconds from now.
	 *
	 * @return string
	 */
	public static function instant(int $offset): string {
		return gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
	}

	/**
	 * The base64 body of a PEM certificate.
	 *
	 * @param string $pem The certificate.
	 *
	 * @return string
	 */
	private static function derBody(string $pem): string {
		return trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $pem));
	}
}
