<?php

/**
 * Integriq signed-envelope delivery.
 *
 * Delivers one rendered XML envelope over the WUS signed profile: refuse
 * closed when the signing material does not resolve, refuse a missing
 * endpoint, sign, POST, refuse a non-2xx answer and read the reference from
 * the answer (the caller's kenmerk when the answer is empty). The refusals
 * are built by the client, in its own words and with its own exception.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a signed XML envelope and reads its reference.
 *
 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */
final class SignedEnvelopeDelivery {

	/**
	 * Constructor.
	 *
	 * @param Client                                 $httpClient         The HTTP client.
	 * @param PkiOverheidCredentialResolverInterface $credentialResolver Resolves the signing material.
	 * @param SignedSoapProfileInterface             $profile            Signs the request and verifies the answer.
	 * @param SignedEnvelopeRefusals                 $refusals           Builds the client's refusals.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function __construct(
		private readonly Client $httpClient,
		private readonly PkiOverheidCredentialResolverInterface $credentialResolver,
		private readonly SignedSoapProfileInterface $profile,
		private readonly SignedEnvelopeRefusals $refusals,
	) {

	}//end __construct()

	/**
	 * The configuration schema of a signed-envelope source: an endpoint URL and a certificate reference.
	 *
	 * @param string $endpointDescription    What the endpoint is.
	 * @param string $certificateDescription What the certificate reference is.
	 *
	 * @return array The JSON schema.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public static function configSchema(string $endpointDescription, string $certificateDescription): array {
		return [
			'type' => 'object',
			'required' => ['endpoint', 'certificateRef'],
			'properties' => [
				'endpoint' => [
					'type' => 'string',
					'format' => 'uri',
					'description' => $endpointDescription,
				],
				'certificateRef' => [
					'type' => 'string',
					'description' => $certificateDescription,
				],
			],
		];
	}//end configSchema()

	/**
	 * Deliver the envelope and return the reference the endpoint answered.
	 *
	 * @param SignedEnvelopeRequest $request The envelope to deliver.
	 *
	 * @return string The verified reference, or the kenmerk when the answer is empty.
	 *
	 * @throws Exception The client's refusal when signing material, endpoint, transport or status fails.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function deliver(SignedEnvelopeRequest $request): string {
		$certificateRef = (string)($request->sourceConfiguration['certificateRef'] ?? '');
		$this->assertSigningMaterial(certificateRef: $certificateRef);
		$endpoint = $this->requireEndpoint(request: $request);

		// Unreachable today: resolveSigningMaterial() above always throws
		// until OpenRegister's credential broker can issue in-process
		// signing material (see the clients' class docblocks).
		$partnerCertificate = $this->partnerCertificate(request: $request);
		$signedXml = $this->signedRequest(request: $request, certificateRef: $certificateRef, partnerCertificate: $partnerCertificate);
		$response  = $this->post(url: $endpoint . $request->pathSuffix, options: $this->postOptions(request: $request, signedXml: $signedXml));
		$this->assertSuccessful(response: $response);

		return $this->reference(response: $response, fallback: $request->kenmerk, certificateRef: $certificateRef, encrypted: $partnerCertificate !== null);
	}//end deliver()

	/**
	 * The partner certificate to encrypt for, or null when the source has encryption off.
	 *
	 * A source with `encryption` on and no `partnerCertificate` is refused
	 * before anything is sent, never sent unencrypted.
	 *
	 * @param SignedEnvelopeRequest $request The envelope to deliver.
	 *
	 * @return string|null The partner certificate (PEM), or null.
	 *
	 * @throws Exception The client's signing refusal when encryption is on without a certificate.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	private function partnerCertificate(SignedEnvelopeRequest $request): ?string {
		$encryption = $request->sourceConfiguration['encryption'] ?? false;
		if ($encryption !== true && $encryption !== 'true') {
			return null;
		}

		$certificate = trim((string)($request->sourceConfiguration['partnerCertificate'] ?? ''));
		if ($certificate === '') {
			throw $this->refusals->signingRefused(
				cause: new DigikoppelingException(message: 'WS-Security encryption is on but the source has no partner certificate to encrypt for.')
			);
		}

		return $certificate;
	}//end partnerCertificate()

	/**
	 * The signed request, encrypted for the partner when a certificate is given.
	 *
	 * @param SignedEnvelopeRequest $request            The envelope to deliver.
	 * @param string                $certificateRef     The broker credentialRef.
	 * @param string|null           $partnerCertificate The partner certificate, or null for a signed-only request.
	 *
	 * @return string The request body.
	 *
	 * @spec openspec/changes/sources-ws-security-encryption/specs/digikoppeling-adapter/spec.md#requirement-a-soap-source-encrypts-its-body-and-decrypts-the-answer-req-wse-001
	 */
	private function signedRequest(SignedEnvelopeRequest $request, string $certificateRef, ?string $partnerCertificate): string {
		if ($partnerCertificate === null) {
			return $this->profile->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $request->envelopeXml);
		}

