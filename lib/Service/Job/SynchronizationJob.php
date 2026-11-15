<?php

/**
 * Integriq synchronization job.
 *
 * One SynchronizationAction run once its synchronization is found: the
 * synchronization and the job arguments (force flag, execution trace).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Job;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Run one found synchronization.
 */
final class SynchronizationJob {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $synchronization The synchronization to run.
	 * @param array        $argument        The job arguments.
	 */
	public function __construct(
		public readonly ObjectEntity $synchronization,
		public readonly array $argument,
	) {

	}//end __construct()
}//end class
