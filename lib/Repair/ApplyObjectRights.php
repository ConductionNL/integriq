<?php

/**
 * Integriq — apply the stored object rights to the configuration schemas again.
 *
 * A register import writes each schema's `authorization` block from the
 * register JSON, which knows nothing of what an administrator set on the
 * action authorization screen. This step runs after InitializeRegister (the
 * import) and InitializeActions (the seed), and writes the stored
 * `object.<schema>.<verb>` rows back onto the schemas, so an upgrade does not
 * reset a delegated right. An untouched matrix writes what the register
 * already declares, so on an instance where nobody changed an object right
 * this step changes nothing.
 *
 * Never throws: it runs under <install> too, where an escaping exception
 * aborts the install. A schema whose update is refused is reported and
 * tried again on the next run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ObjectRightsServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-applies the stored object rights after every register import.
 *
 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
 */
class ApplyObjectRights implements IRepairStep {
	/**
	 * OpenRegister's schema mapper; without OpenRegister there is nothing to apply.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ActionAuthServiceInterface   $actionAuth   Reads the stored matrix.
	 * @param ObjectRightsServiceInterface $objectRights Writes its object rows onto the schemas.
	 * @param LoggerInterface              $logger       The logger.
	 */
	public function __construct(
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly ObjectRightsServiceInterface $objectRights,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
	 */
	public function getName(): string {
		return 'Apply the Integriq object rights to the configuration schemas';
	}//end getName()

	/**
	 * Apply the stored object rights.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\' . self::SCHEMA_MAPPER) === false) {
			$output->info('Integriq: OpenRegister not available; object rights not applied.');
			return;
		}

		try {
			$notApplied = $this->objectRights->apply(matrix: $this->actionAuth->getMatrix());
		} catch (Throwable $e) {
			$output->warning('Integriq: applying the object rights failed: ' . $e->getMessage());
			$this->logger->error('Integriq: ApplyObjectRights failed', ['exception' => $e->getMessage()]);
			return;
		}

		if ($notApplied !== []) {
			$output->warning('Integriq: object rights not applied to: ' . implode(', ', $notApplied) . '; tried again on the next run.');
			return;
		}

		$output->info('Integriq: object rights applied to the configuration schemas.');
	}//end run()
}//end class
