<?php

/**
 * Integriq — WS-Security XML Encryption of the SOAP Body for the WUS profile.
 *
 * Encrypts the content of a (signed) SOAP Body for a partner's X.509
 * certificate and decrypts an encrypted answer with the organisation's private
 * key. The Body content becomes an `xenc:EncryptedData` (AES-256-GCM), the
 * content key travels RSA-OAEP-wrapped in an `xenc:EncryptedKey` at the top of
 * the `wsse:Security` header, ahead of the signature: encrypt after signing,
 * decrypt before verifying (REQ-WSE-001).
 *
 * Like {@see WsSecuritySigner} it is built on ext-dom + ext-openssl only.
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
use DOMXPath;
use OCA\Integriq\Exception\DigikoppelingException;

/**
 * Encrypts a SOAP Body for a partner certificate and decrypts an encrypted answer.
 *
 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
 *
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class WsSecurityEncryptor {

	/**
	 * WS-Security secext namespace.
	 *
	 * @var string
	 */
	private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

	/**
	 * XML Encryption namespace.
	 *
	 * @var string
	 */
	public const NS_XENC = 'http://www.w3.org/2001/04/xmlenc#';

	/**
	 * XML-DSig namespace.
	 *
	 * @var string
	 */
	private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

	/**
	 * EncryptedData type for element content.
	 *
	 * @var string
	 */
	private const TYPE_CONTENT = 'http://www.w3.org/2001/04/xmlenc#Content';

	/**
	 * The content cipher integriq encrypts with.
	 *
	 * @var string
	 */
	public const ALGORITHM_AES256_GCM = 'http://www.w3.org/2009/xmlenc11#aes256-gcm';

	/**
	 * The key transport integriq wraps the content key with.
	 *
	 * @var string
	 */
	public const ALGORITHM_RSA_OAEP = 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p';

	/**
	 * Content ciphers an answer may use: algorithm URI => [openssl cipher, key bytes, IV bytes, AEAD].
	 *
	 * @var array<string, array{0: string, 1: int, 2: int, 3: bool}>
	 */
	private const CONTENT_CIPHERS = [
		'http://www.w3.org/2009/xmlenc11#aes256-gcm' => ['aes-256-gcm', 32, 12, true],
		'http://www.w3.org/2009/xmlenc11#aes128-gcm' => ['aes-128-gcm', 16, 12, true],
		'http://www.w3.org/2001/04/xmlenc#aes256-cbc' => ['aes-256-cbc', 32, 16, false],
		'http://www.w3.org/2001/04/xmlenc#aes128-cbc' => ['aes-128-cbc', 16, 16, false],
	];

	/**
	 * Key transports an answer may use. RSA PKCS#1 v1.5 is refused.
	 *
	 * @var list<string>
	 */
	private const KEY_TRANSPORTS = [
		'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p',
		'http://www.w3.org/2009/xmlenc11#rsa-oaep',
	];

	/**
	 * The GCM authentication tag length in bytes.
	 *
	 * @var int
	 */
	private const GCM_TAG_BYTES = 16;

	/**
	 * Encrypt the content of the SOAP Body for the partner's certificate.
	 *
	 * The Body element itself stays (with its wsu:Id), so a signature over it
	 * verifies again once the answer is decrypted.
	 *
	 * @param string $soapEnvelopeXml       The (signed) SOAP envelope.
	 * @param string $partnerCertificatePem The partner's X.509 certificate (PEM).
	 *
	 * @return string The envelope with an encrypted Body.
	 *
	 * @throws DigikoppelingException When the envelope or the certificate is unusable, or encryption fails.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	public function encrypt(string $soapEnvelopeXml, string $partnerCertificatePem): string {
		$doc = $this->load(xml: $soapEnvelopeXml, action: 'encrypt');
		$body = $this->body(doc: $doc, action: 'encrypt');
		$publicKey = @openssl_pkey_get_public($partnerCertificatePem);
		if ($publicKey === false) {
			throw new DigikoppelingException(message: 'Cannot encrypt: the partner certificate is missing or invalid.');
		}

		$plaintext = '';
		foreach (iterator_to_array($body->childNodes) as $child) {
			$plaintext .= (string)$doc->saveXML($child);
			$body->removeChild($child);
		}

		$contentKey = random_bytes(32);
		$initVector = random_bytes(12);
		$tag = '';
		$ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $contentKey, OPENSSL_RAW_DATA, $initVector, $tag, '', self::GCM_TAG_BYTES);
		if ($ciphertext === false || openssl_public_encrypt($contentKey, $wrappedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING) === false) {
			throw new DigikoppelingException(message: 'Cannot encrypt: the SOAP body could not be encrypted for the partner certificate.');
		}

		$dataId = 'ED-' . bin2hex(random_bytes(8));
		$body->appendChild($this->encryptedData(doc: $doc, dataId: $dataId, cipherValue: base64_encode($initVector . $ciphertext . $tag)));

		$security = $this->securityHeader(doc: $doc);
		$encryptedKey = $this->encryptedKey(doc: $doc, dataId: $dataId, wrappedKey: (string)$wrappedKey, certificatePem: $partnerCertificatePem);
		$security->insertBefore($encryptedKey, $security->firstChild);

		return (string)$doc->saveXML();
	}//end encrypt()

	/**
	 * Whether the envelope's Body is encrypted.
	 *
	 * @param string $soapEnvelopeXml The SOAP envelope.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	public function isEncrypted(string $soapEnvelopeXml): bool {
		$doc = new DOMDocument();
		if (@$doc->loadXML($soapEnvelopeXml) === false) {
			return false;
		}

		return $this->encryptedBodyData(doc: $doc) !== null;
	}//end isEncrypted()

	/**
	 * Decrypt an encrypted SOAP Body with the organisation's private key.
	 *
	 * An envelope whose Body is not encrypted is returned unchanged. One that is
	 * encrypted but cannot be decrypted fails with the reason; it is never
	 * passed on with an empty Body.
	 *
	 * @param string $soapEnvelopeXml The SOAP envelope.
	 * @param string $privateKeyPem   The organisation's RSA private key (PEM).
	 *
	 * @return string The envelope with its Body content restored.
	 *
	 * @throws DigikoppelingException When the answer cannot be decrypted, naming why.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	public function decrypt(string $soapEnvelopeXml, string $privateKeyPem): string {
		$doc = $this->load(xml: $soapEnvelopeXml, action: 'decrypt');
		$encryptedData = $this->encryptedBodyData(doc: $doc);
		if ($encryptedData === null) {
			return $soapEnvelopeXml;
		}

		$body = $this->body(doc: $doc, action: 'decrypt');
		$encryptedKey = $this->encryptedKeyFor(doc: $doc, dataId: $encryptedData->getAttribute('Id'));
		$cipher = $this->contentCipher(encryptedData: $encryptedData);
		$contentKey = $this->unwrapKey(encryptedKey: $encryptedKey, privateKeyPem: $privateKeyPem, keyBytes: $cipher[1]);
		$plaintext = $this->decryptContent(encryptedData: $encryptedData, cipher: $cipher, contentKey: $contentKey);

		$body->removeChild($encryptedData);
		foreach ($this->parseContent(doc: $doc, body: $body, plaintext: $plaintext) as $node) {
			$body->appendChild($node);
		}

		$encryptedKey->parentNode->removeChild($encryptedKey);

		return (string)$doc->saveXML();
	}//end decrypt()

	/**
	 * Parse an envelope.
	 *
	 * @param string $xml    The envelope XML.
	 * @param string $action `encrypt` or `decrypt`, for the message.
	 *
	 * @return DOMDocument
	 *
	 * @throws DigikoppelingException When it is not well-formed XML.
	 */
	private function load(string $xml, string $action): DOMDocument {
		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		if (@$doc->loadXML($xml, LIBXML_NONET) === false) {
			throw new DigikoppelingException(message: 'Cannot ' . $action . ': the SOAP envelope is not well-formed XML.');
		}

		return $doc;
	}//end load()

	/**
	 * The envelope's Body.
	 *
	 * @param DOMDocument $doc    The envelope.
	 * @param string      $action `encrypt` or `decrypt`, for the message.
	 *
	 * @return DOMElement
	 *
	 * @throws DigikoppelingException When there is no Body.
	 */
	private function body(DOMDocument $doc, string $action): DOMElement {
		$root = $doc->documentElement;
		if ($root !== null) {
			foreach ($root->childNodes as $child) {
				if ($child instanceof DOMElement && $child->localName === 'Body' && $child->namespaceURI === $root->namespaceURI) {
					return $child;
				}
			}
		}

		throw new DigikoppelingException(message: 'Cannot ' . $action . ': the SOAP envelope has no Body element.');
	}//end body()

	/**
	 * The xenc:EncryptedData that is the Body's content, or null when the Body is plain.
	 *
	 * @param DOMDocument $doc The envelope.
	 *
	 * @return DOMElement|null
	 */
	private function encryptedBodyData(DOMDocument $doc): ?DOMElement {
		try {
			$body = $this->body(doc: $doc, action: 'decrypt');
		} catch (DigikoppelingException) {
			return null;
		}

		foreach ($body->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'EncryptedData' && $child->namespaceURI === self::NS_XENC) {
				return $child;
			}
		}

		return null;
	}//end encryptedBodyData()

	/**
	 * The xenc:EncryptedData element replacing the Body content.
	 *
	 * @param DOMDocument $doc         The envelope.
	 * @param string      $dataId      Its Id.
	 * @param string      $cipherValue The base64 IV, ciphertext and tag.
	 *
	 * @return DOMElement
	 */
	private function encryptedData(DOMDocument $doc, string $dataId, string $cipherValue): DOMElement {
		$data = $doc->createElementNS(self::NS_XENC, 'xenc:EncryptedData');
		$data->setAttribute('Id', $dataId);
		$data->setAttribute('Type', self::TYPE_CONTENT);
		$method = $doc->createElementNS(self::NS_XENC, 'xenc:EncryptionMethod');
		$method->setAttribute('Algorithm', self::ALGORITHM_AES256_GCM);
		$data->appendChild($method);
		$data->appendChild($this->cipherData(doc: $doc, cipherValue: $cipherValue));

		return $data;
	}//end encryptedData()

	/**
	 * The xenc:EncryptedKey carrying the wrapped content key for the partner.
	 *
	 * @param DOMDocument $doc            The envelope.
	 * @param string      $dataId         The EncryptedData it unlocks.
	 * @param string      $wrappedKey     The RSA-OAEP wrapped content key.
	 * @param string      $certificatePem The partner certificate it was wrapped for.
	 *
	 * @return DOMElement
	 */
	private function encryptedKey(DOMDocument $doc, string $dataId, string $wrappedKey, string $certificatePem): DOMElement {
		$key = $doc->createElementNS(self::NS_XENC, 'xenc:EncryptedKey');
		$key->setAttribute('Id', 'EK-' . bin2hex(random_bytes(8)));
		$method = $doc->createElementNS(self::NS_XENC, 'xenc:EncryptionMethod');
		$method->setAttribute('Algorithm', self::ALGORITHM_RSA_OAEP);
		$key->appendChild($method);
		$key->appendChild($this->keyInfo(doc: $doc, certificatePem: $certificatePem));
		$key->appendChild($this->cipherData(doc: $doc, cipherValue: base64_encode($wrappedKey)));
		$list = $doc->createElementNS(self::NS_XENC, 'xenc:ReferenceList');
		$reference = $doc->createElementNS(self::NS_XENC, 'xenc:DataReference');
		$reference->setAttribute('URI', '#' . $dataId);
		$list->appendChild($reference);
		$key->appendChild($list);

		return $key;
	}//end encryptedKey()

	/**
	 * The ds:KeyInfo naming the partner certificate by issuer and serial number.
	 *
	 * @param DOMDocument $doc            The envelope.
	 * @param string      $certificatePem The partner certificate.
	 *
	 * @return DOMElement
	 */
	private function keyInfo(DOMDocument $doc, string $certificatePem): DOMElement {
		$parsed = openssl_x509_parse($certificatePem);
		$issuer = [];
		foreach (array_reverse((array)($parsed['issuer'] ?? [])) as $name => $value) {
			if (is_array($value) === true) {
				$value = implode('+', $value);
			}

			$issuer[] = $name . '=' . $value;
		}

		$keyInfo = $doc->createElementNS(self::NS_DS, 'ds:KeyInfo');
		$reference = $doc->createElementNS(self::NS_WSSE, 'wsse:SecurityTokenReference');
		$x509Data = $doc->createElementNS(self::NS_DS, 'ds:X509Data');
		$issuerSerial = $doc->createElementNS(self::NS_DS, 'ds:X509IssuerSerial');
		$issuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509IssuerName', htmlspecialchars(implode(',', $issuer), ENT_XML1)));
		$issuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509SerialNumber', (string)($parsed['serialNumber'] ?? '')));
		$x509Data->appendChild($issuerSerial);
		$reference->appendChild($x509Data);
		$keyInfo->appendChild($reference);

		return $keyInfo;
	}//end keyInfo()

	/**
	 * An xenc:CipherData holding a CipherValue.
	 *
	 * @param DOMDocument $doc         The envelope.
	 * @param string      $cipherValue The base64 value.
	 *
	 * @return DOMElement
	 */
	private function cipherData(DOMDocument $doc, string $cipherValue): DOMElement {
		$data = $doc->createElementNS(self::NS_XENC, 'xenc:CipherData');
		$data->appendChild($doc->createElementNS(self::NS_XENC, 'xenc:CipherValue', $cipherValue));

		return $data;
	}//end cipherData()

	/**
	 * The wsse:Security header, created at the top of the Header when absent.
	 *
	 * @param DOMDocument $doc The envelope.
	 *
	 * @return DOMElement
	 */
	private function securityHeader(DOMDocument $doc): DOMElement {
		$existing = $doc->getElementsByTagNameNS(self::NS_WSSE, 'Security')->item(0);
		if ($existing instanceof DOMElement) {
			return $existing;
		}

		$root = $doc->documentElement;
		$header = null;
		foreach ($root->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'Header' && $child->namespaceURI === $root->namespaceURI) {
				$header = $child;
				break;
			}
		}

		if ($header === null) {
			$qname = 'Header';
			if ((string)$root->prefix !== '') {
				$qname = $root->prefix . ':Header';
			}

			$header = $doc->createElementNS($root->namespaceURI, $qname);
			$root->insertBefore($header, $root->firstChild);
		}

		$security = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
		$header->insertBefore($security, $header->firstChild);

		return $security;
	}//end securityHeader()

	/**
	 * The xenc:EncryptedKey that unlocks the given EncryptedData.
	 *
	 * @param DOMDocument $doc    The envelope.
	 * @param string      $dataId The EncryptedData Id.
	 *
	 * @return DOMElement
	 *
	 * @throws DigikoppelingException When the answer carries no key for it.
	 */
	private function encryptedKeyFor(DOMDocument $doc, string $dataId): DOMElement {
		$keys = $doc->getElementsByTagNameNS(self::NS_XENC, 'EncryptedKey');
		foreach ($keys as $key) {
			foreach ($key->getElementsByTagNameNS(self::NS_XENC, 'DataReference') as $reference) {
				if ($dataId !== '' && $reference->getAttribute('URI') === '#' . $dataId) {
					return $key;
				}
			}
		}

		if ($keys->length === 1) {
			return $keys->item(0);
		}

		throw new DigikoppelingException(message: 'WS-Security decryption failed: the answer carries no EncryptedKey for its encrypted body.');
	}//end encryptedKeyFor()

	/**
	 * The content cipher the EncryptedData names, refused when unsupported.
	 *
	 * @param DOMElement $encryptedData The EncryptedData.
	 *
	 * @return array{0: string, 1: int, 2: int, 3: bool}
	 *
	 * @throws DigikoppelingException When the algorithm is not supported.
	 */
	private function contentCipher(DOMElement $encryptedData): array {
		$algorithm = $this->algorithmOf(element: $encryptedData);
		if (isset(self::CONTENT_CIPHERS[$algorithm]) === false) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: unsupported content encryption algorithm "' . $algorithm . '".');
		}

		return self::CONTENT_CIPHERS[$algorithm];
	}//end contentCipher()

	/**
	 * Unwrap the content key with the organisation's private key.
	 *
	 * @param DOMElement $encryptedKey  The EncryptedKey.
	 * @param string     $privateKeyPem The organisation's private key (PEM).
	 * @param int        $keyBytes      The key length the content cipher needs.
	 *
	 * @return string The content key.
	 *
	 * @throws DigikoppelingException When the key cannot be unwrapped.
	 */
	private function unwrapKey(DOMElement $encryptedKey, string $privateKeyPem, int $keyBytes): string {
		$algorithm = $this->algorithmOf(element: $encryptedKey);
		if (in_array($algorithm, self::KEY_TRANSPORTS, true) === false) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: unsupported key transport algorithm "' . $algorithm . '".');
		}

		$privateKey = @openssl_pkey_get_private($privateKeyPem);
		if ($privateKey === false) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: the organisation\'s private key material is invalid.');
		}

		$wrapped = base64_decode($this->cipherValue(element: $encryptedKey), true);
		$contentKey = '';
		if ($wrapped === false || @openssl_private_decrypt($wrapped, $contentKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING) === false) {
			throw new DigikoppelingException(
				message: 'WS-Security decryption failed: the content key could not be decrypted with the organisation\'s private key'
				. ' (the answer was encrypted for a different key).'
			);
		}

		if (strlen((string)$contentKey) !== $keyBytes) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: the content key has the wrong length for its algorithm.');
		}

		return (string)$contentKey;
	}//end unwrapKey()

	/**
	 * Decrypt the EncryptedData's cipher value.
	 *
	 * @param DOMElement                          $encryptedData The EncryptedData.
	 * @param array{0: string, 1: int, 2: int, 3: bool} $cipher  The content cipher.
	 * @param string                              $contentKey    The content key.
	 *
	 * @return string The plaintext Body content.
	 *
	 * @throws DigikoppelingException When the ciphertext does not decrypt.
	 */
	private function decryptContent(DOMElement $encryptedData, array $cipher, string $contentKey): string {
		[$name, , $ivBytes, $aead] = $cipher;
		$raw = base64_decode($this->cipherValue(element: $encryptedData), true);
		$minimum = $ivBytes + 1;
		if ($aead === true) {
			$minimum = $ivBytes + self::GCM_TAG_BYTES;
		}

		if ($raw === false || strlen($raw) < $minimum) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: the encrypted body is truncated or not base64.');
		}

		$initVector = substr($raw, 0, $ivBytes);
		if ($aead === true) {
			$ciphertext = substr($raw, $ivBytes, -self::GCM_TAG_BYTES);
			$plaintext = openssl_decrypt($ciphertext, $name, $contentKey, OPENSSL_RAW_DATA, $initVector, substr($raw, -self::GCM_TAG_BYTES));
		}

		if ($aead === false) {
			$plaintext = $this->decryptCbc(name: $name, ciphertext: substr($raw, $ivBytes), contentKey: $contentKey, initVector: $initVector);
		}

		if ($plaintext === false) {
			throw new DigikoppelingException(
				message: 'WS-Security decryption failed: the encrypted body does not decrypt with its content key (tampered or corrupt).'
			);
		}

		return $plaintext;
	}//end decryptContent()

	/**
	 * AES-CBC with XML Encryption padding: the last byte is the pad length.
	 *
	 * @param string $name       The openssl cipher.
	 * @param string $ciphertext The ciphertext.
	 * @param string $contentKey The content key.
	 * @param string $initVector The IV.
	 *
	 * @return string|false The plaintext, or false when it does not decrypt.
	 */
	private function decryptCbc(string $name, string $ciphertext, string $contentKey, string $initVector): string|false {
		$padded = openssl_decrypt($ciphertext, $name, $contentKey, (OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING), $initVector);
		if ($padded === false || $padded === '') {
			return false;
		}

		$padLength = ord($padded[(strlen($padded) - 1)]);
		if ($padLength < 1 || $padLength > 16 || $padLength > strlen($padded)) {
			return false;
		}

		return substr($padded, 0, -$padLength);
	}//end decryptCbc()

	/**
	 * Parse decrypted Body content in the Body's namespace context.
	 *
	 * @param DOMDocument $doc       The envelope.
	 * @param DOMElement  $body      The Body.
	 * @param string      $plaintext The decrypted content.
	 *
	 * @return list<\DOMNode> The nodes, imported into the envelope.
	 *
	 * @throws DigikoppelingException When the content is not well-formed XML.
	 */
	private function parseContent(DOMDocument $doc, DOMElement $body, string $plaintext): array {
		$declarations = '';
		foreach ((new DOMXPath($doc))->query('namespace::*', $body) as $namespace) {
			if ($namespace->localName === 'xml') {
				continue;
			}

			$declarations .= ' ' . $namespace->nodeName . '="' . htmlspecialchars((string)$namespace->nodeValue, (ENT_XML1 | ENT_QUOTES)) . '"';
		}

		$content = new DOMDocument();
		$content->preserveWhiteSpace = false;
		if (str_contains($plaintext, '<!DOCTYPE') === true
			|| @$content->loadXML('<integriq-decrypted' . $declarations . '>' . $plaintext . '</integriq-decrypted>', LIBXML_NONET) === false
		) {
			throw new DigikoppelingException(message: 'WS-Security decryption failed: the decrypted body is not well-formed XML.');
		}

		$nodes = [];
		foreach ($content->documentElement->childNodes as $child) {
			$nodes[] = $doc->importNode($child, true);
		}

		return $nodes;
	}//end parseContent()

	/**
	 * The Algorithm of an element's xenc:EncryptionMethod.
	 *
	 * @param DOMElement $element An EncryptedData or EncryptedKey.
	 *
	 * @return string
	 */
	private function algorithmOf(DOMElement $element): string {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'EncryptionMethod' && $child->namespaceURI === self::NS_XENC) {
				return $child->getAttribute('Algorithm');
			}
		}

		return '';
	}//end algorithmOf()

	/**
	 * The text of an element's own xenc:CipherData/xenc:CipherValue.
	 *
	 * @param DOMElement $element An EncryptedData or EncryptedKey.
	 *
	 * @return string
	 */
	private function cipherValue(DOMElement $element): string {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'CipherData' && $child->namespaceURI === self::NS_XENC) {
				foreach ($child->childNodes as $value) {
					if ($value instanceof DOMElement && $value->localName === 'CipherValue') {
						return trim($value->textContent);
					}
				}
			}
		}

		return '';
	}//end cipherValue()
}//end class
