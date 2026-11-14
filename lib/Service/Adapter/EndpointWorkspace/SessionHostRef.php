<?php

/**
 * Integriq AVD session host reference.
 *
 * Which Azure Virtual Desktop session host to enumerate: the subscription,
 * resource group, host pool and session host names.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\EndpointWorkspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\EndpointWorkspace;

/**
 * The address of one AVD session host.
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-2
 */
final class SessionHostRef {

	/**
	 * Constructor.
	 *
	 * @param string $subscriptionId Azure subscription id.
	 * @param string $resourceGroup  Resource group name.
	 * @param string $hostPool       Host pool name.
	 * @param string $sessionHost    Session host name.
	 */
	public function __construct(
		public readonly string $subscriptionId,
		public readonly string $resourceGroup,
		public readonly string $hostPool,
		public readonly string $sessionHost,
	) {

	}//end __construct()
}//end class
