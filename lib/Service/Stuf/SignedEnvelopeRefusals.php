<?php

/**
 * Integriq signed-envelope refusals.
 *
 * The refusals a client that delivers a signed XML envelope raises in its
 * own words and with its own exception class: signing material that does not
 * resolve, a missing endpoint, a transport failure and a non-2xx answer.
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
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\DigikoppelingException;

/**
 * Builds the exception a signed-envelope delivery throws at each refusal.
 *
 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */
interface SignedEnvelopeRefusals {

	/**
	 * The signing material for the certificate reference did not resolve.
	 *
	 * @param DigikoppelingException $cause The resolver's refusal.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function signingRefused(DigikoppelingException $cause): Exception;

	/**
	 * The source configuration has no endpoint.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function endpointMissing(): Exception;

	/**
	 * The HTTP transport failed; log it as the client does and build the exception.
	 *
	 * @param GuzzleException $cause The transport failure.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function transportFailed(GuzzleException $cause): Exception;

	/**
	 * The endpoint answered with a status outside 2xx.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function statusRefused(int $status): Exception;
}//end interface
