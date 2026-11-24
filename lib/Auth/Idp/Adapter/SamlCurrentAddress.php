<?php

/**
 * Integriq SamlCurrentAddress.
 *
 * The SAML toolkit compares a Response's Destination and its subject
 * confirmation's Recipient with the address the request arrived on, which it
 * reads from `$_SERVER` and its own static overrides. Behind a reverse proxy
 * that is not the address the identity provider was given, so for the length
 * of a check the address is pinned to our own assertion consumer service and
 * put back afterwards.
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

use OneLogin\Saml2\Utils;

/**
 * Pins the toolkit's idea of the current address, and puts it back.
 *
 * @SuppressWarnings(PHPMD.Superglobals) The toolkit reads the request address from $_SERVER only.
 * @SuppressWarnings(PHPMD.StaticAccess) The toolkit's address overrides are static setters on Utils.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
final class SamlCurrentAddress {

	/**
	 * Point the toolkit's idea of the current address at one address.
	 *
	 * @param string $address The assertion consumer service the Response must name.
	 *
	 * @return callable(): void Puts back what was there before.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function pin(string $address): callable {
		$parts = parse_url($address);
		$scheme = strtolower((string)($parts['scheme'] ?? 'https'));
		$port = ($parts['port'] ?? null);
		if ($port === null) {
			$port = 443;
			if ($scheme === 'http') {
				$port = 80;
			}
		}

		$server = [];
		foreach (['REQUEST_URI', 'QUERY_STRING'] as $key) {
			$server[$key] = ($_SERVER[$key] ?? null);
		}

		$basePath = Utils::getBaseURLPath();

		Utils::setSelfProtocol($scheme);
		Utils::setSelfHost((string)($parts['host'] ?? ''));
		Utils::setSelfPort((int)$port);
		Utils::setBaseURLPath('');
		$_SERVER['REQUEST_URI'] = (string)($parts['path'] ?? '/');
		unset($_SERVER['QUERY_STRING']);

		return static function () use ($server, $basePath): void {
			// The toolkit's defaults: no override, so it reads the request again.
			Utils::setSelfProtocol(false);
			Utils::setSelfHost('');
			Utils::setSelfPort(null);
			Utils::setBaseURLPath((string)$basePath);
			foreach ($server as $key => $value) {
				if ($value === null) {
					unset($_SERVER[$key]);
					continue;
				}

				$_SERVER[$key] = $value;
			}
		};

	}//end pin()

}//end class
