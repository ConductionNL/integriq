<?php

/**
 * Integriq FSC call run.
 *
 * One downstream call while FscDirectoryClient sends it: where it goes and how.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Fsc
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
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-fscdirectoryclient-routes-through-the-mtls-transport-when-configured
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Fsc;

/**
 * The method, URL, transport and request options of one FSC call. Built by
 * FscDirectoryClient::call() and never handed out.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-fscdirectoryclient-routes-through-the-mtls-transport-when-configured
 */
final class FscCallRun {

	/**
	 * The source's `configuration.directory.authentication` object.
	 *
	 * @var array<string,mixed>
	 */
	public array $authConfig = [];

	/**
	 * Whether `authentication.mode=mtls` is configured.
	 *
	 * @var boolean
	 */
	public bool $useMtls = false;

	/**
	 * The Guzzle request options.
	 *
	 * @var array<string,mixed>
	 */
	public array $requestOptions = [];

	/**
	 * Constructor.
	 *
	 * @param string $method The already-uppercased HTTP method.
	 * @param string $url    The absolute request URL.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
	) {

	}//end __construct()
}//end class
