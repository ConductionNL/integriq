<?php

/**
 * Where one S3 object lives: its bucket and its key.
 *
 * The address {@see S3Adapter} writes to, and names in every write failure.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

/**
 * A bucket and key.
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-5
 */
final class S3Location {

	/**
	 * Constructor.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key Object key.
	 */
	public function __construct(
		public readonly string $bucket,
		public readonly string $key,
	) {
	}//end __construct()
}//end class
