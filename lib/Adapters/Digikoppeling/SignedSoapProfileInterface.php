<?php

/**
 * Contract of the WusProfileService.
 *
 * @category Contract
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
 * Public operations of {@see WusProfileService} that other components call.
 *
 * The method signatures repeat those of WusProfileService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
interface SignedSoapProfileInterface {
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
	public function buildSignedRequest(string $certificateRef, string $stufBodyXml): string;

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
	public function verifyResponse(string $responseXml, ?string $expectedCertificatePem = null): string;
}
