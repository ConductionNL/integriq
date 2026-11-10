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
		$signedXml = $this->profile->buildSignedRequest(certificateRef: $certificateRef, stufBodyXml: $request->envelopeXml);
		$response  = $this->post(url: $endpoint . $request->pathSuffix, options: $this->postOptions(request: $request, signedXml: $signedXml));
		$this->assertSuccessful(response: $response);

		return $this->reference(response: $response, fallback: $request->kenmerk);
	}//end deliver()

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
	 * @param ResponseInterface $response The answer.
	 * @param string            $fallback The caller's kenmerk.
	 *
	 * @return string The reference.
	 */
	private function reference(ResponseInterface $response, string $fallback): string {
		$body = trim((string)$response->getBody());
		if ($body === '') {
			return $fallback;
		}

		return $this->profile->verifyResponse(responseXml: $body);
	}//end reference()
}//end class
