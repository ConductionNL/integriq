<?php

/**
 * Contract of the RegistryUpdateClient.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

/**
 * Public operations of {@see RegistryUpdateClient} that other components call.
 */
interface RegistryUpdateClientInterface {
	/**
	 * Post one change to OpenRegister.
	 *
	 * @param string $registryId Registry id, which names the endpoint.
	 * @param array<string,mixed> $payload The identity, the changed properties and the event reference.
	 *
	 * @return int The HTTP status, or 0 when the call could not be made.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function postUpdate(string $registryId, array $payload): int;
}
