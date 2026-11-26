<?php

/**
 * Integriq IdpAdapterServices.
 *
 * The collaborators every registration adapter is built with, whatever its
 * protocol: the secret seam its credentials are read through, the HTTP client
 * that reaches the identity provider, and the trust table that says which
 * assurance spellings reach the floor. One object, so an adapter's
 * constructor names what is particular to it.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCP\Http\Client\IClientService;

/**
 * The shared collaborators of a registration adapter.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
final class IdpAdapterServices {

	/**
	 * Constructor.
	 *
	 * @param IdpSecretSeam    $secrets Reads a registration's credentials, for one request only.
	 * @param IClientService   $http    Reaches the identity provider.
	 * @param TrustLevelMapper $trust   Says which assurance spellings reach the floor.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function __construct(
		public readonly IdpSecretSeam $secrets,
		public readonly IClientService $http,
		public readonly TrustLevelMapper $trust,
	) {

	}//end __construct()

}//end class