		return $this->profile->buildSignedEncryptedRequest(
			certificateRef: $certificateRef,
			stufBodyXml: $request->envelopeXml,
			partnerCertificatePem: $partnerCertificate
		);
	}//end signedRequest()

	/**
	 * The Guzzle options of the POST: the XML content type plus the client's headers, the signed body.
	 *
	 * @param SignedEnvelopeRequest $request   The envelope to deliver.
	 * @param string                $signedXml The signed request.
	 *
	 * @return array The request options.
	 */
	private function postOptions(SignedEnvelopeRequest $request, string $signedXml): array {
		return [
			'headers' => ['Content-Type' => 'application/xml'] + $request->headers,
			'body' => $signedXml,
			'http_errors' => false,
		];
	}//end postOptions()

	/**
	 * Refuse an answer whose status is outside 2xx.
	 *
	 * @param ResponseInterface $response The answer.
	 *
	 * @return void
	 *
	 * @throws Exception The client's status refusal.
	 */
	private function assertSuccessful(ResponseInterface $response): void {
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw $this->refusals->statusRefused(status: $status);
		}
	}//end assertSuccessful()

	/**
	 * Refuse closed when the certificate reference resolves no signing material.
	 *
	 * @param string $certificateRef The broker credentialRef.
	 *
	 * @return void
	 *
	 * @throws Exception The client's signing refusal.
	 */
	private function assertSigningMaterial(string $certificateRef): void {
		try {
			$this->credentialResolver->resolveSigningMaterial(certificateRef: $certificateRef);
		} catch (DigikoppelingException $exception) {
			throw $this->refusals->signingRefused(cause: $exception);
		}
	}//end assertSigningMaterial()

	/**
	 * The configured endpoint without a trailing slash, refused when blank.
	 *
	 * @param SignedEnvelopeRequest $request The envelope to deliver.
	 *
	 * @return string The endpoint.
	 *
	 * @throws Exception The client's endpoint refusal.
	 */
	private function requireEndpoint(SignedEnvelopeRequest $request): string {
		$endpoint = rtrim((string)($request->sourceConfiguration['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw $this->refusals->endpointMissing();
		}

		return $endpoint;
	}//end requireEndpoint()

	/**
	 * POST the signed envelope.
	 *
	 * @param string $url     The full URL.
	 * @param array  $options The Guzzle request options.
	 *
	 * @return ResponseInterface The answer.
	 *
	 * @throws Exception The client's transport refusal.
	 */
	private function post(string $url, array $options): ResponseInterface {
		try {
			return $this->httpClient->request('POST', $url, $options);
		} catch (GuzzleException $exception) {
			throw $this->refusals->transportFailed(cause: $exception);
		}
	}//end post()

	/**
	 * The verified reference in the answer, or the fallback when the answer is empty.
	 *
	 * An answer from a source with encryption on is decrypted with the
	 * organisation's key before its signature is verified.
	 *
	 * @param ResponseInterface $response       The answer.
	 * @param string            $fallback       The caller's kenmerk.
	 * @param string            $certificateRef The broker credentialRef whose key decrypts an encrypted answer.
	 * @param bool              $encrypted      Whether the source has encryption on.
	 *
	 * @return string The reference.
	 */
	private function reference(ResponseInterface $response, string $fallback, string $certificateRef, bool $encrypted): string {
		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $fallback;
		}

		if ($encrypted === true) {
			return $this->profile->decryptAndVerifyResponse(certificateRef: $certificateRef, responseXml: $body);
		}

		return $this->profile->verifyResponse(responseXml: $body);
	}//end reference()
}//end class
