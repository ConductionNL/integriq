<?php

/**
 * Integriq signed-envelope request.
 *
 * One signed XML envelope to deliver: the source configuration that names
 * the endpoint and the certificate reference, the extra headers and path the
 * client adds, the rendered envelope and the caller's kenmerk.
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

/**
 * The values of one signed-envelope delivery.
 *
 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */
final class SignedEnvelopeRequest {

	/**
	 * Constructor.
	 *
	 * @param array                 $sourceConfiguration The source's `configuration` object (endpoint, certificateRef).
	 * @param string                $kenmerk             The caller-supplied correlation id, the fallback reference.
	 * @param string                $envelopeXml         The fully rendered envelope.
	 * @param array<string, string> $headers             Headers sent besides the XML content type.
	 * @param string                $pathSuffix          Appended to the endpoint, '' for none.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function __construct(
		public readonly array $sourceConfiguration,
		public readonly string $kenmerk,
		public readonly string $envelopeXml,
		public readonly array $headers=[],
		public readonly string $pathSuffix='',
	) {

	}//end __construct()
}//end class
