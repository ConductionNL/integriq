<?php

/**
 * Integriq brokered call refusals.
 *
 * The refusals a REST provider raises, in its own words and with its own
 * exception class, when a brokered call cannot be made: no credentialRef,
 * a broker configuration error, or any other failure on the way.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use Exception;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use Throwable;

/**
 * Builds the exception a brokered call throws at each refusal.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
interface BrokeredCallRefusals {

	/**
	 * The source names no broker credentialRef (ADR-007).
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
	 */
	public function credentialMissing(): Exception;

	/**
	 * The broker refused the call's configuration.
	 *
	 * @param BrokeredCallConfigurationException $cause The broker's refusal.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
	 */
	public function configurationRefused(BrokeredCallConfigurationException $cause): Exception;

	/**
	 * Anything else failed on the way; log it as the provider does and build the exception.
	 *
	 * @param Throwable $cause The failure.
	 *
	 * @return Exception The exception to throw.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
	 */
	public function transportFailed(Throwable $cause): Exception;
}//end interface
