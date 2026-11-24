<?php

/**
 * Integriq MirrorApiProductsIntoInventory.
 *
 * The API inventory lists each integriq API product as a row its listener
 * writes when the product is saved. Products saved before that listener
 * existed, and products a register import seeds without an object event, get
 * their row here. Idempotent: a product's row is found by its uuid and written
 * over. Never throws: a failure is reported and the upgrade goes on.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
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
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\ExternalApi\ApiProductInventory;
use OCA\Integriq\Service\SystemWrite;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes the inventory row of every API product.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite::run() is the app's one scoped system-write entry.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */
class MirrorApiProductsIntoInventory implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the inventory service, which needs OpenRegister.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function getName(): string {
		return 'List Integriq API products in the API inventory (gateway-federated-api-discovery)';

	}//end getName()

	/**
	 * Write the rows, as the system principal: a repair runs without a user.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function run(IOutput $output): void {
		try {
			$inventory = $this->container->get(ApiProductInventory::class);
			$written = SystemWrite::run(what: 'listing API products in the API inventory', operation: fn (): int => $inventory->mirrorAll());
		} catch (Throwable $failure) {
			$output->warning('Integriq: API products could not be listed in the API inventory ('.$failure::class.')');
			return;
		}

		$output->info('Integriq: '.$written.' API product(s) listed in the API inventory');

	}//end run()

}//end class
