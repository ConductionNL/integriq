<?php

/**
 * Contract of the InitializeRegister.
 *
 * @category Contract
 * @package  OCA\Integriq\Repair
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

namespace OCA\Integriq\Repair;

use OCP\Migration\IOutput;

/**
 * Public operations of {@see InitializeRegister} that other components call.
 */
interface InitializeRegisterInterface {
	/**
	 * Import integriq_register.json into OpenRegister.
	 *
	 * @param IOutput $output progress/info channel piped to occ stdout
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md
	 */
	public function run(IOutput $output): void;
}
