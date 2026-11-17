<?php

/**
 * OCC command: integriq:job:list.
 *
 * @category Command
 * @package  OCA\Integriq\Command\Integration
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
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

/**
 * Lists every job.
 */
class JobList extends IntegrationListCommand {

	/**
	 * {@inheritDoc}
	 *
	 * @return string The schema.
	 */
	protected function schema(): string {
		return 'job';

	}//end schema()

}//end class
