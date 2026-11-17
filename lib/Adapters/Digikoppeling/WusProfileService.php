<?php

/**
 * Integriq — Digikoppeling WUS synchronous profile.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The WUS profile carries a StUF/ZGW *bevraging* body over a synchronous
 * request/response, signing the outgoing SOAP envelope with WS-Security X.509
 * and verifying the responder's signature (REQ-DK-002). It COMPOSES with the
 * content services: the StUF body is produced elsewhere (e.g.
 * {@see \OCA\Integriq\Service\StUFXMLBuilder}) and passed in unchanged;
 * this profile signs and (would) deliver it (D1 — transport, not content).
 *
 * Signing material comes from the credential broker via a `certificateRef`
 * ({@see PkiOverheidCredentialResolver}) — never plaintext, fail-closed when the
 * broker cannot supply in-process signing material (REQ-DK-005).
 *
 * DEFERRED (documented): the on-the-wire HTTP POST over two-way TLS (wiring the
 * signed envelope through `SOAPService` with the brokered client certificate)
 * is the WUS live-dispatch follow-on, blocked on the same broker
 * signing-material capability as {@see PkiOverheidCredentialResolver}. The
 * signing + response-verification core delivered here is real and unit-tested.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Digikoppeling;

use OCA\Integriq\Exception\DigikoppelingException;

/**
 * Signs a WUS bevraging and verifies the signed response.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class WusProfileService implements SignedSoapProfileInterface {
	/**
	 * Constructor.
	 *
	 * @param WsSecuritySigner $signer WS-Security X.509 signer/verifier.
	 * @param PkiOverheidCredentialResolver $resolver PKIoverheid signing-material resolver (broker, fail-closed).
	 * @param WsSecurityEncryptor $encryptor WS-Security body encryption for partners that demand it.
	 */
	public function __construct(
		private readonly WsSecuritySigner $signer,
		private readonly PkiOverheidCredentialResolver $resolver,
		private readonly WsSecurityEncryptor $encryptor = new WsSecurityEncryptor(),
	) {
	}//end __construct()

	/**
	 * Build a signed WUS request envelope for a StUF/ZGW bevraging body.
	 *
	 * The StUF body is wrapped in a SOAP envelope (unchanged) and signed with
	 * the PKIoverheid material resolved for the given `certificateRef`. Fails
	 * closed when the broker cannot supply in-process signing material.
	 *
	 * @param string $certificateRef The broker credentialRef for the PKIoverheid certificate.
	 * @param string $stufBodyXml The StUF/ZGW message body XML (produced by the content services).
	 *
	 * @return string The signed SOAP request envelope, ready for two-way-TLS dispatch.
	 *
	 * @throws DigikoppelingException Fail-closed when signing material is unavailable, or on a signing error.
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function buildSignedRequest(string $certificateRef, string $stufBodyXml): string {
		$material = $this->resolver->resolveSigningMaterial(certificateRef: $certificateRef);
		$envelope = $this->wrapInSoapEnvelope(bodyXml: $stufBodyXml);

		return $this->signer->sign(
			soapEnvelopeXml: $envelope,
			certificatePem: $material['certificatePem'],
			privateKeyPem: $material['privateKeyPem']
		);

	}//end buildSignedRequest()

	/**
	 * Verify a WUS response's WS-Security signature.
	 *
	 * A response whose signature is missing or invalid MUST be rejected as a
	 * transport error and MUST NOT be treated as a successful answer.
	 *
	 * @param string $responseXml The signed SOAP response envelope.
	 * @param string|null $expectedCertificatePem Optional certificate to pin the responder to.
	 *
	 * @return string The verified response XML (returned for fluent use).
	 *
	 * @throws DigikoppelingException When the response signature is missing or does not verify.
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function verifyResponse(string $responseXml, ?string $expectedCertificatePem = null): string {
		if ($this->signer->verify(signedXml: $responseXml, expectedCertificatePem: $expectedCertificatePem) === false) {
			throw new DigikoppelingException(
				message:
				'WUS response rejected: the WS-Security signature is missing or does not verify (transport error).'
			);
		}

		return $responseXml;
	}//end verifyResponse()

	/**
	 * Build a signed WUS request whose Body is then encrypted for the partner.
	 *
	 * Signs first, then encrypts the Body content for the partner certificate
	 * (XML Encryption, AES-256-GCM with an RSA-OAEP wrapped key), so the
	 * partner decrypts before it verifies the signature.
	 *
	 * @param string $certificateRef        The broker credentialRef for the PKIoverheid certificate.
	 * @param string $stufBodyXml           The StUF/ZGW message body XML.
	 * @param string $partnerCertificatePem The partner's X.509 certificate (PEM) held on the source.
	 *
	 * @return string The signed and encrypted SOAP request envelope.
	 *
	 * @throws DigikoppelingException Fail-closed when signing material is unavailable, or signing or encryption fails.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	public function buildSignedEncryptedRequest(string $certificateRef, string $stufBodyXml, string $partnerCertificatePem): string {
		$signed = $this->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $stufBodyXml);

		return $this->encryptor->encrypt(soapEnvelopeXml: $signed, partnerCertificatePem: $partnerCertificatePem);
	}//end buildSignedEncryptedRequest()

	/**
	 * Decrypt an encrypted WUS response with the organisation's key, then verify its signature.
	 *
	 * An answer that is not encrypted is verified as it is. One that cannot be
	 * decrypted fails the call with the reason and is never passed on.
	 *
	 * @param string      $certificateRef         The broker credentialRef whose private key decrypts the answer.
	 * @param string      $responseXml            The SOAP response envelope.
	 * @param string|null $expectedCertificatePem Optional certificate to pin the responder to.
	 *
	 * @return string The decrypted, verified response XML.
	 *
	 * @throws DigikoppelingException When the key is unavailable, decryption fails, or the signature does not verify.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	public function decryptAndVerifyResponse(string $certificateRef, string $responseXml, ?string $expectedCertificatePem = null): string {
		$decrypted = $responseXml;
		if ($this->encryptor->isEncrypted(soapEnvelopeXml: $responseXml) === true) {
			$material = $this->resolver->resolveSigningMaterial(certificateRef: $certificateRef);
			$decrypted = $this->encryptor->decrypt(soapEnvelopeXml: $responseXml, privateKeyPem: $material['privateKeyPem']);
		}

		return $this->verifyResponse(responseXml: $decrypted, expectedCertificatePem: $expectedCertificatePem);
	}//end decryptAndVerifyResponse()

	/**
	 * Wrap a message body in a minimal SOAP 1.1 envelope.
	 *
	 * @param string $bodyXml The message body XML.
	 *
	 * @return string The SOAP envelope XML.
	 */
	private function wrapInSoapEnvelope(string $bodyXml): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Header/>'
			. '<soap:Body>' . $bodyXml . '</soap:Body>'
			. '</soap:Envelope>';

	}//end wrapInSoapEnvelope()
}//end class
